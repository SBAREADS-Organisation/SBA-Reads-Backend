<?php

namespace App\Http\Controllers\Author;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\Paystack\CurrencyConversionService;
use App\Services\Paystack\PaystackTransferService;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AuthorWalletController extends Controller
{
    public function __construct(
        protected StripeConnectService      $stripe,
        protected CurrencyConversionService $fx,
        protected PaystackTransferService   $paystack,
    ) {}

    /**
     * Unified wallet balance endpoint.
     *
     * Returns a consistent shape regardless of payout method so the mobile
     * wallet screen never needs to call different endpoints per author type.
     *
     * Shape:
     * {
     *   payout_method: "stripe"|"paystack"|null,
     *   available: { amount, currency },   // spendable / already-in-bank
     *   pending_iap: { amount, currency }, // Apple IAP earnings awaiting Apple remittance
     *   lifetime_earned: { amount, currency },
     *   stripe_account_id: string|null,
     *   can_withdraw: bool,
     *   withdraw_note: string|null,
     * }
     */
    public function balance(Request $request): JsonResponse
    {
        $author       = $request->user();
        $payoutMethod = $author->payout_method
            ?? ($author->paystack_recipient_code ? 'paystack' : ($author->kyc_account_id ? 'stripe' : null));

        // ── IAP pending — both App Store and Google Play ────────────────────
        $iapPending = (float) Transaction::where('user_id', $author->id)
            ->where('direction', 'credit')
            ->where('status', 'iap_pending')
            ->whereIn('payment_provider', ['apple', 'google_play'])
            ->sum('amount');

        $iapCurrency = 'USD'; // Apple always remits in USD

        // ── Stripe authors: ask Stripe for the live connected-account balance ─
        if ($payoutMethod === 'stripe' && $author->kyc_account_id) {
            try {
                $stripeResp = $this->stripe->retrieveAccountBalance($author->kyc_account_id);
                $body       = json_decode($stripeResp->getContent(), true);

                // retrieveAccountBalance returns { data: { available: {usd: n}, pending: {usd: n} } }
                $availableMap = $body['data']['available'] ?? [];
                $pendingMap   = $body['data']['pending']   ?? [];

                $availableAmount = (float) ($availableMap['usd'] ?? 0);
                $pendingAmount   = (float) ($pendingMap['usd']   ?? 0);
            } catch (\Throwable $e) {
                Log::warning('Wallet: Stripe balance fetch failed for ' . $author->id . ': ' . $e->getMessage());
                $availableAmount = 0.0;
                $pendingAmount   = 0.0;
            }

            return $this->success([
                'payout_method'    => 'stripe',
                'available'        => ['amount' => round($availableAmount + $pendingAmount, 2), 'currency' => 'USD'],
                'pending_iap'      => ['amount' => $iapPending, 'currency' => $iapCurrency],
                'lifetime_earned'  => $this->lifetimeEarned($author->id, 'USD'),
                'stripe_account_id'=> $author->kyc_account_id,
                'can_withdraw'     => $availableAmount > 0,
                'withdraw_note'    => null,
            ], 'Wallet balance retrieved.');
        }

        // ── Paystack authors ────────────────────────────────────────────────────
        if ($payoutMethod === 'paystack') {
            $rate = $this->safeRate('USD', 'NGN');

            // All credited earnings (NGN direct + USD converted)
            $lifetimeNGN = (float) Transaction::where('user_id', $author->id)
                ->where('direction', 'credit')->where('status', 'succeeded')
                ->where('currency', 'NGN')->sum('amount');

            $lifetimeUSD = (float) Transaction::where('user_id', $author->id)
                ->where('direction', 'credit')->where('status', 'succeeded')
                ->whereIn('currency', ['USD', 'usd'])->sum('amount');

            $lifetimeTotal = $lifetimeNGN + ($lifetimeUSD * $rate);

            // Subtract amounts already manually withdrawn via Paystack transfer
            $alreadyPaidOut = (float) Transaction::where('user_id', $author->id)
                ->where('direction', 'debit')
                ->whereIn('status', ['succeeded', 'pending'])
                ->where('payment_provider', 'paystack')
                ->where('purpose_type', 'paystack_payout')
                ->sum('amount');

            $available = max(0.0, $lifetimeTotal - $alreadyPaidOut);

            $hasRecipient = ! empty($author->paystack_recipient_code);
            $canWithdraw  = $available >= 100 && $hasRecipient; // min 100 NGN

            $withdrawNote = ! $hasRecipient
                ? 'Add a Nigerian bank account to withdraw your earnings.'
                : ($available < 100 ? 'Minimum withdrawal is ₦100. Keep selling to build up your balance!' : null);

            return $this->success([
                'payout_method'    => 'paystack',
                'available'        => ['amount' => round($available, 2), 'currency' => 'NGN'],
                'pending_iap'      => ['amount' => round($iapPending * $rate, 2), 'currency' => 'NGN'],
                'lifetime_earned'  => ['amount' => round($lifetimeTotal, 2), 'currency' => 'NGN'],
                'stripe_account_id'=> null,
                'can_withdraw'     => $canWithdraw,
                'withdraw_note'    => $withdrawNote,
            ], 'Wallet balance retrieved.');
        }

        // ── No payout method set yet ─────────────────────────────────────────
        $lifetimeAny = (float) Transaction::where('user_id', $author->id)
            ->where('direction', 'credit')
            ->where('status', 'succeeded')
            ->sum('amount');

        return $this->success([
            'payout_method'    => null,
            'available'        => ['amount' => $lifetimeAny, 'currency' => 'USD'],
            'pending_iap'      => ['amount' => $iapPending, 'currency' => 'USD'],
            'lifetime_earned'  => ['amount' => $lifetimeAny, 'currency' => 'USD'],
            'stripe_account_id'=> null,
            'can_withdraw'     => false,
            'withdraw_note'    => 'Set up a payout method to withdraw your earnings.',
        ], 'Wallet balance retrieved.');
    }

    /**
     * Manually withdraw accumulated NGN earnings via Paystack Transfer.
     *
     * Works for both pre-revamp authors (who have unremitted credit history)
     * and current Paystack authors. USD credits are converted to NGN at the
     * live rate before computing available balance.
     */
    public function paystackWithdraw(Request $request): JsonResponse
    {
        $author = $request->user();

        if (empty($author->paystack_recipient_code)) {
            return $this->error('Please add a Nigerian bank account before withdrawing.', 422);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:100',
        ]);

        $amountNGN = (float) $validated['amount'];
        $rate      = $this->safeRate('USD', 'NGN');

        // Compute available balance (same logic as balance())
        $lifetimeNGN = (float) Transaction::where('user_id', $author->id)
            ->where('direction', 'credit')->where('status', 'succeeded')
            ->where('currency', 'NGN')->sum('amount');

        $lifetimeUSD = (float) Transaction::where('user_id', $author->id)
            ->where('direction', 'credit')->where('status', 'succeeded')
            ->whereIn('currency', ['USD', 'usd'])->sum('amount');

        $lifetimeTotal = $lifetimeNGN + ($lifetimeUSD * $rate);

        $alreadyPaidOut = (float) Transaction::where('user_id', $author->id)
            ->where('direction', 'debit')
            ->whereIn('status', ['succeeded', 'pending'])
            ->where('payment_provider', 'paystack')
            ->where('purpose_type', 'paystack_payout')
            ->sum('amount');

        $available = max(0.0, $lifetimeTotal - $alreadyPaidOut);

        if ($amountNGN > $available) {
            return $this->error(
                'Amount exceeds available balance. Available: ₦' . number_format($available, 2),
                422
            );
        }

        try {
            $amountKobo = (int) round($amountNGN * 100);

            $transfer = $this->paystack->initiateTransfer([
                'source'    => 'balance',
                'amount'    => $amountKobo,
                'recipient' => $author->paystack_recipient_code,
                'reason'    => 'Author royalty withdrawal',
                'currency'  => 'NGN',
            ]);

            if (! ($transfer['status'] ?? false)) {
                throw new \RuntimeException($transfer['message'] ?? 'Paystack transfer failed. Please try again.');
            }

            // Record the payout so future balance() calls subtract it
            Transaction::create([
                'id'               => (string) Str::uuid(),
                'user_id'          => $author->id,
                'reference'        => $transfer['reference'] ?? ('ps_wd_' . uniqid()),
                'status'           => 'pending',
                'currency'         => 'NGN',
                'amount'           => $amountNGN,
                'direction'        => 'debit',
                'payment_provider' => 'paystack',
                'purpose_type'     => 'paystack_payout',
                'description'      => 'Withdrawal to Nigerian bank account',
                'meta_data'        => $transfer,
            ]);

            return $this->success([
                'amount'        => $amountNGN,
                'currency'      => 'NGN',
                'transfer_code' => $transfer['transfer_code'] ?? null,
                'status'        => $transfer['status'] ?? 'pending',
            ], 'Withdrawal initiated. Funds will arrive in your bank account within 1–3 business days.');
        } catch (\Throwable $e) {
            Log::error('Paystack withdrawal failed', ['user_id' => $author->id, 'error' => $e->getMessage()]);
            return $this->error($e->getMessage(), 422);
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function lifetimeEarned(int $userId, string $currency): array
    {
        $amount = (float) Transaction::where('user_id', $userId)
            ->where('direction', 'credit')
            ->where('status', 'succeeded')
            ->sum('amount');

        return ['amount' => $amount, 'currency' => $currency];
    }

    private function safeRate(string $from, string $to): float
    {
        try {
            return $this->fx->getExchangeRate($from, $to);
        } catch (\Throwable) {
            return (float) config('services.currency.ngn_usd_fallback', 1600);
        }
    }
}
