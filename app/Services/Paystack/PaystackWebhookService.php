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
            if ($paystackTransaction->transaction_id) {
                $transaction = Transaction::find($paystackTransaction->transaction_id);
                if ($transaction) {
                    $transaction->update([
                        'status' => 'completed',
                        'payment_method' => 'paystack',
                        'transaction_reference' => $data['reference'],
                    ]);
                }
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
}