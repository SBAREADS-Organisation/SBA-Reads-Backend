<?php

namespace App\Services\Paystack;

use App\Models\PaystackTransaction;
use App\Models\Transaction;
use App\Models\PaymentAudit;
use App\Models\WebhookEvent;
use App\Services\Payments\PaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaystackWebhookService
{
    protected $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    /**
     * Handle Paystack webhook events
     */
    public function handleWebhook(array $payload): bool
    {
        $event = $payload['event'] ?? '';
        $data = $payload['data'] ?? [];

        try {
            switch ($event) {
                case 'charge.success':
                    return $this->handleChargeSuccess($data);
                case 'transfer.success':
                    return $this->handleTransferSuccess($data);
                case 'transfer.failed':
                    return $this->handleTransferFailed($data);
                case 'subscription.create':
                    return $this->handleSubscriptionCreate($data);
                case 'subscription.disable':
                    return $this->handleSubscriptionDisable($data);
                default:
                    Log::info("Unhandled Paystack event: {$event}");
                    return false;
            }
        } catch (\Exception $e) {
            Log::error('Paystack webhook error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Handle successful charge
     */
    protected function handleChargeSuccess(array $data): bool
    {
        Log::info('Paystack charge success handled', $data);

        $transaction = Transaction::where('payment_provider', 'paystack')
            ->where('reference', $data['reference'])
            ->first();

        return DB::transaction(function () use ($data, $transaction) {
            // Create or update Paystack transaction
            $paystackTransaction = PaystackTransaction::updateOrCreate(
                ['paystack_reference' => $data['reference']],
                [
                    'transaction_id' => $transaction->id,
                    'user_id' => $transaction->user_id ?? null,
                    'paystack_transaction_id' => isset($data['id']) ? (string) $data['id'] : null,
                    'amount_kobo' => $data['amount'] ?? 0,
                    'amount_naira' => isset($data['amount']) ? ($data['amount'] / 100) : 0, // Convert from kobo
                    'currency' => $data['currency'] ?? 'NGN',
                    'status' => 'success',
                    'paid_at' => $data['paid_at'] ?? now(),
                    'channel' => $data['channel'] ?? null,
                    'gateway_response' => $data['gateway_response'] ?? null,
                    'paystack_customer_code' => $data['customer']['customer_code'] ?? null,
                    'customer_email' => $data['customer']['email'] ?? null,
                    'fees_kobo' => $data['fees'] ?? 0,
                    'ip_address' => $data['ip_address'] ?? null,
                    'metadata' => $data,
                    'paystack_response' => $data,
                ]
            );

            // Update related transaction if exists
            $transaction = Transaction::where('reference', 'LIKE', '%' . $data['reference'] . '%')
                ->orWhere('payment_intent_id', $data['reference'])
                ->first();

            if ($transaction) {
                $transaction->update([
                    'status' => 'succeeded',
                ]);

                // Process the successful transaction based on purpose
                $this->processSuccessfulTransaction($transaction, $data);
            }

            // Create payment audit
            PaymentAudit::create([
                'transaction_id' => $paystackTransaction->transaction_id,
                'transaction_reference' => $paystackTransaction->paystack_reference,
                'total_amount' => $paystackTransaction->amount_naira,
                'authors_pay' => 0,
                'company_pay' => 0,
                'vat_amount' => 0,
                'currency' => $paystackTransaction->currency,
                'payment_status' => 'succeeded',
                'audit_metadata' => (array) $data,
            ]);

            // Create webhook event
            WebhookEvent::create([
                'stripe_event_id' => $data['id'],
                'type' => 'Paystack charge.success',
                'payload' => $data,
                'status' => 'success',
            ]);

            return true;
        });
    }

    /**
     * Handle successful transfer
     */
    protected function handleTransferSuccess(array $data): bool
    {
        return DB::transaction(function () use ($data) {
            Log::info('Paystack transfer success handled', $data);

            // Find and update related withdrawal transaction
            $transferReference = $data['reference'] ?? null;
            if ($transferReference) {
                $withdrawal = \App\Models\Withdrawal::where('reference', $transferReference)
                    ->orWhere('provider_reference', $transferReference)
                    ->first();

                if ($withdrawal) {
                    $withdrawal->update([
                        'status' => 'completed',
                        'completed_at' => now(),
                        'provider_response' => json_encode($data)
                    ]);

                    // Update related transaction if exists
                    $transaction = \App\Models\Transaction::where('reference', $withdrawal->reference)->first();
                    if ($transaction) {
                        $transaction->update(['status' => 'succeeded']);
                    }
                }
            }

            WebhookEvent::create([
                'service' => 'paystack',
                'event_type' => 'transfer.success',
                'payload' => $data,
                'processed' => true,
            ]);

            return true;
        });
    }

    /**
     * Handle failed transfer
     */
    protected function handleTransferFailed(array $data): bool
    {
        return DB::transaction(function () use ($data) {
            Log::warning('Paystack transfer failed', $data);

            // Find and update related withdrawal transaction
            $transferReference = $data['reference'] ?? null;
            if ($transferReference) {
                $withdrawal = \App\Models\Withdrawal::where('reference', $transferReference)
                    ->orWhere('provider_reference', $transferReference)
                    ->first();

                if ($withdrawal) {
                    $withdrawal->update([
                        'status' => 'failed',
                        'failed_at' => now(),
                        'failure_reason' => $data['message'] ?? 'Transfer failed',
                        'provider_response' => json_encode($data)
                    ]);

                    // Update related transaction if exists
                    $transaction = \App\Models\Transaction::where('reference', $withdrawal->reference)->first();
                    if ($transaction) {
                        $transaction->update(['status' => 'failed']);
                    }

                    // Optionally refund user wallet if this was a withdrawal
                    if ($withdrawal->user_id) {
                        $user = \App\Models\User::find($withdrawal->user_id);
                        if ($user) {
                            $user->increment('wallet_balance', $withdrawal->amount);
                        }
                    }
                }
            }

            WebhookEvent::create([
                'service' => 'paystack',
                'event_type' => 'transfer.failed',
                'payload' => $data,
                'processed' => true,
            ]);

            return true;
        });
    }

    /**
     * Handle subscription creation
     */
    protected function handleSubscriptionCreate(array $data): bool
    {
        return DB::transaction(function () use ($data) {
            Log::info('Paystack subscription created', $data);

            $subscriptionCode = $data['subscription_code'] ?? null;
            $customerEmail = $data['customer']['email'] ?? null;

            if ($subscriptionCode && $customerEmail) {
                // Find user by email
                $user = \App\Models\User::where('email', $customerEmail)->first();

                if ($user) {
                    // Update or create user subscription
                    $userSubscription = \App\Models\UserSubscription::where('user_id', $user->id)
                        ->where('provider', 'paystack')
                        ->first();

                    if ($userSubscription) {
                        $userSubscription->update([
                            'status' => 'active',
                            'provider_subscription_id' => $subscriptionCode,
                            'provider_response' => json_encode($data)
                        ]);
                    }
                }
            }

            WebhookEvent::create([
                'service' => 'paystack',
                'event_type' => 'subscription.create',
                'payload' => $data,
                'processed' => true,
            ]);

            return true;
        });
    }

    /**
     * Handle subscription disable
     */
    protected function handleSubscriptionDisable(array $data): bool
    {
        return DB::transaction(function () use ($data) {
            Log::info('Paystack subscription disabled', $data);

            $subscriptionCode = $data['subscription_code'] ?? null;

            if ($subscriptionCode) {
                // Find and disable the subscription
                $userSubscription = \App\Models\UserSubscription::where('provider_subscription_id', $subscriptionCode)
                    ->where('provider', 'paystack')
                    ->first();

                if ($userSubscription) {
                    $userSubscription->update([
                        'status' => 'cancelled',
                        'cancelled_at' => now(),
                        'provider_response' => json_encode($data)
                    ]);
                }
            }

            WebhookEvent::create([
                'service' => 'paystack',
                'event_type' => 'subscription.disable',
                'payload' => $data,
                'processed' => true,
            ]);

            return true;
        });
    }

    /**
     * Verify webhook signature
     */
    public function verifySignature(string $payload, string $signature): bool
    {
        $secret = config('services.paystack.webhook_secret');
        $computedSignature = hash_hmac('sha512', $payload, $secret);

        return hash_equals($computedSignature, $signature);
    }

    /**
     * Process successful transaction based on its purpose
     */
    protected function processSuccessfulTransaction(Transaction $transaction, array $data): void
    {
        try {
            $user = $transaction->user;

            switch ($transaction->purpose_type) {
                case 'digital_book_purchase':
                    $this->processSuccessfulDigitalBookPurchase($transaction, $user);
                    break;

                case 'order':
                    $this->processSuccessfulOrder($transaction, $user);
                    break;

                default:
                    Log::info("Unhandled transaction purpose: {$transaction->purpose_type}");
                    break;
            }
        } catch (\Exception $e) {
            Log::error('Error processing successful Paystack transaction: ' . $e->getMessage());
        }
    }

    /**
     * Process successful digital book purchase (mirrors Stripe webhook functionality)
     */
    protected function processSuccessfulDigitalBookPurchase(Transaction $transaction, $user): void
    {
        try {
            $purchaseId = $transaction->purpose_id;
            $purchase = \App\Models\DigitalBookPurchase::with(['items.book.author'])
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

                // Update author wallet (only after successful payment)
                $author = $item->book->author;
                if ($author) {
                    $author->increment('wallet_balance', $item->author_payout_amount_usd);

                    // Create payout transaction record
                    \App\Models\Transaction::create([
                        'id' => \Illuminate\Support\Str::uuid(),
                        'user_id' => $author->id,
                        'reference' => uniqid('pay_immediate_'),
                        'status' => 'succeeded',
                        'currency' => $purchase->currency ?? 'NGN',
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
                $bookAnalytics = \App\Models\BookMetaDataAnalytics::firstOrCreate(
                    ['book_id' => $item->book_id],
                    ['purchases' => 0, 'views' => 0, 'downloads' => 0, 'favourites' => 0, 'bookmarks' => 0, 'reads' => 0, 'shares' => 0, 'likes' => 0]
                );
                $bookAnalytics->increment('purchases', 1);
                $bookAnalytics->save();
            }
        } catch (\Exception $e) {
            Log::error('Error processing successful digital book purchase: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Process successful order (mirrors Stripe webhook functionality)
     */
    protected function processSuccessfulOrder(Transaction $transaction, $user): void
    {
        try {
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
                    $bookAnalytics = \App\Models\BookMetaDataAnalytics::firstOrCreate(
                        ['book_id' => $item->book_id],
                        ['purchases' => 0, 'views' => 0, 'downloads' => 0, 'favourites' => 0, 'bookmarks' => 0, 'reads' => 0, 'shares' => 0, 'likes' => 0]
                    );
                    $bookAnalytics->increment('purchases', (int) $item->quantity);
                    $bookAnalytics->save();

                    // Immediately update author wallet balance
                    $author = $item->book->author;
                    if ($author && isset($item->author_payout_amount_usd)) {
                        $author->increment('wallet_balance', $item->author_payout_amount_usd);;

                        // Create immediate payout transaction record
                        \App\Models\Transaction::create([
                            'id' => \Illuminate\Support\Str::uuid(),
                            'user_id' => $author->id,
                            'reference' => uniqid('pay_immediate_'),
                            'status' => 'succeeded',
                            'currency' => $order->currency ?? 'NGN',
                            'amount' => $item->author_payout_amount,
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

            // Schedule delayed payout if needed
            if ($order->payout_status === 'pending') {
                $delayDays = 7;
                \App\Jobs\ProcessAuthorPayout::dispatch(purpose: 'order', purposeId: $order->id)
                    ->delay(now()->addDays($delayDays));
            }
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::error('Order record not found: ' . $e->getMessage());
            throw new \Exception('Order record not found: ' . $e->getMessage(), 404, $e);
        } catch (\Exception $e) {
            Log::error('Error processing successful order: ' . $e->getMessage());
            throw $e;
        }
    }
}
