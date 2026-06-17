<?php

namespace App\Http\Controllers\Author;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\Paystack\CurrencyConversionService;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AuthorWalletController extends Controller
{
    public function __construct(
        protected StripeConnectService   $stripe,
        protected CurrencyConversionService $fx
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

        // ── Paystack authors: earnings go straight to bank on each purchase ──
        // We don't hold a balance — compute lifetime credits from transactions.
        if ($payoutMethod === 'paystack') {
            $lifetimeNGN = (float) Transaction::where('user_id', $author->id)
                ->where('direction', 'credit')
                ->where('status', 'succeeded')
                ->where('currency', 'NGN')
                ->sum('amount');

            // USD credits converted to NGN (USD purchases routed through Paystack)
            $lifetimeUSD = (float) Transaction::where('user_id', $author->id)
                ->where('direction', 'credit')
                ->where('status', 'succeeded')
                ->whereIn('currency', ['USD', 'usd'])
                ->sum('amount');

            $rate           = $lifetimeUSD > 0 ? $this->safeRate('USD', 'NGN') : 1;
            $lifetimeTotal  = $lifetimeNGN + ($lifetimeUSD * $rate);

            return $this->success([
                'payout_method'    => 'paystack',
                'available'        => ['amount' => $lifetimeTotal, 'currency' => 'NGN'],
                'pending_iap'      => ['amount' => $iapPending * $this->safeRate('USD', 'NGN'), 'currency' => 'NGN'],
                'lifetime_earned'  => ['amount' => $lifetimeTotal, 'currency' => 'NGN'],
                'stripe_account_id'=> null,
                // Paystack pushes to bank immediately — nothing to "withdraw"
                'can_withdraw'     => false,
                'withdraw_note'    => 'Your earnings are automatically sent to your registered bank account after each sale. No manual withdrawal needed.',
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
