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
        return DB::transaction(function () use ($data) {
            // Create or update Paystack transaction
            $paystackTransaction = PaystackTransaction::updateOrCreate(
                ['reference' => $data['reference']],
                [
                    'transaction_id' => $data['id'],
                    'amount' => $data['amount'] / 100, // Convert from kobo
                    'currency' => $data['currency'],
                    'status' => 'success',
                    'paid_at' => $data['paid_at'] ?? now(),
                    'channel' => $data['channel'] ?? null,
                    'gateway_response' => $data['gateway_response'] ?? null,
                    'customer_code' => $data['customer']['customer_code'] ?? null,
                    'customer_email' => $data['customer']['email'] ?? null,
                    'fees' => $data['fees'] / 100 ?? 0,
                    'raw_data' => $data,
                ]
            );

            // Update related transaction if exists
            $transaction = Transaction::where('reference', 'LIKE', '%' . $data['reference'] . '%')
                ->orWhere('payment_intent_id', $data['reference'])
                ->first();
                
            if ($transaction) {
                $transaction->update([
                    'status' => 'succeeded',
                    'payment_method' => 'paystack',
                    'transaction_reference' => $data['reference'],
                ]);
                
                // Process the successful transaction based on purpose
                $this->processSuccessfulTransaction($transaction, $data);
            }

            // Create payment audit
            PaymentAudit::create([
                'transaction_id' => $paystackTransaction->transaction_id,
                'payment_method' => 'paystack',
                'amount' => $paystackTransaction->amount,
                'currency' => $paystackTransaction->currency,
                'status' => 'success',
                'metadata' => $data,
            ]);

            // Create webhook event
            WebhookEvent::create([
                'service' => 'paystack',
                'event_type' => 'charge.success',
                'payload' => $data,
                'processed' => true,
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
            // Update withdrawal or payout transaction
            // Implementation depends on your withdrawal system
            Log::info('Paystack transfer success handled', $data);

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
                    $author->increment('wallet_balance', $item->author_payout_amount);

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
     * Process successful order (placeholder for future implementation)
     */
    protected function processSuccessfulOrder(Transaction $transaction, $user): void
    {
        try {
            // TODO: Implement order processing for Paystack
            // This would be similar to the Stripe webhook order processing
            Log::info("Order processing for Paystack not yet implemented. Transaction: {$transaction->id}");
        } catch (\Exception $e) {
            Log::error('Error processing successful order: ' . $e->getMessage());
            throw $e;
        }
    }
}