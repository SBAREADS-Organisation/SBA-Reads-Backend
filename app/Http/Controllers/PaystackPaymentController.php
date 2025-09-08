<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\User;
use App\Services\Paystack\PaystackService;
use App\Services\Paystack\CurrencyConversionService;
use App\Services\Payments\PaymentProviderFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaystackPaymentController extends Controller
{
    protected $paystackService;
    protected $currencyService;

    public function __construct(
        PaystackService $paystackService,
        CurrencyConversionService $currencyService
    ) {
        $this->paystackService = $paystackService;
        $this->currencyService = $currencyService;
    }

    /**
     * Initialize Paystack payment
     */
    public function initializePayment(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'currency' => 'required|string|size:3',
            'purpose' => 'required|string',
            'purpose_id' => 'nullable|integer',
            'description' => 'nullable|string',
            'meta_data' => 'nullable|array',
        ]);

        $user = auth()->user();
        $amount = $request->amount;
        $currency = strtoupper($request->currency);
        $metaData = $request->meta_data ?? [];

        try {
            return DB::transaction(function () use ($user, $amount, $currency, $request, $metaData) {
                // Calculate naira equivalent if currency is USD
                $nairaAmount = $amount;
                if ($currency === 'USD') {
                    try {
                        $nairaAmount = $this->currencyService->convert($amount, 'USD', 'NGN');
                    } catch (\Exception $e) {
                        throw new \Exception('Unable to fetch current exchange rates. Please try again later.');
                    }
                }

                // Create transaction record
                $transaction = Transaction::create([
                    'user_id' => $user->id,
                    'amount' => $amount,
                    'currency' => $currency,
                    'amount_naira' => $nairaAmount,
                    'payment_vendor' => 'paystack',
                    'status' => 'pending',
                    'purpose' => $request->purpose,
                    'purpose_id' => $request->purpose_id,
                    'description' => $request->description,
                    'meta_data' => $metaData, // Store metadata
                ]);

                // Initialize Paystack payment
                $paystackResponse = $this->paystackService->initializePayment([
                    'amount' => $amount,
                    'currency' => $currency,
                    'reference' => 'paystack_' . $transaction->id . '_' . uniqid(),
                    'purpose' => $request->purpose,
                    'purpose_id' => $request->purpose_id,
                ], $user);

                if (!$paystackResponse['status']) {
                    throw new \Exception($paystackResponse['message'] ?? 'Payment initialization failed (in initializePayment try)');
                }

                // Update transaction with Paystack response data
                $transaction->update([
                    'meta_data' => array_merge(
                        (array) $metaData,
                        ['paystack_response' => $paystackResponse]
                    ), // Store as array, not JSON string
                ]);

                return response()->json([
                    'success' => true,
                    'data' => [
                        'transaction_id' => $transaction->id,
                        'authorization_url' => $paystackResponse['data']['authorization_url'],
                        'access_code' => $paystackResponse['data']['access_code'],
                        'reference' => $paystackResponse['data']['reference'],
                        'amount' => $amount,
                        'currency' => $currency,
                        'amount_naira' => $nairaAmount,
                    ]
                ]);
            });
        } catch (\Exception $e) {
            Log::error('Paystack payment initialization failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Payment initialization failed (in initializePayment catch): ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Handle Paystack webhook
     */
    public function handleWebhook(Request $request)
    {
        $payload = $request->all();
        $signature = $request->header('x-paystack-signature');

        // Verify webhook signature
        if (!$this->verifyWebhookSignature($request->getContent(), $signature)) {
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $webhookService = app(\App\Services\Paystack\PaystackWebhookService::class);
        $webhookService->handleWebhook($payload);

        return response()->json(['message' => 'Webhook processed']);
    }

    /**
     * Handle Paystack callback
     */
    public function handleCallback(Request $request)
    {
        $reference = $request->query('reference');

        if (!$reference) {
            return redirect()->route('payment.failed')->with('error', 'Invalid payment reference');
        }

        try {
            $verification = $this->paystackService->verifyPayment($reference);

            if (!$verification['status'] || $verification['data']['status'] !== 'success') {
                return redirect()->route('payment.failed')->with('error', 'Payment verification failed');
            }

            // Update transaction
            $transaction = Transaction::where('payment_vendor', 'paystack')
                ->where('status', 'pending')
                ->where('id', str_replace('paystack_', '', explode('_', $reference)[1]))
                ->first();

            if ($transaction) {
                $transaction->update([
                    'status' => 'succeeded',
                    'transaction_reference' => $reference,
                    'paid_at' => now(),
                ]);

                // Process the successful transaction using the same logic as the webhook
                $webhookService = app(\App\Services\Paystack\PaystackWebhookService::class);
                $webhookPayload = [
                    'event' => 'charge.success',
                    'data' => $verification['data']
                ];
                $webhookService->handleWebhook($webhookPayload);
            }

            return redirect()->route('payment.success')->with('success', 'Payment completed successfully');
        } catch (\Exception $e) {
            Log::error('Paystack callback error: ' . $e->getMessage());
            return redirect()->route('payment.failed')->with('error', 'Payment processing failed');
        }
    }

    /**
     * Verify webhook signature
     */
    protected function verifyWebhookSignature(string $payload, string $signature): bool
    {
        $secret = config('services.paystack.webhook_secret');
        if (!$secret) {
            return true; // Skip verification if no secret configured
        }

        $computedSignature = hash_hmac('sha512', $payload, $secret);
        return hash_equals($computedSignature, $signature);
    }

    /**
     * Get transaction details
     */
    public function getTransactionDetails($transactionId)
    {
        $transaction = Transaction::where('payment_vendor', 'paystack')
            ->where('user_id', auth()->id())
            ->findOrFail($transactionId);

        return response()->json([
            'success' => true,
            'data' => $transaction
        ]);
    }

    /**
     * Get Paystack balance (for withdrawals)
     */
    public function getBalance()
    {
        try {
            $balance = $this->paystackService->getBalance();

            return response()->json([
                'success' => true,
                'data' => $balance
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get balance'
            ], 500);
        }
    }

    /**
     * Initiate withdrawal to Nigerian bank account
     */
    public function initiateWithdrawal(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:100',
            'bank_code' => 'required|string',
            'account_number' => 'required|string|size:10',
            'account_name' => 'required|string',
        ]);

        $user = auth()->user();
        $amount = $request->amount;

        try {
            // Create transfer recipient
            $recipientData = [
                'type' => 'nuban',
                'name' => $request->account_name,
                'account_number' => $request->account_number,
                'bank_code' => $request->bank_code,
                'currency' => 'NGN',
            ];

            $recipient = $this->paystackService->createTransferRecipient($recipientData);

            if (!$recipient['status']) {
                throw new \Exception('Failed to create transfer recipient');
            }

            // Initiate transfer
            $transferData = [
                'source' => 'balance',
                'reason' => 'Withdrawal from SBA Reads',
                'amount' => $amount * 100, // Convert to kobo
                'recipient' => $recipient['data']['recipient_code'],
            ];

            $transfer = $this->paystackService->initiateTransfer($transferData);

            if (!$transfer['status']) {
                throw new \Exception($transfer['message'] ?? 'Transfer initiation failed');
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'transfer_code' => $transfer['data']['transfer_code'],
                    'reference' => $transfer['data']['reference'],
                    'amount' => $amount,
                    'currency' => 'NGN',
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Paystack withdrawal failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Withdrawal failed: ' . $e->getMessage()
            ], 500);
        }
    }
}
