<?php

namespace App\Services\Paystack;

use App\Models\Transaction;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

class PaystackService
{
    use ApiResponse;

    protected $baseUrl;
    protected $secretKey;

    public function __construct()
    {
        $this->baseUrl = config('services.paystack.base_url');
        $this->secretKey = config('services.paystack.secret');
    }

    /**
     * Initialize a Paystack payment
     */
    public function initializePayment(array $data, User $user): array
    {
        $payload = [
            'amount' => $data['amount'] * 100, // Convert to kobo
            'email' => $user->email,
            'currency' => $data['currency'] ?? 'NGN',
            'reference' => $data['reference'] ?? uniqid('paystack_'),
            'callback_url' => route('paystack.callback'),
            'metadata' => [
                'user_id' => $user->id,
                'purpose' => $data['purpose'] ?? 'purchase',
                'purpose_id' => $data['purpose_id'] ?? null,
            ]
        ];

        $response = Http::withToken($this->secretKey)
            ->post($this->baseUrl . '/transaction/initialize', $payload);

        return $response->json();
    }

    /**
     * Verify a Paystack payment
     */
    public function verifyPayment(string $reference): array
    {
        $response = Http::withToken($this->secretKey)
            ->get($this->baseUrl . '/transaction/verify/' . $reference);

        return $response->json();
    }

    /**
     * Get transaction details
     */
    public function getTransaction(string $transactionId): array
    {
        $response = Http::withToken($this->secretKey)
            ->get($this->baseUrl . '/transaction/' . $transactionId);

        return $response->json();
    }

    /**
     * Get customer details
     */
    public function getCustomer(string $customerCode): array
    {
        $response = Http::withToken($this->secretKey)
            ->get($this->baseUrl . '/customer/' . $customerCode);

        return $response->json();
    }

    /**
     * Create a customer
     */
    public function createCustomer(array $data): array
    {
        $response = Http::withToken($this->secretKey)
            ->post($this->baseUrl . '/customer', $data);

        return $response->json();
    }

    /**
     * Create a payment plan
     */
    public function createPlan(array $data): array
    {
        $response = Http::withToken($this->secretKey)
            ->post($this->baseUrl . '/plan', $data);

        return $response->json();
    }

    /**
     * Create a subscription
     */
    public function createSubscription(array $data): array
    {
        $response = Http::withToken($this->secretKey)
            ->post($this->baseUrl . '/subscription', $data);

        return $response->json();
    }

    /**
     * Get subscription details
     */
    public function getSubscription(string $subscriptionCode): array
    {
        $response = Http::withToken($this->secretKey)
            ->get($this->baseUrl . '/subscription/' . $subscriptionCode);

        return $response->json();
    }

    /**
     * Cancel a subscription
     */
    public function cancelSubscription(string $subscriptionCode): array
    {
        $response = Http::withToken($this->secretKey)
            ->delete($this->baseUrl . '/subscription/' . $subscriptionCode);

        return $response->json();
    }

    /**
     * Get bank list
     */
    public function getBankList(): array
    {
        $response = Http::withToken($this->secretKey)
            ->get($this->baseUrl . '/bank');

        return $response->json();
    }

    /**
     * Create a transfer recipient
     */
    public function createTransferRecipient(array $data): array
    {
        $response = Http::withToken($this->secretKey)
            ->post($this->baseUrl . '/transferrecipient', $data);

        return $response->json();
    }

    /**
     * Initiate a transfer
     */
    public function initiateTransfer(array $data): array
    {
        $response = Http::withToken($this->secretKey)
            ->post($this->baseUrl . '/transfer', $data);

        return $response->json();
    }

    /**
     * Finalize a transfer
     */
    public function finalizeTransfer(string $transferCode): array
    {
        $response = Http::withToken($this->secretKey)
            ->post($this->baseUrl . '/transfer/finalize', [
                'transfer_code' => $transferCode
            ]);

        return $response->json();
    }

    /**
     * Get transfer details
     */
    public function getTransfer(string $transferCode): array
    {
        $response = Http::withToken($this->secretKey)
            ->get($this->baseUrl . '/transfer/' . $transferCode);

        return $response->json();
    }

    /**
     * Get balance
     */
    public function getBalance(): array
    {
        $response = Http::withToken($this->secretKey)
            ->get($this->baseUrl . '/balance');

        return $response->json();
    }
}