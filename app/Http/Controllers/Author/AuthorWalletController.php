<?php

namespace App\Http\Controllers\Author;

use App\Http\Controllers\Controller;
use App\Models\PayoutRequest;
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
            $balanceCurrency = 'USD'; // default if Stripe API is unreachable
            try {
                $stripeResp = $this->stripe->retrieveAccountBalance($author->kyc_account_id);
                $body       = json_decode($stripeResp->getContent(), true);

                // retrieveAccountBalance returns { data: { available: [{currency,amount},...], pending: [...] } }
                // Prefer USD; fall back to the first non-zero entry for non-USD accounts (GB, AU, EU, etc.)
                $pickBalance = function (array $list): array {
                    $col = collect($list);
                    $usd = $col->firstWhere('currency', 'usd');
                    if ($usd) return ['amount' => (float) $usd['amount'], 'currency' => 'USD'];
                    $first = $col->first();
                    return $first
                        ? ['amount' => (float) $first['amount'], 'currency' => strtoupper($first['currency'])]
                        : ['amount' => 0.0, 'currency' => 'USD'];
                };

                $availableMap = $body['data']['available'] ?? [];
                $pendingMap   = $body['data']['pending']   ?? [];

                $availableBalance = $pickBalance($availableMap);
                $pendingBalance   = $pickBalance($pendingMap);
                $availableAmount  = $availableBalance['amount'];
                $balanceCurrency  = $availableBalance['currency'];
                $pendingAmount    = $pendingBalance['amount'];
            } catch (\Throwable $e) {
                Log::warning('Wallet: Stripe balance fetch failed for ' . $author->id . ': ' . $e->getMessage());
                $availableAmount = 0.0;
                $pendingAmount   = 0.0;
            }

            $symbol = $balanceCurrency === 'USD' ? '$' : $balanceCurrency . ' ';
            $withdrawNote = $pendingAmount > 0 && $availableAmount === 0.0
                ? 'Your earnings of ' . $symbol . number_format($pendingAmount, 2) . ' are still pending settlement by Stripe (usually 2–7 days after a sale).'
                : ($pendingAmount > 0
                    ? $symbol . number_format($pendingAmount, 2) . ' more is pending settlement and will be available soon.'
                    : null);

            return $this->success([
                'payout_method'    => 'stripe',
                'available'        => ['amount' => round($availableAmount, 2), 'currency' => $balanceCurrency],
                'pending_stripe'   => ['amount' => round($pendingAmount, 2),   'currency' => $balanceCurrency],
                'pending_iap'      => ['amount' => $iapPending, 'currency' => $iapCurrency],
                'lifetime_earned'  => $this->lifetimeEarned($author->id, $balanceCurrency),
                'stripe_account_id'=> $author->kyc_account_id,
                'can_withdraw'     => $availableAmount > 0,
                'withdraw_note'    => $withdrawNote,
            ], 'Wallet balance retrieved.');
        }

        // ── Paystack authors ────────────────────────────────────────────────────
        if ($payoutMethod === 'paystack') {
            $rate = $this->safeRate('USD', 'NGN');

            // NGN earnings — directly withdrawable via Paystack (funds are in the platform's Paystack account)
            $lifetimeNGN = (float) Transaction::where('user_id', $author->id)
                ->where('direction', 'credit')->where('status', 'succeeded')
                ->where('currency', 'NGN')->sum('amount');

            // USD earnings from Stripe card purchases only — IAP earnings are excluded because
            // they are processed separately via IAPPayoutController and converted to NGN via Paystack.
            // Including them here would allow an author to request the same money twice.
            $lifetimeUSD = (float) Transaction::where('user_id', $author->id)
                ->where('direction', 'credit')->where('status', 'succeeded')
                ->whereIn('currency', ['USD', 'usd'])
                ->whereNotIn('payment_provider', ['apple', 'google_play'])
                ->sum('amount');

            $lifetimeUSDinNGN = round($lifetimeUSD * $rate, 2);

            // Already paid out: manual withdrawals (debit) + auto-payouts already sent by the job (credit).
            // Both reduce what the author can still manually withdraw — without this, auto-payout credits
            // would remain in "available" forever and allow a second manual withdrawal of the same funds.
            $alreadyPaidOut = (float) Transaction::where('user_id', $author->id)
                ->where('currency', 'NGN')
                ->whereIn('status', ['succeeded', 'pending'])
                ->where('payment_provider', 'paystack')
                ->where(function ($q) {
                    $q->where(function ($inner) {
                        // Manual author-initiated withdrawals
                        $inner->where('direction', 'debit')
                              ->where('purpose_type', 'paystack_payout');
                    })->orWhere(function ($inner) {
                        // Auto-payouts already sent by ProcessAuthorPayout job
                        $inner->where('direction', 'credit')
                              ->whereIn('purpose_type', ['order', 'digital_book_purchase']);
                    });
                })
                ->sum('amount');

            // Only NGN is withdrawable via Paystack — USD sits in the Stripe connected account
            $available = max(0.0, $lifetimeNGN - $alreadyPaidOut);

            $hasRecipient = ! empty($author->paystack_recipient_code);
            $canWithdraw  = $available >= 100 && $hasRecipient;

            $withdrawNote = ! $hasRecipient
                ? 'Add a Nigerian bank account to withdraw your NGN earnings.'
                : ($available < 100 ? 'Minimum withdrawal is ₦100. Keep selling to build up your balance!' : null);

            // USD earnings available for a manual payout request (for authors with no Stripe account).
            // Subtract amounts already covered by pending/processing/completed requests.
            $handledUSD     = (float) PayoutRequest::where('user_id', $author->id)
                ->whereIn('status', ['pending', 'processing', 'completed'])
                ->sum('amount');
            $usdAvailableForRequest = max(0.0, $lifetimeUSD - $handledUSD);
            $pendingRequestUSD      = (float) PayoutRequest::where('user_id', $author->id)
                ->whereIn('status', ['pending', 'processing'])
                ->sum('amount');

            // When the author also has a Stripe connected account, fetch its live USD balance
            // so the frontend can show a second USD wallet card with a direct withdraw option.
            $stripeWallet = null;
            if (! empty($author->kyc_account_id)) {
                try {
                    $stripeResp  = $this->stripe->retrieveAccountBalance($author->kyc_account_id);
                    $stripeBody  = json_decode($stripeResp->getContent(), true);
                    $stripeAvail = collect($stripeBody['data']['available'] ?? []);
                    $stripePend  = collect($stripeBody['data']['pending']   ?? []);
                    $usdAvail    = (float) ($stripeAvail->firstWhere('currency', 'usd')['amount'] ?? 0);
                    $usdPend     = (float) ($stripePend->firstWhere('currency', 'usd')['amount']  ?? 0);

                    // Only surface the card when there is something to show
                    if ($usdAvail > 0 || $usdPend > 0) {
                        $stripeWallet = [
                            'available'    => ['amount' => round($usdAvail, 2), 'currency' => 'USD'],
                            'pending'      => ['amount' => round($usdPend, 2),  'currency' => 'USD'],
                            'can_withdraw' => $usdAvail > 0,
                            'account_id'   => $author->kyc_account_id,
                        ];
                    }
                } catch (\Throwable $e) {
                    Log::warning('Wallet: Stripe dual-wallet balance fetch failed for user ' . $author->id . ': ' . $e->getMessage());
                }
            }

            return $this->success([
                'payout_method'        => 'paystack',
                'available'            => ['amount' => round($available, 2), 'currency' => 'NGN'],
                'pending_iap'          => ['amount' => round($iapPending * $rate, 2), 'currency' => 'NGN'],
                'lifetime_earned'      => ['amount' => round($lifetimeNGN + $lifetimeUSDinNGN, 2), 'currency' => 'NGN'],
                'pending_usd_in_ngn'   => $lifetimeUSDinNGN,
                'stripe_account_id'    => null,
                'stripe_wallet'        => $stripeWallet,
                'can_withdraw'         => $canWithdraw,
                'withdraw_note'        => $withdrawNote,
                // USD earnings summary for authors without a Stripe connected account,
                // enabling them to submit a manual payout request via the app.
                'usd_earnings'         => $lifetimeUSD > 0 ? [
                    'lifetime'        => round($lifetimeUSD, 2),
                    'available'       => round($usdAvailableForRequest, 2),
                    'pending_request' => round($pendingRequestUSD, 2),
                    'currency'        => 'USD',
                ] : null,
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

        // Only NGN credits are withdrawable via Paystack (USD from Stripe is not in the Paystack account)
        $lifetimeNGN = (float) Transaction::where('user_id', $author->id)
            ->where('direction', 'credit')->where('status', 'succeeded')
            ->where('currency', 'NGN')->sum('amount');

        $alreadyPaidOut = (float) Transaction::where('user_id', $author->id)
            ->where('currency', 'NGN')
            ->whereIn('status', ['succeeded', 'pending'])
            ->where('payment_provider', 'paystack')
            ->where(function ($q) {
                $q->where(function ($inner) {
                    $inner->where('direction', 'debit')
                          ->where('purpose_type', 'paystack_payout');
                })->orWhere(function ($inner) {
                    $inner->where('direction', 'credit')
                          ->whereIn('purpose_type', ['order', 'digital_book_purchase']);
                });
            })
            ->sum('amount');

        $available = max(0.0, $lifetimeNGN - $alreadyPaidOut);

        if ($amountNGN > $available) {
            // Check if the gap is due to USD earnings pending admin processing
            // Exclude IAP earnings — those are already handled separately.
            $rate        = $this->safeRate('USD', 'NGN');
            $lifetimeUSD = (float) Transaction::where('user_id', $author->id)
                ->where('direction', 'credit')->where('status', 'succeeded')
                ->whereIn('currency', ['USD', 'usd'])
                ->whereNotIn('payment_provider', ['apple', 'google_play'])
                ->sum('amount');

            if ($lifetimeUSD > 0 && $available < $amountNGN) {
                $usdInNGN = number_format(round($lifetimeUSD * $rate, 2), 2);
                return $this->error(
                    "You have ₦{$usdInNGN} in international earnings that are being processed. " .
                    'Your NGN withdrawable balance is ₦' . number_format($available, 2) . '. ' .
                    'Contact support@sbareads.com if you need urgent access to your earnings.',
                    422
                );
            }

            return $this->error(
                'Amount exceeds available balance. Available: ₦' . number_format($available, 2),
                422
            );
        }

        try {
            $amountKobo = (int) round($amountNGN * 100);

            $transfer = $this->paystack->initiateTransfer(
                $amountKobo,
                $author->paystack_recipient_code,
                'Author royalty withdrawal'
            );

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

            $message = $e->getMessage();
            if (stripos($message, 'balance') !== false && stripos($message, 'not enough') !== false) {
                $message = 'Withdrawal could not be processed at this time. Please contact support@sbareads.com and we will process your payment manually.';
            }

            return $this->error($message, 422);
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
