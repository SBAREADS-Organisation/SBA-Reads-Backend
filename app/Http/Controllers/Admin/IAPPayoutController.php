<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Paystack\CurrencyConversionService;
use App\Services\Paystack\PaystackTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Stripe\StripeClient;

class IAPPayoutController extends Controller
{
    public function __construct(
        protected PaystackTransferService   $paystack,
        protected CurrencyConversionService $fx
    ) {}

    /**
     * List all authors with pending IAP earnings (for admin review before paying out).
     */
    public function pending(Request $request): JsonResponse
    {
        // Includes both Apple App Store and Google Play pending earnings
        $rows = Transaction::where('status', 'iap_pending')
            ->where('direction', 'credit')
            ->whereIn('payment_provider', ['apple', 'google_play'])
            ->with('user:id,name,email,payout_method,kyc_account_id,paystack_recipient_code,bank_name')
            ->get()
            ->groupBy('user_id')
            ->map(function ($txns) {
                $author = $txns->first()->user;
                return [
                    'author_id'         => $author?->id,
                    'author_name'       => $author?->name,
                    'author_email'      => $author?->email,
                    'payout_method'     => $author?->payout_method,
                    'bank_name'         => $author?->bank_name,
                    'stripe_connected'  => ! empty($author?->kyc_account_id),
                    'total_pending_usd' => round($txns->sum('amount'), 2),
                    'transaction_count' => $txns->count(),
                    'transactions'      => $txns->pluck('id'),
                ];
            })
            ->values();

        return $this->success([
            'authors'             => $rows,
            'grand_total_usd'     => round($rows->sum('total_pending_usd'), 2),
            'total_authors'       => $rows->count(),
        ], 'Pending IAP payouts retrieved.');
    }

    /**
     * Process all pending IAP earnings for a specific author (or all authors).
     *
     * Call this AFTER Apple has remitted funds to your bank account.
     * Admin triggers this from the dashboard — never automatic.
     *
     * POST /admin/iap-payouts/process          → process ALL pending authors
     * POST /admin/iap-payouts/process/{author} → process one author only
     */
    public function process(Request $request, ?User $author = null): JsonResponse
    {
        $query = Transaction::where('status', 'iap_pending')
            ->where('direction', 'credit')
            ->whereIn('payment_provider', ['apple', 'google_play']);

        if ($author) {
            $query->where('user_id', $author->id);
        }

        $pending = $query->with('user')->get()->groupBy('user_id');

        $results = ['succeeded' => [], 'failed' => [], 'skipped' => []];

        foreach ($pending as $authorId => $txns) {
            $authorModel  = $txns->first()->user;
            $totalUSD     = (float) $txns->sum('amount');
            $payoutMethod = $authorModel->payout_method
                ?? ($authorModel->paystack_recipient_code ? 'paystack' : ($authorModel->kyc_account_id ? 'stripe' : null));

            if (! $payoutMethod) {
                $results['skipped'][] = ['author_id' => $authorId, 'reason' => 'No payout method set'];
                continue;
            }

            try {
                $transferRef = 'iap_batch_' . now()->format('Ym') . '_' . $authorId;

                if ($payoutMethod === 'paystack') {
                    if (empty($authorModel->paystack_recipient_code)) {
                        $results['skipped'][] = ['author_id' => $authorId, 'reason' => 'No Paystack recipient code'];
                        continue;
                    }

                    // Use the weighted-average of the rate locked at sale time across all transactions.
                    // This means each author is paid at the rate that was locked when their book was sold,
                    // not the current market rate — protecting them from rate fluctuation.
                    $totalNGN = 0;
                    foreach ($txns as $txn) {
                        $meta        = is_array($txn->meta_data) ? $txn->meta_data : json_decode($txn->meta_data ?? '{}', true);
                        $lockedRate  = (float) ($meta['ngn_rate_at_sale'] ?? $this->safeRate('USD', 'NGN'));
                        $totalNGN   += (float) $txn->amount * $lockedRate;
                    }
                    $amountNGN   = (int) round($totalNGN * 100); // kobo
                    $rateUsed    = $totalUSD > 0 ? $totalNGN / $totalUSD : $this->safeRate('USD', 'NGN');

                    $transfer = $this->paystack->initiateTransfer(
                        $amountNGN,
                        $authorModel->paystack_recipient_code,
                        "SBAReads App Store royalty batch — {$txns->count()} sales"
                    );

                    $txns->each(fn ($t) => $t->update([
                        'status'      => 'succeeded',
                        'payout_data' => json_encode([
                            'transfer_code' => $transfer['transfer_code'] ?? null,
                            'amount_usd'    => $totalUSD,
                            'amount_ngn'    => $totalNGN,
                            'effective_rate'=> round($rateUsed, 2),
                            'rate_method'   => 'locked_at_sale',
                            'batch_ref'     => $transferRef,
                        ]),
                    ]));

                    $results['succeeded'][] = ['author_id' => $authorId, 'method' => 'paystack', 'amount_usd' => $totalUSD, 'amount_ngn' => $totalNGN];

                } else {
                    // Stripe Connect Transfer
                    if (empty($authorModel->kyc_account_id)) {
                        $results['skipped'][] = ['author_id' => $authorId, 'reason' => 'No Stripe account'];
                        continue;
                    }

                    $stripe   = new StripeClient(config('services.stripe.secret'));
                    $amountCents = (int) round($totalUSD * 100);

                    $transfer = $stripe->transfers->create([
                        'amount'      => $amountCents,
                        'currency'    => 'usd',
                        'destination' => $authorModel->kyc_account_id,
                        'metadata'    => [
                            'type'             => 'iap_batch_payout',
                            'author_id'        => $authorId,
                            'transaction_count'=> $txns->count(),
                            'batch_ref'        => $transferRef,
                        ],
                    ]);

                    $txns->each(fn ($t) => $t->update([
                        'status'            => 'succeeded',
                        'payment_intent_id' => $transfer->id,
                        'payout_data'       => json_encode([
                            'transfer_id' => $transfer->id,
                            'amount_usd'  => $totalUSD,
                            'batch_ref'   => $transferRef,
                        ]),
                    ]));

                    $results['succeeded'][] = ['author_id' => $authorId, 'method' => 'stripe', 'amount_usd' => $totalUSD, 'transfer_id' => $transfer->id];
                }

                Log::info("IAP batch payout succeeded for author {$authorId}", [
                    'method' => $payoutMethod, 'amount_usd' => $totalUSD,
                ]);

            } catch (\Throwable $e) {
                Log::error("IAP batch payout failed for author {$authorId}: " . $e->getMessage());
                $results['failed'][] = ['author_id' => $authorId, 'error' => $e->getMessage()];
            }
        }

        return $this->success($results, 'IAP payout batch processed.');
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
