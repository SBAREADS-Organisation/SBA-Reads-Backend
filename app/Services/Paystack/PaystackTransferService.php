<?php

namespace App\Services\Paystack;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaystackTransferService
{
    protected string $secretKey;
    protected string $baseUrl = 'https://api.paystack.co';

    public function __construct()
    {
        $this->secretKey = config('services.paystack.secret', '');
    }

    /**
     * Create a Paystack Transfer Recipient for a Nigerian bank account.
     * Store the returned recipient_code on the author's user record.
     *
     * @return string  The recipient code (e.g. "RCP_xxx")
     * @throws \RuntimeException on API failure
     */
    public function createRecipient(
        string $accountName,
        string $accountNumber,
        string $bankCode,
        string $currency = 'NGN'
    ): string {
        $response = Http::withToken($this->secretKey)
            ->post("{$this->baseUrl}/transferrecipient", [
                'type'           => 'nuban',
                'name'           => $accountName,
                'account_number' => $accountNumber,
                'bank_code'      => $bankCode,
                'currency'       => $currency,
            ]);

        if (! $response->successful() || ! $response->json('status')) {
            $message = $response->json('message') ?? 'Unknown Paystack error';
            Log::error("PaystackTransferService::createRecipient failed: {$message}", [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \RuntimeException("Failed to create Paystack recipient: {$message}");
        }

        return $response->json('data.recipient_code');
    }

    /**
     * Initiate a Paystack transfer to an existing recipient.
     *
     * @param  int    $amountKobo    Amount in the smallest currency unit (kobo for NGN)
     * @param  string $recipientCode Paystack recipient code (RCP_xxx)
     * @param  string $reason        Human-readable transfer reason
     * @return array  Paystack transfer data including transfer_code and status
     * @throws \RuntimeException on API failure
     */
    public function initiateTransfer(
        int    $amountKobo,
        string $recipientCode,
        string $reason = 'Author royalty payout'
    ): array {
        $response = Http::withToken($this->secretKey)
            ->post("{$this->baseUrl}/transfer", [
                'source'    => 'balance',
                'amount'    => $amountKobo,
                'recipient' => $recipientCode,
                'reason'    => $reason,
            ]);

        if (! $response->successful() || ! $response->json('status')) {
            $message = $response->json('message') ?? 'Unknown Paystack error';
            Log::error("PaystackTransferService::initiateTransfer failed: {$message}", [
                'recipient' => $recipientCode,
                'amount'    => $amountKobo,
                'body'      => $response->body(),
            ]);
            throw new \RuntimeException("Paystack transfer failed: {$message}");
        }

        return $response->json('data');
    }

    /**
     * Verify a transfer status by transfer code.
     */
    public function verifyTransfer(string $transferCode): array
    {
        $response = Http::withToken($this->secretKey)
            ->get("{$this->baseUrl}/transfer/{$transferCode}");

        if (! $response->successful()) {
            throw new \RuntimeException('Failed to verify Paystack transfer: ' . $response->json('message'));
        }

        return $response->json('data');
    }

    /**
     * Resolve the account name for a Nigerian bank account number.
     * Calls Paystack's /bank/resolve endpoint — used to auto-fill the account
     * name on the registration form so authors don't type it manually.
     *
     * @throws \RuntimeException when Paystack cannot resolve the account
     */
    public function resolveAccount(string $accountNumber, string $bankCode): string
    {
        $response = Http::withToken($this->secretKey)
            ->get("{$this->baseUrl}/bank/resolve", [
                'account_number' => $accountNumber,
                'bank_code'      => $bankCode,
            ]);

        if (! $response->successful() || ! $response->json('status')) {
            $message = $response->json('message') ?? 'Could not resolve account. Check the number and bank.';
            Log::warning("PaystackTransferService::resolveAccount failed: {$message}", [
                'account_number' => $accountNumber,
                'bank_code'      => $bankCode,
                'status'         => $response->status(),
            ]);
            throw new \RuntimeException($message);
        }

        return $response->json('data.account_name') ?? '';
    }

    /**
     * List available Nigerian banks for account registration UI.
     */
    public function listBanks(): array
    {
        $response = Http::withToken($this->secretKey)
            ->get("{$this->baseUrl}/bank", ['currency' => 'NGN', 'per_page' => 100]);

        if (! $response->successful()) {
            return [];
        }

        return $response->json('data') ?? [];
    }
}
