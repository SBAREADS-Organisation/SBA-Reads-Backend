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
        $this->secretKey = (string) (config('services.paystack.secret') ?? '');
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
        string $reason = 'Author royalty payout',
        ?string $reference = null
    ): array {
        // Our own reference lets the payout be recorded before the money moves, and matched by the webhook.
        $response = Http::withToken($this->secretKey)
            ->post("{$this->baseUrl}/transfer", array_filter([
                'source'    => 'balance',
                'amount'    => $amountKobo,
                'recipient' => $recipientCode,
                'reason'    => $reason,
                'reference' => $reference,
            ]));

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
    /** The naira balance in the SBA Reads Paystack account (what payouts are paid from). */
    public function nairaBalance(): float
    {
        $response = Http::withToken($this->secretKey)->get("{$this->baseUrl}/balance");
        if (! $response->successful() || ! $response->json('status')) {
            throw new \RuntimeException('Could not read the Paystack balance: ' . ($response->json('message') ?? $response->status()));
        }
        $ngn = collect($response->json('data') ?? [])->firstWhere('currency', 'NGN');

        return round(((float) ($ngn['balance'] ?? 0)) / 100, 2);
    }

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
        // Every Nigerian bank (Paystack pages the list), kept for 12 hours. A failed fetch isn't cached.
        $banks = \Illuminate\Support\Facades\Cache::get('paystack_ngn_banks');
        if (is_array($banks) && $banks) {
            return $banks;
        }

        $banks = [];
        $next = null;
        for ($page = 0; $page < 10; $page++) {
            $response = Http::withToken($this->secretKey)->get("{$this->baseUrl}/bank", array_filter([
                'currency' => 'NGN', 'perPage' => 100, 'use_cursor' => 'true', 'next' => $next,
            ]));
            if (! $response->successful()) {
                break;
            }
            $banks = array_merge($banks, $response->json('data') ?? []);
            $next = $response->json('meta.next');
            if (! $next) {
                break;
            }
        }

        $banks = collect($banks)->unique('code')->sortBy(fn ($b) => strtolower($b['name'] ?? ''))->values()->all();
        if ($banks) {
            \Illuminate\Support\Facades\Cache::put('paystack_ngn_banks', $banks, now()->addHours(12));
        }

        return $banks;
    }
}
