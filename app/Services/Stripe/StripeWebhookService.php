<?php

namespace App\Services\Stripe;

use App\Models\Book;
use App\Models\Transaction;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Stripe\Account;
use Stripe\PaymentIntent;

class StripeWebhookService
{
    use ApiResponse;

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

    protected function processDigitalBookPurchase(Transaction $transaction, User $user): void
    {
        try {
            $txnMetaData = json_decode($transaction->meta_data);
            $bookIds = $txnMetaData->book_ids;
            Log::info('Transaction Metadata', ['meta_data' => $txnMetaData]);
            Log::info('Book IDs from transaction metadata', ['book_ids' => $bookIds]);
            foreach ($bookIds as $bookId) {
                $book = Book::findOrFail($bookId);
                Log::info('Processing book purchase', ['book_id' => $book->id, 'user_id' => $user->id]);

                // Check if book exists in user's purchased books
                if ($user->purchasedBooks()->where('book_id', $bookId)->exists()) {
                    Log::info('Book already purchased', ['book' => $book]);

                    continue;
                }
                // Attach the book to the user
                $user->purchasedBooks()->attach($book->id);
            }
        } catch (\Exception $e) {
            Log::error('Error processing digital book purchase', [$e->getMessage()]);
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
