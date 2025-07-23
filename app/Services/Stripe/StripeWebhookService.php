<?php

namespace App\Services\Stripe;

use App\Jobs\ProcessAuthorPayout;
use App\Models\Book;
use App\Models\BookMetaDataAnalytics;
use App\Models\DigitalBookPurchase;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payments\PaymentService;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Stripe\Account;
use Stripe\PaymentIntent;

class StripeWebhookService
{
    use ApiResponse;

    protected PaymentService $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    /**
     * Handle the payment intent succeeded event.
     */
    public function handlePaymentIntentSucceeded(PaymentIntent $paymentIntent): JsonResponse
    {
        try {
            // Log the payment intent for debugging purposes
            Log::info('Payment Intent Succeeded', ['payment_intent' => $paymentIntent]);

            $metadata = $paymentIntent->metadata;

            $transaction = Transaction::where('reference', $metadata->reference)->first();
            $user = $transaction->user;

            if (! $transaction) {
                Log::error('Transaction not found for reference', ['reference' => $metadata->reference]);
            }

            $transaction->update(['status' => 'succeeded']);
            Log::info('Transaction updated to succeeded', ['transaction_id' => $transaction->id, 'user_id' => $user->id]);
            $transaction->refresh();

            // Get transaction purpose
            $purpose = $transaction->purpose_type;
            Log::info('Purpose Type', ['purpose_type' => $purpose]);

            switch ($purpose) {
                case 'digital_book_purchase':
                    $this->processDigitalBookPurchase($transaction, $user);
                    break;

                case 'order':
                    $this->processOrder($transaction, $user);
                    break;

                default:
                    Log::warning('Stripe Webhook: Unhandled transaction type received: '.$purpose, ['transaction_id' => $transaction->id]);
                    break;
            }

            Log::info('Transaction Processed', ['transaction' => $transaction]);

        } catch (\Exception $e) {
            Log::error('Error handling payment intent succeeded', [
                'error' => $e->getMessage(),
                'payment_intent' => $paymentIntent,
            ]);
        }
    }

    /**
     * Processes a successful digital book purchase transaction.
     *
     * @param  Transaction  $transaction  The local transaction record
     * @param  User  $user  The user associated with the transaction
     *
     * @throws \Exception If processing fails.
     */
    protected function processDigitalBookPurchase(Transaction $transaction, User $user): void
    {
        try {
            $txnMetaData = json_decode($transaction->meta_data);
            Log::info('Transaction Metadata', ['meta_data' => $txnMetaData]);

            $purchaseId = $transaction->purpose_id;
            if (! is_numeric($purchaseId)) {
                throw new \InvalidArgumentException('Purpose ID is missing or invalid for digital book purchase.');
            }
            $purchaseId = (int) $purchaseId;

            Log::info('Purchase ID from transaction', ['purchase_id' => $purchaseId]);

            $purchase = DigitalBookPurchase::with(['items.book.author'])
                ->where('user_id', $user->id)
                ->findOrFail($purchaseId);

            Log::info('Processing digital book purchase', ['purchase_id' => $purchase->id, 'user_id' => $user->id]);

            if ($purchase->status !== 'paid') {
                $purchase->update(['status' => 'paid']);
                Log::info("DigitalBookPurchase ID: {$purchase->id} status updated to 'paid'.");
            } else {
                Log::info("DigitalBookPurchase ID: {$purchase->id} already in 'paid' status. Skipping update.");
            }

            foreach ($purchase->items as $item) {
                Log::info('Processing purchase item for user access', ['item_id' => $item->id, 'book_id' => $item->book_id]);

                if (! $user->purchasedBooks()->where('book_id', $item->book_id)->exists()) {
                    Log::info('Granting book access to user', ['book_id' => $item->book_id, 'user_id' => $user->id]);
                    $user->purchasedBooks()->syncWithoutDetaching($item->book_id);
                    $bookAnalytics = BookMetaDataAnalytics::firstOrCreate(
                        ['book_id' => $item->book_id],
                        ['purchases' => 0, 'views' => 0, 'downloads' => 0, 'favourites' => 0, 'bookmarks' => 0, 'reads' => 0, 'shares' => 0, 'likes' => 0]
                    );
                    $bookAnalytics->increment('purchases', 1);
                    $bookAnalytics->save();
                    $bookAnalytics->refresh();
                } else {
                    Log::info('User already has access to book', ['book_id' => $item->book_id, 'user_id' => $user->id]);
                }

            }

            if (! in_array($purchase->status, ['payout_initiated', 'payout_completed', 'payout_failed'])) {
                $delayDays = 7; // Funds become available after 1 week so dispatch after 8 days

                // Dispatch the job to run after the specified delay
                ProcessAuthorPayout::dispatch($purchase->id)->delay(now()->addDays($delayDays));
                Log::info("ProcessAuthorPayout Job dispatched for DigitalBookPurchase ID: {$purchase->id}");
            } else {
                Log::info("Payout job for DigitalBookPurchase ID: {$purchase->id} already dispatched or completed. Skipping.");
            }

        } catch (ModelNotFoundException $e) {
            Log::error("DigitalBookPurchase not found for ID: {$purchaseId} (User ID: {$user->id}). Error: ".$e->getMessage());
            throw new \Exception('Digital book purchase record not found: '.$e->getMessage(), 404, $e);
        } catch (\Throwable $e) {
            Log::error('Error processing digital book purchase: '.$e->getMessage(), ['exception' => $e, 'purchase_id' => $purchaseId, 'user_id' => $user->id]);
            throw new \Exception('Failed to process digital book purchase: '.$e->getMessage(), 500, $e);
        }
    }

