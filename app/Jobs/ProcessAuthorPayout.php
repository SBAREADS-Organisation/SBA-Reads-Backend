<?php

namespace App\Jobs;

use App\Models\DigitalBookPurchase;
use App\Models\Order;
use App\Services\Payments\PaymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

// Import the item model

// Assuming your PaymentService is here

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
            case 'digital_book_purchase' :
                $this->processDigitalBookPurchasePayout($paymentService);
                break;

            case 'order'  :
                $this->processOrderPayout($paymentService);
                break;

            default:
                Log::error('Invalid purpose for ProcessAuthorPayout job: ' . $this->purpose);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        switch ($this->purpose) {
            case 'digital_book_purchase' :
                Log::critical("ProcessAuthorPayout Job failed for DigitalBookPurchase ID:  {
                $this->purposeId
                }
                . Reason: " .
                $exception->getMessage());
                $purchase = DigitalBookPurchase::where('id', $this->purposeId)->first();
                if ($purchase) {
                    $purchase->update(['status' => 'payout_failed']);
                    // Mark all pending purchase items as failed
                    $purchase->items()->where('payout_status', 'pending')->update(['payout_status' => 'failed', 'payout_error' => 'Job failed: ' . $exception->getMessage()]);
                }
                break;

            case 'order' :
                Log::critical("ProcessAuthorPayout Job failed for Order ID: {$this->purposeId}. Reason: " . $exception->getMessage());
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
        Log::info("ProcessAuthorPayout Job started for DigitalBookPurchase ID: {$this->purposeId}");

        // 1. Retrieve the DigitalBookPurchase and its items
        $purchase = DigitalBookPurchase::where('id', $this->purposeId)
            ->with(['items.book.author']) // Eager load relationships
            ->where('status', 'paid') // Ensure purchase is marked as paid
            ->first();

        if (!$purchase) {
            Log::warning("ProcessAuthorPayout: DigitalBookPurchase not found or not in 'paid' status for ID: {$this->purposeId}");

            return;
        }

        // Check if payouts for this purchase have already been initiated/completed
        // This is crucial for idempotency at the purchase level
        $payoutsAlreadyInitiated = $purchase->items->every(function ($item) {
            return in_array($item->payout_status, ['initiated', 'completed', 'failed']);
        });

        if ($payoutsAlreadyInitiated) {
            Log::info("ProcessAuthorPayout: Payouts already processed for DigitalBookPurchase ID: {$purchase->id}. Skipping.");

            return;
        }

        // Aggregate payouts by author
        $authorPayouts = []; // [author_user_id => total_payout_amount_cents]

        foreach ($purchase->items as $item) {
            // Skip if this specific item's payout is already handled
            if (in_array($item->payout_status, ['initiated', 'completed', 'failed'])) {
                continue;
            }

            if (!$item->book || !$item->author || empty($item->author->kyc_account_id)) {
                Log::error("ProcessAuthorPayout: Missing book, author, or Stripe account for DigitalBookPurchaseItem ID: {$item->id}. Skipping payout for this item.");
                $item->update(['payout_status' => 'failed', 'payout_error' => 'Missing author or Stripe account.']);

                continue;
            }

            // Calculate amounts in cents for precision
            // Use price_at_purchase (which is decimal) and convert to cents
            $itemPriceInCents = $paymentService->convertToSubunit($item->price_at_purchase, $purchase->currency);
            $itemTotalAmountCents = $itemPriceInCents * $item->quantity;

            $authorPayoutPercentage = 0.80; // 80%

            $authorItemPayoutCents = (int)round($itemTotalAmountCents * $authorPayoutPercentage);
            // Adjust platform fee for rounding to ensure sum matches total
            $platformItemFeeCents = $itemTotalAmountCents - $authorItemPayoutCents;

            // Aggregate payout for the author
            $authorPayouts[$item->author->id] = ($authorPayouts[$item->author->id] ?? 0) + $authorItemPayoutCents;

            // Update item with calculated amounts (store as decimals if that's the DB column type)
            $item->author_payout_amount = $authorItemPayoutCents / 100; // Convert back to major unit for decimal storage
            $item->platform_fee_amount = $platformItemFeeCents / 100; // Convert back to major unit for decimal storage
            $item->payout_status = 'initiated'; // Temporarily mark as initiated within the loop
            $item->save();
        }

        // Perform transfers for each unique author
        $totalPlatformFeeCollectedCents = 0;
        $allTransfersSuccessful = true;

        foreach ($authorPayouts as $authorUserId => $payoutAmountCents) {
            $author = $purchase->items->firstWhere('author_id', $authorUserId)->author; // Get author (User) object

            if ($payoutAmountCents <= 0) {
                Log::info("ProcessAuthorPayout: Skipping payout for Author (User) ID: {$authorUserId} as amount is zero or negative.");

                continue;
            }

            try {
                // Create the Stripe Transfer
                $transfer = $this->stripe->transfers->create([
                    'amount' => $payoutAmountCents, // Amount to send to the author (in cents)
                    'currency' => $purchase->currency,
                    'destination' => $author->kyc_account_id,
                    'transfer_group' => 'purchase_' . $purchase->id, // Group all transfers for this purchase
                    'metadata' => [
                        'digital_book_purchase_id' => $purchase->id,
                        'author_user_id' => $author->id,
                        'payment_intent_id' => $purchase->stripe_payment_intent_id,
                        'type' => 'author_royalty_batch',
                    ],
                ]);

                Log::info("Stripe Transfer created for Author (User) ID: {$author->id}, Amount: {$payoutAmountCents}, Transfer ID: {$transfer->id}");

                // Update all relevant purchase items for this author
                foreach ($purchase->items->where('author_id', $authorUserId) as $item) {
                    if ($item->payout_status === 'initiated') { // Only update if it was part of this batch
                        $item->update([
                            'payout_status' => 'completed',
                            'stripe_transfer_id' => $transfer->id, // Store the transfer ID
                        ]);
                    }
                }
                // Sum platform fees (already in cents, convert to major unit for total_platform_fee_amount if needed)
                $totalPlatformFeeCollectedCents += $purchase->items->where('author_id', $authorUserId)->sum(function ($item) use ($paymentService, $purchase) {
                    // Convert stored decimal back to cents for summation
                    return $paymentService->convertToSubunit($item->platform_fee_amount, $purchase->currency);
                });

            } catch (ApiErrorException $e) {
                Log::error("Stripe API Error during payout for Author (User) ID: {$authorUserId} (Purchase ID: {$purchase->id}) (Amount: {$payoutAmountCents}): " . $e->getMessage(), [
                    'code' => $e->getStripeCode(),
                    'error' => $e->getJsonBody(),
                ]);
                $allTransfersSuccessful = false;
                // Mark relevant purchase items as failed
                foreach ($purchase->items->where('author_id', $authorUserId) as $item) {
                    if ($item->payout_status === 'initiated') {
                        $item->update(['payout_status' => 'failed', 'payout_error' => $e->getMessage()]);
                    }
                }
            } catch (\Throwable $e) {
                Log::error("General Error during payout for Author (User) ID: {$authorUserId} (Purchase ID: {$purchase->id}): " . $e->getMessage(), [
                    'exception' => $e,
                ]);
                $allTransfersSuccessful = false;
                foreach ($purchase->items->where('author_id', $authorUserId) as $item) {
                    if ($item->payout_status === 'initiated') {
                        $item->update(['payout_status' => 'failed', 'payout_error' => $e->getMessage()]);
                    }
                }
            }
        }

        // Update the main DigitalBookPurchase status based on overall payout success
        if ($allTransfersSuccessful) {
            $purchase->update([
                'status' => 'payout_completed',
                'platform_fee_amount' => $totalPlatformFeeCollectedCents / 100, // Store total in major unit
            ]);
            Log::info("All payouts completed for DigitalBookPurchase ID: {$purchase->id}.");
        } else {
            $purchase->update([
                'status' => 'payout_failed', // Or 'payout_partially_completed' if you track that
                'platform_fee_amount' => $totalPlatformFeeCollectedCents / 100,
            ]);
            Log::warning("Some payouts failed for DigitalBookPurchase ID: {$purchase->id}.");
            // You might want to re-dispatch a job to retry failed payouts or notify admin
        }
    }

    private function processOrderPayout(PaymentService $paymentService): void
    {
        Log::info("ProcessAuthorPayout Job started for Order ID: {$this->purposeId}");

        // 1. Retrieve the DigitalBookPurchase and its items
        $order = Order::where('id', $this->purposeId)
            ->with(['items.book.author']) // Eager load relationships
            ->where('payout_status', 'pending') // Ensure payout is pending
            ->first();

        if (!$order) {
            Log::warning("ProcessAuthorPayout: Order not found or not in 'pending' status for ID: {$this->purposeId}");

            return;
        }

        // Check if payouts for this purchase have already been initiated/completed
        // This is crucial for idempotency at the purchase level
        $payoutsAlreadyInitiated = $order->items->every(function ($item) {
            return in_array($item->payout_status, ['initiated', 'completed', 'failed']);
        });

        if ($payoutsAlreadyInitiated) {
            Log::info("ProcessAuthorPayout: Payouts already processed for Order ID: {$order->id}. Skipping.");

            return;
        }

        // Aggregate payouts by author
        $authorPayouts = []; // [author_user_id => total_payout_amount_cents]

        foreach ($order->items as $item) {
            // Skip if this specific item's payout is already handled
            if (in_array($item->payout_status, ['initiated', 'completed', 'failed'])) {
                continue;
            }

            if (!$item->book || !$item->author || empty($item->author->kyc_account_id)) {
                Log::error("ProcessAuthorPayout: Missing book, author, or Stripe account for Order ID: {$item->id}. Skipping payout for this item.");
                $item->update(['payout_status' => 'failed', 'payout_error' => 'Missing author or Stripe account.']);

                continue;
            }

            // Calculate amounts in cents for precision
            // Use price_at_purchase (which is decimal) and convert to cents
            $itemTotalAmountCents = $paymentService->convertToSubunit($item->total_price, 'USD');

            Log::info("Total item $item->id amount in cents: $itemTotalAmountCents");

            $authorPayoutPercentage = 0.80; // 80%

            $authorItemPayoutCents = (int)round($itemTotalAmountCents * $authorPayoutPercentage);
            // Adjust platform fee for rounding to ensure sum matches total
            $platformItemFeeCents = $itemTotalAmountCents - $authorItemPayoutCents;
            Log::info('Author item payout in cents: ' . $authorItemPayoutCents);
            Log::info('Platform item fee in cents: ' . $platformItemFeeCents);

            // Aggregate payout for the author
            $authorPayouts[$item->author->id] = ($authorPayouts[$item->author->id] ?? 0) + $authorItemPayoutCents;

            // Update item with calculated amounts (store as decimals if that's the DB column type)
            $item->author_payout_amount = $authorItemPayoutCents / 100; // Convert back to major unit for decimal storage
            $item->platform_fee_amount = $platformItemFeeCents / 100; // Convert back to major unit for decimal storage
            $item->payout_status = 'initiated'; // Temporarily mark as initiated within the loop
            $item->save();
            Log::info("ProcessAuthorPayout: Updated Order Item ID: {$item->id} with author payout: {$item->author_payout_amount} and platform fee: {$item->platform_fee_amount}");
        }

        // Perform transfers for each unique author
        $totalPlatformFeeCollectedCents = 0;
        $allTransfersSuccessful = true;

        foreach ($authorPayouts as $authorUserId => $payoutAmountCents) {
            $author = $order->items->firstWhere('author_id', $authorUserId)->author; // Get author (User) object

            if ($payoutAmountCents <= 0) {
                Log::info("ProcessAuthorPayout: Skipping payout for Author (User) ID: {$authorUserId} as amount is zero or negative.");

                continue;
            }

            try {
                // Create the Stripe Transfer
                $transfer = $this->stripe->transfers->create([
                    'amount' => $payoutAmountCents, // Amount to send to the author (in cents)
                    'currency' => 'USD',
                    'destination' => $author->kyc_account_id,
                    'transfer_group' => 'order_' . $order->id, // Group all transfers for this order
                    'metadata' => [
                        'order_id' => $order->id,
                        'author_user_id' => $author->id,
                        'payment_intent_id' => $order->stripe_payment_intent_id,
                        'type' => 'author_royalty_batch',
                    ],
                ]);

                Log::info("Stripe Transfer created for Author (User) ID: {$author->id}, Amount: {$payoutAmountCents}, Transfer ID: {$transfer->id}");

                // Update all relevant order items for this author
                foreach ($order->items->where('author_id', $authorUserId) as $item) {
                    if ($item->payout_status === 'initiated') { // Only update if it was part of this batch
                        $item->update([
                            'payout_status' => 'completed',
                            'stripe_transfer_id' => $transfer->id, // Store the transfer ID
                        ]);
                    }
                }
                // Sum platform fees (already in cents, convert to major unit for total_platform_fee_amount if needed)
                $totalPlatformFeeCollectedCents += $order->items->where('author_id', $authorUserId)->sum(function ($item) use ($paymentService, $order) {
                    // Convert stored decimal back to cents for summation
                    return $paymentService->convertToSubunit($item->platform_fee_amount, 'USD');
                });

            } catch (ApiErrorException $e) {
                Log::error("Stripe API Error during payout for Author (User) ID: {$authorUserId} (Order ID: {$order->id}) (Amount: {$payoutAmountCents}): " . $e->getMessage(), [
                    'code' => $e->getStripeCode(),
                    'error' => $e->getJsonBody(),
                ]);
                $allTransfersSuccessful = false;
                // Mark relevant order items as failed
                foreach ($order->items->where('author_id', $authorUserId) as $item) {
                    if ($item->payout_status === 'initiated') {
                        $item->update(['payout_status' => 'failed', 'payout_error' => $e->getMessage()]);
                    }
                }
            } catch (\Throwable $e) {
                Log::error("General Error during payout for Author (User) ID: {$authorUserId} (Order ID: {$order->id}): " . $e->getMessage(), [
                    'exception' => $e,
                ]);
                $allTransfersSuccessful = false;
                foreach ($order->items->where('author_id', $authorUserId) as $item) {
                    if ($item->payout_status === 'initiated') {
                        $item->update(['payout_status' => 'failed', 'payout_error' => $e->getMessage()]);
                    }
                }
            }
        }

        // Update the main DigitalBookPurchase status based on overall payout success
        if ($allTransfersSuccessful) {
            $order->update([
                'payout_status' => 'completed',
                'platform_fee_amount' => $totalPlatformFeeCollectedCents / 100, // Store total in major unit
            ]);
            Log::info("All payouts completed for DigitalBookPurchase ID: {$order->id}.");
        } else {
            $order->update([
                'payout_status' => 'failed', // Or 'payout_partially_completed' if you track that
                'platform_fee_amount' => $totalPlatformFeeCollectedCents / 100,
            ]);
            Log::warning("Some payouts failed for DigitalBookPurchase ID: {$order->id}.");
            // You might want to re-dispatch a job to retry failed payouts or notify admin

        }
    }
}
