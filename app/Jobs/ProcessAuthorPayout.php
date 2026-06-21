<?php

namespace App\Jobs;

use App\Mail\Generic\GenericAppNotification;
use App\Models\DigitalBookPurchase;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payments\PaymentService;
use App\Services\Paystack\PaystackTransferService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

class ProcessAuthorPayout implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $purpose; // Purpose of the transaction (e.g., 'order', 'digital_book_purchase')
    protected int $purposeId;

    protected StripeClient $stripe;

    /**
     * Create a new job instance.
     *
     * @param string $purpose Purpose of the transaction (e.g., 'order', 'digital_book_purchase')
     * @param int $purposeId ID of the purpose (e.g., DigitalBookPurchase ID)
     * @return void
     */
    public function __construct(string $purpose, int $purposeId)
    {
        $this->purpose = $purpose;
        $this->purposeId = $purposeId;
        $this->stripe = new StripeClient(config('services.stripe.secret')); // Initialize Stripe client
    }

    /**
     * Execute the job.
     *
     * @param PaymentService $paymentService // Inject PaymentService to use convertToSubunit
     */
    public function handle(PaymentService $paymentService): void
    {
        switch ($this->purpose) {
            case 'digital_book_purchase':
                $this->processDigitalBookPurchasePayout($paymentService);
                break;

            case 'order':
                $this->processOrderPayout($paymentService);
                break;

            default:
        }
    }

    private function notifyPayoutFailed(User $author, float $amount, string $currency, string $reason): void
    {
        if (! $author->email) return;
        try {
            Mail::to($author->email)->queue(new GenericAppNotification(
                'SBA Reads — Payout Failed',
                "Hi {$author->first_name},\n\n"
                . "We were unable to send your payout of {$currency} {$amount}.\n\n"
                . "Reason: {$reason}\n\n"
                . "Please check your payout account details in the SBA Reads app under Wallet → Payout Method and ensure they are correct.\n\n"
                . "If the issue persists, contact support@sbareads.com.\n\n"
                . "— The SBA Reads Team"
            ));
        } catch (\Throwable $e) {
            Log::warning("Could not send payout failure email to author {$author->id}: " . $e->getMessage());
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        switch ($this->purpose) {
            case 'digital_book_purchase':
                $purchase = DigitalBookPurchase::where('id', $this->purposeId)->first();
                if ($purchase) {
                    $purchase->update(['status' => 'payout_failed']);
                    // Mark all pending purchase items as failed
                    $purchase->items()->where('payout_status', 'pending')->update(['payout_status' => 'failed', 'payout_error' => 'Job failed: ' . $exception->getMessage()]);
                }
                break;

            case 'order':
                $order = Order::where('id', $this->purposeId)->first();
                if ($order) {
                    $order->update(['payout_status' => 'failed']);
                    // Mark all pending order items as failed
                    $order->items()->where('payout_status', 'pending')->update(['payout_status' => 'failed', 'payout_error' => 'Job failed: ' . $exception->getMessage()]);
                }
                break;
        }
    }

    private function processDigitalBookPurchasePayout(PaymentService $paymentService): void
    {

        // 1. Retrieve the DigitalBookPurchase and its items
        $purchase = DigitalBookPurchase::where('id', $this->purposeId)
            ->with(['items.book.author']) // Eager load relationships
            ->where('status', 'paid') // Ensure purchase is marked as paid
            ->first();

        if (!$purchase) {

            return;
        }

        // Check if payouts for this purchase have already been initiated/completed
        // This is crucial for idempotency at the purchase level
        $payoutsAlreadyInitiated = $purchase->items->every(function ($item) {
            return in_array($item->payout_status, ['initiated', 'completed', 'failed']);
        });

        if ($payoutsAlreadyInitiated) {

            return;
        }

        // Aggregate payouts by author
        $authorPayouts = []; // [author_user_id => total_payout_amount_cents]

        foreach ($purchase->items as $item) {
            if (in_array($item->payout_status, ['initiated', 'completed', 'failed'])) {
                continue;
            }

            $author       = $item->author;
            $payoutMethod = $author->payout_method ?? ($author->paystack_recipient_code ? 'paystack' : ($author->kyc_account_id ? 'stripe' : null));

            if (! $item->book || ! $author || ! $payoutMethod) {
                $item->update(['payout_status' => 'failed', 'payout_error' => 'Author has not set up a payout method (Stripe or Nigerian bank account).']);
                continue;
            }

            $itemPriceInCents     = $paymentService->convertToSubunit($item->price_at_purchase, $purchase->currency);
            $itemTotalAmountCents = $itemPriceInCents * $item->quantity;
            $authorItemPayoutCents = (int) round($itemTotalAmountCents * 0.75);
            $platformItemFeeCents  = $itemTotalAmountCents - $authorItemPayoutCents;

            $authorPayouts[$author->id] = ($authorPayouts[$author->id] ?? 0) + $authorItemPayoutCents;

            $item->author_payout_amount = $authorItemPayoutCents / 100;
            $item->platform_fee_amount  = $platformItemFeeCents / 100;
            $item->payout_status        = 'initiated';
            $item->save();
        }

        $totalPlatformFeeCollectedCents = 0;
        $allTransfersSuccessful         = true;
        $paystackSvc                    = new PaystackTransferService();

        foreach ($authorPayouts as $authorUserId => $payoutAmountCents) {
            $author       = $purchase->items->firstWhere('author_id', $authorUserId)->author;
            $payoutMethod = $author->payout_method ?? ($author->paystack_recipient_code ? 'paystack' : 'stripe');

            if ($payoutAmountCents <= 0) continue;

            // Validate the author has the right credentials for their chosen method
            if ($payoutMethod === 'paystack' && empty($author->paystack_recipient_code)) {
                foreach ($purchase->items->where('author_id', $authorUserId) as $item) {
                    if ($item->payout_status === 'initiated') {
                        $item->update(['payout_status' => 'failed', 'payout_error' => 'Nigerian bank account not registered. Go to Settings → Payout to add your bank account.']);
                    }
                }
                $allTransfersSuccessful = false;
                Log::warning("Payout skipped — author {$authorUserId} selected Paystack but has no recipient code.");
                continue;
            }

            if ($payoutMethod === 'stripe' && empty($author->kyc_account_id)) {
                foreach ($purchase->items->where('author_id', $authorUserId) as $item) {
                    if ($item->payout_status === 'initiated') {
                        $item->update(['payout_status' => 'failed', 'payout_error' => 'Stripe account not connected. Complete Stripe onboarding in Settings → Payout.']);
                    }
                }
                $allTransfersSuccessful = false;
                continue;
            }

            // For Paystack payouts convert USD → NGN if the purchase was in USD
            $payoutCurrency       = $payoutMethod === 'paystack' ? 'NGN' : strtoupper($purchase->currency);
            $payoutAmountInTarget = $payoutAmountCents;
            if ($payoutMethod === 'paystack' && strtoupper($purchase->currency) !== 'NGN') {
                $rate                 = app(\App\Services\Paystack\CurrencyConversionService::class)->getExchangeRate('USD', 'NGN');
                $payoutAmountInTarget = (int) round(($payoutAmountCents / 100) * $rate * 100); // convert to kobo
            }

            $transaction = Transaction::create([
                'id'               => Str::uuid(),
                'user_id'          => $author->id,
                'reference'        => uniqid('pay_'),
                'status'           => 'pending',
                'currency'         => $payoutCurrency,
                'amount'           => $payoutAmountInTarget / 100,
                'payment_provider' => $payoutMethod,
                'description'      => "Author payout for DigitalBookPurchase ID: {$purchase->id}",
                'type'             => 'payout',
                'direction'        => 'credit',
                'purpose_type'     => 'digital_book_purchase',
                'purpose_id'       => $purchase->id,
            ]);

            try {
                if ($payoutMethod === 'paystack') {
                    // ── Paystack Transfer ────────────────────────────────────────
                    $transferData = $paystackSvc->initiateTransfer([
                        'source'    => 'balance',
                        'amount'    => $payoutAmountInTarget,
                        'recipient' => $author->paystack_recipient_code,
                        'reason'    => "SBAReads royalty — purchase #{$purchase->id}",
                        'currency'  => 'NGN',
                    ]);

                    $transaction->update([
                        'status'      => 'succeeded',
                        'payout_data' => json_encode([
                            'transfer_code' => $transferData['transfer_code'] ?? null,
                            'amount'        => $payoutAmountInTarget / 100,
                            'currency'      => 'NGN',
                            'recipient'     => $author->paystack_recipient_code,
                        ]),
                    ]);
                } else {
                    // ── Stripe Transfer ──────────────────────────────────────────
                    // Idempotency key prevents double-payout if the queue retries this job
                    $idempotencyKey = 'dbp_' . $purchase->id . '_author_' . $author->id;
                    $transfer = $this->stripe->transfers->create([
                        'amount'         => $payoutAmountCents,
                        'currency'       => $purchase->currency,
                        'destination'    => $author->kyc_account_id,
                        'transfer_group' => 'purchase_' . $purchase->id,
                        'metadata'       => [
                            'digital_book_purchase_id' => $purchase->id,
                            'author_user_id'           => $author->id,
                            'type'                     => 'author_royalty_batch',
                        ],
                    ], ['idempotency_key' => $idempotencyKey]);

                    $transaction->update([
                        'status'            => 'succeeded',
                        'payment_intent_id' => $transfer->id,
                        'payout_data'       => json_encode([
                            'transfer_id' => $transfer->id,
                            'amount'      => $payoutAmountCents / 100,
                            'currency'    => $purchase->currency,
                            'destination' => $author->kyc_account_id,
                        ]),
                    ]);
                }

                $author->increment('wallet_balance', $payoutAmountCents / 100);

                foreach ($purchase->items->where('author_id', $authorUserId) as $item) {
                    if ($item->payout_status === 'initiated') {
                        $item->update(['payout_status' => 'completed']);
                    }
                }

                $totalPlatformFeeCollectedCents += $purchase->items
                    ->where('author_id', $authorUserId)
                    ->sum(fn ($item) => $paymentService->convertToSubunit($item->platform_fee_amount, $purchase->currency));

            } catch (ApiErrorException $e) {
                $allTransfersSuccessful = false;
                foreach ($purchase->items->where('author_id', $authorUserId) as $item) {
                    if ($item->payout_status === 'initiated') {
                        $item->update(['payout_status' => 'failed', 'payout_error' => $e->getMessage()]);
                    }
                }
                $transaction->update([
                    'status'      => 'failed',
                    'payout_data' => json_encode(['error' => $e->getMessage()]),
                ]);
                $this->notifyPayoutFailed($author, $payoutAmountCents / 100, strtoupper($payoutCurrency), $e->getMessage());
            } catch (\Throwable $e) {
                $allTransfersSuccessful = false;
                foreach ($purchase->items->where('author_id', $authorUserId) as $item) {
                    if ($item->payout_status === 'initiated') {
                        $item->update(['payout_status' => 'failed', 'payout_error' => $e->getMessage()]);
                    }
                }
                $transaction->update([
                    'status'      => 'failed',
                    'payout_data' => json_encode(['error' => $e->getMessage()]),
                ]);
                $this->notifyPayoutFailed($author, $payoutAmountCents / 100, strtoupper($payoutCurrency), $e->getMessage());
            }
        }

        // Update the main DigitalBookPurchase status based on overall payout success
        if ($allTransfersSuccessful) {
            $purchase->update([
                'status' => 'payout_completed',
                'platform_fee_amount' => $totalPlatformFeeCollectedCents / 100, // Store total in major unit
            ]);
        } else {
            $purchase->update([
                'status' => 'payout_failed', // Or 'payout_partially_completed' if you track that
                'platform_fee_amount' => $totalPlatformFeeCollectedCents / 100,
            ]);
        }
    }

    private function processOrderPayout(PaymentService $paymentService): void
    {

        // 1. Retrieve the DigitalBookPurchase and its items
        $order = Order::where('id', $this->purposeId)
            ->with(['items.book.author']) // Eager load relationships
            ->where('payout_status', 'pending') // Ensure payout is pending
            ->first();

        if (!$order) {

            return;
        }

        // Check if payouts for this purchase have already been initiated/completed
        // This is crucial for idempotency at the purchase level
        $payoutsAlreadyInitiated = $order->items->every(function ($item) {
            return in_array($item->payout_status, ['initiated', 'completed', 'failed']);
        });

        if ($payoutsAlreadyInitiated) {

            return;
        }

        // Aggregate payouts by author
        $authorPayouts = []; // [author_user_id => total_payout_amount_cents]

        foreach ($order->items as $item) {
            if (in_array($item->payout_status, ['initiated', 'completed', 'failed'])) {
                continue;
            }

            $author       = $item->author;
            $payoutMethod = $author->payout_method ?? ($author->paystack_recipient_code ? 'paystack' : ($author->kyc_account_id ? 'stripe' : null));

            if (! $item->book || ! $author || ! $payoutMethod) {
                $item->update(['payout_status' => 'failed', 'payout_error' => 'Author has not set up a payout method.']);
                continue;
            }

            $itemTotalAmountCents  = $paymentService->convertToSubunit($item->total_price, 'USD');
            $authorItemPayoutCents = (int) round($itemTotalAmountCents * 0.75);
            $platformItemFeeCents  = $itemTotalAmountCents - $authorItemPayoutCents;

            $authorPayouts[$author->id] = ($authorPayouts[$author->id] ?? 0) + $authorItemPayoutCents;

            $item->author_payout_amount = $authorItemPayoutCents / 100;
            $item->platform_fee_amount  = $platformItemFeeCents / 100;
            $item->payout_status        = 'initiated';
            $item->save();
        }

        $totalPlatformFeeCollectedCents = 0;
        $allTransfersSuccessful         = true;
        $paystackSvc                    = new PaystackTransferService();

        foreach ($authorPayouts as $authorUserId => $payoutAmountCents) {
            $author       = $order->items->firstWhere('author_id', $authorUserId)->author;
            $payoutMethod = $author->payout_method ?? ($author->paystack_recipient_code ? 'paystack' : 'stripe');

            if ($payoutAmountCents <= 0) continue;

            if ($payoutMethod === 'paystack' && empty($author->paystack_recipient_code)) {
                foreach ($order->items->where('author_id', $authorUserId) as $item) {
                    if ($item->payout_status === 'initiated') {
                        $item->update(['payout_status' => 'failed', 'payout_error' => 'Nigerian bank account not registered.']);
                    }
                }
                $allTransfersSuccessful = false;
                continue;
            }

            if ($payoutMethod === 'stripe' && empty($author->kyc_account_id)) {
                foreach ($order->items->where('author_id', $authorUserId) as $item) {
                    if ($item->payout_status === 'initiated') {
                        $item->update(['payout_status' => 'failed', 'payout_error' => 'Stripe account not connected.']);
                    }
                }
                $allTransfersSuccessful = false;
                continue;
            }

            // Convert USD → NGN for Paystack payouts
            $payoutCurrency       = $payoutMethod === 'paystack' ? 'NGN' : 'USD';
            $payoutAmountInTarget = $payoutAmountCents;
            if ($payoutMethod === 'paystack') {
                $rate                 = app(\App\Services\Paystack\CurrencyConversionService::class)->getExchangeRate('USD', 'NGN');
                $payoutAmountInTarget = (int) round(($payoutAmountCents / 100) * $rate * 100);
            }

            $transaction = Transaction::create([
                'id'               => Str::uuid(),
                'user_id'          => $author->id,
                'reference'        => uniqid('pay_'),
                'status'           => 'pending',
                'currency'         => $payoutCurrency,
                'amount'           => $payoutAmountInTarget / 100,
                'payment_provider' => $payoutMethod,
                'description'      => "Author payout for Order ID: {$order->id}",
                'type'             => 'payout',
                'direction'        => 'credit',
                'purpose_type'     => 'order',
                'purpose_id'       => $order->id,
            ]);

            try {
                if ($payoutMethod === 'paystack') {
                    $transferData = $paystackSvc->initiateTransfer(
                        $payoutAmountInTarget,
                        $author->paystack_recipient_code,
                        "SBAReads royalty — order #{$order->id}"
                    );

                    $transaction->update([
                        'status'      => 'succeeded',
                        'payout_data' => json_encode([
                            'transfer_code' => $transferData['transfer_code'] ?? null,
                            'amount'        => $payoutAmountInTarget / 100,
                            'currency'      => 'NGN',
                            'recipient'     => $author->paystack_recipient_code,
                        ]),
                    ]);

                    foreach ($order->items->where('author_id', $authorUserId) as $item) {
                        if ($item->payout_status === 'initiated') {
                            $item->update(['payout_status' => 'completed']);
                        }
                    }
                } else {
                    $idempotencyKey = 'order_' . $order->id . '_author_' . $author->id;
                    $transfer = $this->stripe->transfers->create([
                        'amount'         => $payoutAmountCents,
                        'currency'       => 'USD',
                        'destination'    => $author->kyc_account_id,
                        'transfer_group' => 'order_' . $order->id,
                        'metadata'       => [
                            'order_id'          => $order->id,
                            'author_user_id'    => $author->id,
                            'type'              => 'author_royalty_batch',
                        ],
                    ], ['idempotency_key' => $idempotencyKey]);

                    $transaction->update([
                        'status'            => 'succeeded',
                        'payment_intent_id' => $transfer->id,
                        'payout_data'       => json_encode([
                            'transfer_id' => $transfer->id,
                            'amount'      => $payoutAmountCents / 100,
                            'currency'    => 'USD',
                            'destination' => $author->kyc_account_id,
                        ]),
                    ]);

                    foreach ($order->items->where('author_id', $authorUserId) as $item) {
                        if ($item->payout_status === 'initiated') {
                            $item->update(['payout_status' => 'completed', 'stripe_transfer_id' => $transfer->id]);
                        }
                    }
                }
                // Sum platform fees (already in cents, convert to major unit for total_platform_fee_amount if needed)
                $totalPlatformFeeCollectedCents += $order->items->where('author_id', $authorUserId)->sum(function ($item) use ($paymentService, $order) {
                    // Convert stored decimal back to cents for summation
                    return $paymentService->convertToSubunit($item->platform_fee_amount, 'USD');
                });
            } catch (ApiErrorException $e) {
                $allTransfersSuccessful = false;
                foreach ($order->items->where('author_id', $authorUserId) as $item) {
                    if ($item->payout_status === 'initiated') {
                        $item->update(['payout_status' => 'failed', 'payout_error' => $e->getMessage()]);
                    }
                }
                $transaction->update([
                    'status'      => 'failed',
                    'payout_data' => json_encode(['error' => $e->getMessage()]),
                ]);
                $this->notifyPayoutFailed($author, $payoutAmountCents / 100, strtoupper($payoutCurrency), $e->getMessage());
            } catch (\Throwable $e) {
                $allTransfersSuccessful = false;
                foreach ($order->items->where('author_id', $authorUserId) as $item) {
                    if ($item->payout_status === 'initiated') {
                        $item->update(['payout_status' => 'failed', 'payout_error' => $e->getMessage()]);
                    }
                }
                $transaction->update([
                    'status'      => 'failed',
                    'payout_data' => json_encode(['error' => $e->getMessage()]),
                ]);
                $this->notifyPayoutFailed($author, $payoutAmountCents / 100, strtoupper($payoutCurrency), $e->getMessage());
            }
        }

        // Update the main DigitalBookPurchase status based on overall payout success
        if ($allTransfersSuccessful) {
            $order->update([
                'payout_status' => 'completed',
                'platform_fee_amount' => $totalPlatformFeeCollectedCents / 100, // Store total in major unit
            ]);
        } else {
            $order->update([
                'payout_status' => 'failed', // Or 'payout_partially_completed' if you track that
                'platform_fee_amount' => $totalPlatformFeeCollectedCents / 100,
            ]);
        }
    }
}