    protected function processOrder(Transaction $transaction, User $user): void
    {
        try {
            $txnMetaData = json_decode($transaction->meta_data);
            Log::info('Transaction Metadata', ['meta_data' => $txnMetaData]);
            $orderId = $transaction->purpose_id;
            Log::info('Order ID from transaction metadata', ['order_id' => $orderId]);
            $order = $user->orders()->findOrFail($orderId);
            Log::info('Processing order', ['order_id' => $order->id, 'user_id' => $user->id]);
            // Update the order status to completed
            $order->update(['status' => 'paid']);
            Log::info('Order updated to paid', ['order_id' => $order->id, 'order_status' => $order->status]);
        } catch (\Exception $e) {
            Log::error('Error processing order', [
                'error' => $e->getMessage(),
                'transaction_id' => $transaction->id,
                'user_id' => $user->id,
            ]);
        }
    }

    public function handleAccountUpdated(Account $account): JsonResponse
    {
        try {
            Log::info('Stripe Account Updated', ['account_id' => $account]);
            $user = User::where('kyc_account_id', $account->id)->first();

            if ($user) {
                if ($account->individual->verification->status === 'unverified' && $account->individual->verification->document->front === null) {
                    $user->update(['kyc_status' => 'document-required']);
                } elseif ($account->individual->verification->status === 'unverified' && $account->individual->verification->document->front !== null) {
                    $user->update(['kyc_status' => 'rejected']);
                } elseif ($account->individual->verification->status === 'pending' && $account->individual->verification->document->front !== null) {
                    $user->update(['kyc_status' => 'in-review']);
                } elseif ($account->individual->verification->status === 'pending' && $account->individual->verification->document->front === null) {
                    $user->update(['kyc_status' => 'document-required']);
                } elseif ($account->individual->verification->status === 'verified') {
                    $user->update([
                        'kyc_status' => 'verified',
                        'first_name' => $account->individual->first_name,
                        'last_name' => $account->individual->last_name,
                        'name' => $account->individual->first_name.' '.$account->individual->last_name,
                    ]);

                    Log::info('Stripe Account Update Processed', ['account' => $account]);
                }
            }
        } catch (\Exception $e) {
            Log::error('Error handling account updated', [
                'error' => $e->getMessage(),
                'account_id' => $account->id,
            ]);
        }
    }
}
