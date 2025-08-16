<?php

namespace App\Services\Stripe;

use App\Jobs\ProcessAuthorPayout;
use App\Models\BookMetaDataAnalytics;
use App\Models\DigitalBookPurchase;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payments\PaymentService;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
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
    public function handlePaymentIntentSucceeded(PaymentIntent $paymentIntent): void
    {
        try {

            $metadata = $paymentIntent->metadata;

            $transaction = Transaction::where('reference', $metadata->reference)->first();
            $user = $transaction->user;

            if (!$transaction) {
            }

            $transaction->update(['status' => 'succeeded']);
            $transaction->refresh();

            // Get transaction purpose
            $purpose = $transaction->purpose_type;

            switch ($purpose) {
                case 'digital_book_purchase':
                    $this->processSuccessfulDigitalBookPurchase($transaction, $user);
                    break;

                case 'order':
                    $this->processSuccessfulOrder($transaction, $user);
                    break;

                default:
                    break;
            }
        } catch (\Exception $e) {
        }
    }

    /**
     * Processes a successful digital book purchase transaction.
     *
     * @param Transaction $transaction The local transaction record
     * @param User $user The user associated with the transaction
     *
     * @throws \Exception If processing fails.
     */
    protected function processSuccessfulDigitalBookPurchase(Transaction $transaction, User $user): void
    {
        try {
            $purchaseId = $transaction->purpose_id;
            $purchase = DigitalBookPurchase::with(['items.book.author'])
                ->where('user_id', $user->id)
                ->findOrFail($purchaseId);

            // Only update if payment actually succeeded
            if ($purchase->status !== 'paid') {
                $purchase->update(['status' => 'paid']);
            }

            foreach ($purchase->items as $item) {
                // Add to user's purchased books using dedicated service
                app(\App\Services\Book\BookPurchaseService::class)
                    ->addBooksToUserLibrary($user, [$item->book_id]);

                // NOW update author wallet (only after successful payment)
                $author = $item->book->author;
                if ($author) {
                    $author->increment('wallet_balance', $item->author_payout_amount);
                    $author->save();

                    // Create payout transaction record
                    Transaction::create([
                        'id' => \Illuminate\Support\Str::uuid(),
                        'user_id' => $author->id,
                        'reference' => uniqid('pay_immediate_'),
                        'status' => 'succeeded',
                        'currency' => $purchase->currency ?? 'USD',
                        'amount' => $item->author_payout_amount,
                        'payment_provider' => 'app',
                        'description' => "Immediate author payout for DigitalBookPurchase ID: {$purchase->id}",
                        'type' => 'payout',
                        'direction' => 'credit',
                        'purpose_type' => 'digital_book_purchase',
                        'purpose_id' => $purchase->id,
                    ]);
                }

                // Update analytics
                $bookAnalytics = BookMetaDataAnalytics::firstOrCreate(
                    ['book_id' => $item->book_id],
                    ['purchases' => 0, 'views' => 0, 'downloads' => 0, 'favourites' => 0, 'bookmarks' => 0, 'reads' => 0, 'shares' => 0, 'likes' => 0]
                );
                $bookAnalytics->increment('purchases', 1);
                $bookAnalytics->save();
            }
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * @throws \Exception
     */
    protected function processSuccessfulOrder(Transaction $transaction, User $user): void
    {
        try {
            $txnMetaData = json_decode($transaction->meta_data);
            $orderId = $transaction->purpose_id;
            if (!is_numeric($orderId)) {
                throw new \InvalidArgumentException('Purpose ID is missing or invalid for book order.');
            }
            $orderId = (int)$orderId;
            $order = $user->orders()->with('items.book.author')->findOrFail($orderId);

            if ($order->status === 'pending') {
                $order->update(['status' => 'paid']);

                foreach ($order->items as $item) {
                    // Update analytics
                    $bookAnalytics = BookMetaDataAnalytics::firstOrCreate(
                        ['book_id' => $item->book_id],
                        ['purchases' => 0, 'views' => 0, 'downloads' => 0, 'favourites' => 0, 'bookmarks' => 0, 'reads' => 0, 'shares' => 0, 'likes' => 0]
                    );
                    $bookAnalytics->increment('purchases', (int) $item->quantity);
                    $bookAnalytics->save();

                    // Immediately update author wallet balance
                    $author = $item->book->author;
                    if ($author && isset($item->author_payout_amount)) {
                        $authorPayoutAmount = $item->author_payout_amount * $item->quantity;
                        $author->increment('wallet_balance', $authorPayoutAmount);
                        $author->save();

                        // Create immediate payout transaction record
                        Transaction::create([
                            'id' => \Illuminate\Support\Str::uuid(),
                            'user_id' => $author->id,
                            'reference' => uniqid('pay_immediate_'),
                            'status' => 'succeeded',
                            'currency' => 'USD',
                            'amount' => $authorPayoutAmount,
                            'payment_provider' => 'app',
                            'description' => "Immediate author payout for Order ID: {$order->id}",
                            'type' => 'payout',
                            'direction' => 'credit',
                            'purpose_type' => 'order',
                            'purpose_id' => $order->id,
                        ]);
                    }
                }
            }

            if ($order->payout_status === 'pending') {
                $delayDays = 7;
                ProcessAuthorPayout::dispatch(purpose: 'order', purposeId: $order->id)->delay(now()->addDays($delayDays));
            }
        } catch (ModelNotFoundException $e) {
            throw new \Exception('Order record not found: ' . $e->getMessage(), 404, $e);
        } catch (\Throwable $e) {
            throw new \Exception('Failed to process order: ' . $e->getMessage(), 500, $e);
        }
    }

    public function handleAccountUpdated(Account $account): void
    {
        try {
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
                        'name' => $account->individual->first_name . ' ' . $account->individual->last_name,
                    ]);
                }
            } else {
            }
        } catch (\Exception $e) {
        }
    }
}
