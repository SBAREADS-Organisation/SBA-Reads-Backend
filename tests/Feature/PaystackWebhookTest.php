<?php

namespace Tests\Feature;

use App\Models\DigitalBookPurchase;
use App\Models\PaystackTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaystackWebhookTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_processes_successful_charge_webhook()
    {
        // Create a user and transaction
        $user = User::factory()->create(['email' => 'test@example.com']);
        $transaction = Transaction::factory()->create([
            'user_id' => $user->id,
            'reference' => 'txn_123_abc',
            'status' => 'pending',
            'payment_vendor' => 'paystack',
            'purpose_type' => 'digital_book_purchase',
        ]);

        // Mock webhook payload
        $payload = [
            'event' => 'charge.success',
            'data' => [
                'id' => 1234567890,
                'reference' => 'paystack_' . $transaction->id . '_abc123',
                'amount' => 250000, // 2500 NGN in kobo
                'currency' => 'NGN',
                'status' => 'success',
                'paid_at' => '2025-01-15T10:30:00.000Z',
                'customer' => [
                    'email' => 'test@example.com',
                    'customer_code' => 'CUS_xxxxx'
                ],
                'channel' => 'card',
                'gateway_response' => 'Successful',
                'fees' => 3750 // Fees in kobo
            ]
        ];

        // Generate valid signature
        $secret = config('services.paystack.webhook_secret', 'test_secret');
        $signature = hash_hmac('sha512', json_encode($payload), $secret);

        // Send webhook request
        $response = $this->postJson('/api/paystack/webhook', $payload, [
            'x-paystack-signature' => $signature,
        ]);

        // Assert webhook processed successfully
        $response->assertStatus(200);
        $response->assertJson(['message' => 'Webhook processed']);

        // Assert transaction updated
        $transaction->refresh();
        $this->assertEquals('succeeded', $transaction->status);

        // Assert PaystackTransaction created
        $this->assertDatabaseHas('paystack_transactions', [
            'reference' => $payload['data']['reference'],
            'transaction_id' => $payload['data']['id'],
            'amount' => 2500.00, // Converted from kobo
            'currency' => 'NGN',
            'status' => 'success',
        ]);

        // Assert webhook event logged
        $this->assertDatabaseHas('webhook_events', [
            'service' => 'paystack',
            'event_type' => 'charge.success',
            'processed' => true,
        ]);
    }

    /** @test */
    public function it_rejects_webhook_with_invalid_signature()
    {
        $payload = [
            'event' => 'charge.success',
            'data' => ['reference' => 'test_ref']
        ];

        $response = $this->postJson('/api/paystack/webhook', $payload, [
            'x-paystack-signature' => 'invalid_signature',
        ]);

        $response->assertStatus(401);
        $response->assertJson(['message' => 'Invalid signature']);
    }

    /** @test */
    public function it_handles_transfer_success_webhook()
    {
        // Create a withdrawal record
        $user = User::factory()->create();
        $withdrawal = \App\Models\Withdrawal::create([
            'user_id' => $user->id,
            'amount' => 1000,
            'currency' => 'NGN',
            'reference' => 'withdrawal_123',
            'status' => 'pending',
            'provider' => 'paystack',
        ]);

        $payload = [
            'event' => 'transfer.success',
            'data' => [
                'reference' => 'withdrawal_123',
                'transfer_code' => 'TRF_xxxxx',
                'amount' => 100000,
                'currency' => 'NGN',
                'status' => 'success'
            ]
        ];

        $secret = config('services.paystack.webhook_secret', 'test_secret');
        $signature = hash_hmac('sha512', json_encode($payload), $secret);

        $response = $this->postJson('/api/paystack/webhook', $payload, [
            'x-paystack-signature' => $signature,
        ]);

        $response->assertStatus(200);

        // Assert withdrawal updated
        $withdrawal->refresh();
        $this->assertEquals('completed', $withdrawal->status);
        $this->assertNotNull($withdrawal->completed_at);
    }

    /** @test */
    public function it_handles_transfer_failed_webhook()
    {
        // Create a withdrawal record and related transaction
        $user = User::factory()->create(['wallet_balance' => 0]);
        $withdrawal = \App\Models\Withdrawal::create([
            'user_id' => $user->id,
            'amount' => 1000,
            'currency' => 'NGN',
            'reference' => 'withdrawal_456',
            'status' => 'pending',
            'provider' => 'paystack',
        ]);

        $payload = [
            'event' => 'transfer.failed',
            'data' => [
                'reference' => 'withdrawal_456',
                'message' => 'Insufficient balance',
                'status' => 'failed'
            ]
        ];

        $secret = config('services.paystack.webhook_secret', 'test_secret');
        $signature = hash_hmac('sha512', json_encode($payload), $secret);

        $response = $this->postJson('/api/paystack/webhook', $payload, [
            'x-paystack-signature' => $signature,
        ]);

        $response->assertStatus(200);

        // Assert withdrawal updated
        $withdrawal->refresh();
        $this->assertEquals('failed', $withdrawal->status);
        $this->assertEquals('Insufficient balance', $withdrawal->failure_reason);

        // Assert user wallet refunded
        $user->refresh();
        $this->assertEquals(1000, $user->wallet_balance);
    }

    /** @test */
    public function it_handles_subscription_create_webhook()
    {
        $user = User::factory()->create(['email' => 'subscriber@example.com']);

        $payload = [
            'event' => 'subscription.create',
            'data' => [
                'subscription_code' => 'SUB_xxxxx',
                'customer' => [
                    'email' => 'subscriber@example.com',
                ],
                'plan' => [
                    'name' => 'Monthly Plan'
                ]
            ]
        ];

        $secret = config('services.paystack.webhook_secret', 'test_secret');
        $signature = hash_hmac('sha512', json_encode($payload), $secret);

        $response = $this->postJson('/api/paystack/webhook', $payload, [
            'x-paystack-signature' => $signature,
        ]);

        $response->assertStatus(200);

        // Note: This would require UserSubscription model and proper setup
        // The test verifies the webhook processes without error
    }

    /** @test */
    public function it_skips_verification_when_no_secret_configured()
    {
        config(['services.paystack.webhook_secret' => null]);

        $payload = [
            'event' => 'charge.success',
            'data' => ['reference' => 'test_ref']
        ];

        $response = $this->postJson('/api/paystack/webhook', $payload);

        // Should process successfully without signature verification
        $response->assertStatus(200);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Set default webhook secret for testing
        config(['services.paystack.webhook_secret' => 'test_secret']);
    }
}
