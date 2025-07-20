<?php

namespace App\Services\Stripe;

use App\Models\Book;
use App\Models\Transaction;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Stripe\PaymentIntent;
use Stripe\Stripe;

class StripeWebhookService
{
    use ApiResponse;

    /**
     * Handle the payment intent succeeded event.
     *
     * @param PaymentIntent $paymentIntent
     * @return JsonResponse
     */
    public function handlePaymentIntentSucceeded(PaymentIntent $paymentIntent): JsonResponse
    {
        try {
            // Log the payment intent for debugging purposes
            Log::info('Payment Intent Succeeded', ['payment_intent' => $paymentIntent]);

            $metadata = $paymentIntent->metadata;

            $transaction = Transaction::where('reference', $metadata->reference)->first();
            $user = $transaction->user;

            if (!$transaction) {
                Log::error('Transaction not found for reference', ['reference' => $metadata->reference]);
            }

            $transaction->update(['status' => 'succeeded']);
            $transaction->refresh();

            // Get transaction purpose
            $purpose = $transaction->purpose_type;
            Log::info('Purpose Type', ['purpose_type' => $purpose]);

            switch ($purpose) {
                case 'digital_book_purchase':
                    $this->processDigitalBookPurchase($transaction, $user);
                    break;

                default:
                    Log::warning('Stripe Webhook: Unhandled transaction type received: ' . $purpose, ['transaction_id' => $transaction->id]);
                    break;
            }


            Log::info('Transaction Found', ['transaction' => $transaction]);

            // Example response
            return $this->success(['message' => 'Payment Intent Succeeded', 'data' => $paymentIntent]);
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
            Log::error("Error processing digital book purchase", [$e->getMessage()]);
        }
    }

}
