<?php

namespace App\Services\Stripe;

use App\Jobs\ProcessAuthorPayout;
use App\Models\BookMetaDataAnalytics;
use App\Models\DigitalBookPurchase;
use App\Models\StripePayout;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payments\PaymentService;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Stripe\Account;
use Stripe\PaymentIntent;
use Stripe\Payout;

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
            $txnMetaData = $transaction->meta_data; // Already cast as array in model
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

    /**
     * Handle when a payout is created in Stripe
     */
    public function handlePayoutCreated(Payout $payout): void
    {
        try {
            // Find the user associated with this payout via their Stripe account
            $user = $this->getUserFromStripeAccount($payout->destination);

            if (!$user) {
                Log::warning('Could not find user for payout', ['payout_id' => $payout->id]);
                return;
            }

            // Create or update the payout record using the model's utility method
            $stripePayout = StripePayout::where('stripe_payout_id', $payout->id)->first();

            if ($stripePayout) {
                $stripePayout->updateFromStripeWebhook($payout->toArray());
            } else {
                StripePayout::createFromStripeObject($user->id, $payout->toArray());
            }

            Log::info('Payout created successfully', ['payout_id' => $payout->id, 'user_id' => $user->id]);
        } catch (\Exception $e) {
            Log::error('Failed to handle payout created', [
                'payout_id' => $payout->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle when a payout is updated in Stripe
     */
    public function handlePayoutUpdated(Payout $payout): void
    {
        try {
            $stripePayout = StripePayout::where('stripe_payout_id', $payout->id)->first();

            if (!$stripePayout) {
                // If payout doesn't exist, create it (fallback)
                $this->handlePayoutCreated($payout);
                return;
            }

            // Update the payout record
            $stripePayout->update([
                'status' => $payout->status,
                'failure_code' => $payout->failure_code,
                'failure_message' => $payout->failure_message,
                'stripe_response' => $payout->toArray(),
            ]);

            Log::info('Payout updated successfully', ['payout_id' => $payout->id]);
        } catch (\Exception $e) {
            Log::error('Failed to handle payout updated', [
                'payout_id' => $payout->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle when a payout is successfully paid in Stripe
     */
    public function handlePayoutPaid(Payout $payout): void
    {
        try {
            $stripePayout = StripePayout::where('stripe_payout_id', $payout->id)->first();

            if (!$stripePayout) {
                Log::warning('Payout record not found for paid event', ['payout_id' => $payout->id]);
                return;
            }

            // Update the payout record to paid status
            $stripePayout->update([
                'status' => 'paid',
                'stripe_response' => $payout->toArray(),
            ]);

            Log::info('Payout marked as paid', [
                'payout_id' => $payout->id,
                'user_id' => $stripePayout->user_id,
                'amount' => $stripePayout->amount
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to handle payout paid', [
                'payout_id' => $payout->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle when a payout fails in Stripe
     */
    public function handlePayoutFailed(Payout $payout): void
    {
        try {
            $stripePayout = StripePayout::where('stripe_payout_id', $payout->id)->first();

            if (!$stripePayout) {
                Log::warning('Payout record not found for failed event', ['payout_id' => $payout->id]);
                return;
            }

            // Update the payout record to failed status
            $stripePayout->update([
                'status' => 'failed',
                'failure_code' => $payout->failure_code,
                'failure_message' => $payout->failure_message,
                'stripe_response' => $payout->toArray(),
            ]);

            // Optionally, you could add the failed amount back to user's wallet
            // $stripePayout->user->increment('wallet_balance', $stripePayout->amount);

            Log::error('Payout failed', [
                'payout_id' => $payout->id,
                'user_id' => $stripePayout->user_id,
                'failure_code' => $payout->failure_code,
                'failure_message' => $payout->failure_message
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to handle payout failed', [
                'payout_id' => $payout->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle when a payout is canceled in Stripe
     */
    public function handlePayoutCanceled(Payout $payout): void
    {
        try {
            $stripePayout = StripePayout::where('stripe_payout_id', $payout->id)->first();

            if (!$stripePayout) {
                Log::warning('Payout record not found for canceled event', ['payout_id' => $payout->id]);
                return;
            }

            // Update the payout record to canceled status
            $stripePayout->update([
                'status' => 'canceled',
                'stripe_response' => $payout->toArray(),
            ]);

            // Add the canceled amount back to user's wallet
            $stripePayout->user->increment('wallet_balance', $stripePayout->amount);

            Log::info('Payout canceled and amount refunded to wallet', [
                'payout_id' => $payout->id,
                'user_id' => $stripePayout->user_id,
                'amount' => $stripePayout->amount
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to handle payout canceled', [
                'payout_id' => $payout->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Helper method to find user by Stripe account/destination
     */
    private function getUserFromStripeAccount(string $destination): ?User
    {
        // Method 1: Find user by their payment methods that match this destination
        $user = User::whereHas('paymentMethods', function ($query) use ($destination) {
            $query->where('provider_payment_method_id', $destination)
                ->where('provider', 'stripe')
                ->where('purpose', 'payout');
        })->first();

        if ($user) {
            return $user;
        }

        // Method 2: Try to find by Stripe Connect account if the destination is linked to an account
        // This would require additional Stripe API calls to get the account from the destination
        try {
            $stripe = new \Stripe\StripeClient(config('services.stripe.secret'));

            // If destination is a bank account, get the account it belongs to
            if (str_starts_with($destination, 'ba_')) {
                $bankAccount = $stripe->accounts->retrieveExternalAccount(
                    'acct_connected_account_id', // You'd need to determine this
                    $destination
                );

                // Find user by the connected account ID
                $user = User::where('kyc_account_id', $bankAccount->account)->first();
            }
        } catch (\Exception $e) {
            Log::warning('Could not retrieve destination details from Stripe', [
                'destination' => $destination,
                'error' => $e->getMessage()
            ]);
        }

        // Method 3: Look for users who have this destination in their kyc_metadata
        $user = User::whereJsonContains('kyc_metadata->bank_account_id', $destination)
            ->orWhereJsonContains('kyc_metadata->external_accounts', $destination)
            ->first();

        return $user;
    }
}
