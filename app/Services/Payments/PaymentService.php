<?php

namespace App\Services\Payments;

use App\Models\DigitalBookPurchaseItem;
use App\Models\OrderItem;
use App\Models\Transaction;
use App\Services\Stripe\StripeConnectService;
use App\Services\Paystack\PaystackService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Stripe\PaymentIntent;

class PaymentService
{
    protected $stripe;
    protected $paystack;

    use ApiResponse;

    public function __construct(
        StripeConnectService $stripe,
        PaystackService $paystack
    ) {
        $this->stripe = $stripe;
        $this->paystack = $paystack;
    }

    public function createPayment(array $data, $user): JsonResponse|Transaction
    {
        try {
            $data = json_decode(json_encode($data));
            $str = Str::of($data->purpose)->take(3);
            $reference = uniqid("$str".'_');

            // Determine payment provider based on currency
            $provider = $this->getPaymentProvider($data->currency ?? 'USD');

            if ($provider === 'paystack') {
                return $this->createPaystackPayment($data, $user, $reference);
            } else {
                return $this->createStripePayment($data, $user, $reference);
            }
        } catch (\Throwable $th) {
            return $this->error('An error occurred while creating the payment intent: ' . $th->getMessage(), 500, $th->getMessage(), $th);
        }
    }

    protected function createStripePayment($data, $user, $reference): JsonResponse|Transaction
    {

        $paymentIntentPayload = [
            'amount' => $this->convertToSubunit($data->amount, $data->currency),
            'currency' => $data->currency,
            'purpose' => $data->purpose,
            'reference' => $reference,
            'description' => $data->description ?? null,
            'purpose_id' => $data->purpose_id ?? 0,
        ];

        $responsePayload = $this->stripe->createPaymentIntent($paymentIntentPayload, $user);

        if ($responsePayload instanceof JsonResponse) {
            $responseData = $responsePayload->getData(true);
            $errorMessage = $responseData['error'] ?? 'Unknown error from payment service.';
            Log::error('Error creating stripe payment intent: ' . $errorMessage);

            return $this->error(
                'An error occurred while creating the payment intent.',
                500,
                config('app.debug') ? $errorMessage : null
            );
        } elseif ($responsePayload instanceof PaymentIntent) {
            // Save Payment record
            return Transaction::create([
                'id' => Str::uuid(),
                'user_id' => $user->id,
                'reference' => $reference,
                'payment_intent_id' => $responsePayload->id,
                'payment_client_secret' => $responsePayload->client_secret,
                'amount' => $data->amount,
                'currency' => $data->currency ?? 'usd',
                'payment_provider' => 'stripe',
                'description' => $data->description ?? null,
                'purpose_type' => $data->purpose,
                'purpose_id' => $data->purpose_id ?? null,
                'meta_data' => $data->meta_data ?? [], // Store as array, not JSON string
                'status' => 'pending',
                'type' => 'purchase',
                'direction' => 'debit',
            ]);
        } else {
            return $this->error(
                'An internal error occurred due to an unexpected service response.',
                500,
                config('app.debug') ? 'Stripe service returned an unhandled type.' : null
            );
        }
    }

    protected function createPaystackPayment($data, $user, $reference): JsonResponse|Transaction
    {
        try{
        // Create transaction record first, then initialize payment
        $transaction = Transaction::create([
            'id' => Str::uuid(),
            'user_id' => $user->id,
            'reference' => $reference,
            'amount' => $data->amount,
            'currency' => $data->currency ?? 'NGN',
            'payment_provider' => 'paystack',
            'description' => $data->description ?? null,
            'purpose_type' => $data->purpose,
            'purpose_id' => $data->purpose_id ?? null,
            'meta_data' => $data->meta_data ?? [], // Store as array, not JSON string
            'status' => 'pending',
            'type' => 'purchase',
            'direction' => 'debit',
        ]);

        // Initialize Paystack payment
        $paystackResponse = $this->paystack->initializePayment([
            'amount' => (float) $data->amount,
            'currency' => $data->currency ?? 'NGN',
            'reference' => $reference,
            'purpose' => $data->purpose,
            'purpose_id' => $data->purpose_id,
            'description' => $data->description ?? null,
        ], $user);

        if (!$paystackResponse['status']) {
            // Delete the transaction if Paystack initialization failed
            $transaction->delete();
            return $this->error(
                'Payment initialization failed (createPaystackPayment): ' . $paystackResponse['message'],
                500,
                $paystackResponse['message'] ?? 'Unknown Paystack error'
            );
        }

        // Update transaction with Paystack response data
        $transaction->update([
            'payment_intent_id' => $paystackResponse['data']['reference'] ?? $reference,
            'payment_client_secret' => $paystackResponse['data']['authorization_url'] ?? null,
            'meta_data' => array_merge(
                (array) ($data->meta_data ?? []),
                ['paystack_response' => $paystackResponse]
            ), // Store as array, not JSON string
        ]);

        return $transaction;
    } catch (\Throwable $th) {
        return $this->error('An error occurred while creating the Paystack payment: ' . $th->getMessage(), 500, $th->getMessage(), $th);
    }
}

    /**
     * Determine the appropriate payment provider based on currency
     */
    protected function getPaymentProvider(string $currency): string
    {
        $currency = strtoupper($currency);

        // African currencies prioritize Paystack
        $africanCurrencies = ['NGN', 'GHS', 'KES', 'ZAR'];
        if (in_array($currency, $africanCurrencies)) {
            return 'paystack';
        }

        // Default to Stripe for other currencies
        return 'stripe';
    }

    public function updatePaymentStatus(string $paymentIntentId, string $status)
    {
        $payment = Transaction::where('payment_intent_id', $paymentIntentId)->first();

        if ($payment) {
            $payment->update(['status' => $status]);
        }

        return $payment;
    }

    // Verify a transaction payload { payment_intent_id, or id of the payment } retrieve payment intent from stripe and confirm the status to update in the payment information in our db by the payment_intent_id or id of payment if provided
    public function verifyTransaction($payload)
    {
        try {
            $paymentIntentId = $payload['payment_intent_id'] ?? null;
            $paymentId = $payload['id'] ?? null;

            if (! $paymentIntentId && ! $paymentId) {
                return $this->error('Either payment_intent_id or id must be provided.', 400);
            }

            // Retrieve payment intent from Stripe
            if ($paymentIntentId) {
                $paymentIntent = $this->stripe->retrievePaymentIntent($paymentIntentId);
            } else {
                $payment = Transaction::find($paymentId);
                if (! $payment) {
                    return $this->error('Payment not found.', 400);
                }
                $paymentIntent = $this->stripe->retrievePaymentIntent($payment->payment_intent_id);
            }

            // dd($paymentIntent);

            if (isset($paymentIntent->error)) {
                return $this->error($paymentIntent->error, 400);
            }

            // Update payment status in the database
            $status = $paymentIntent->status;
            // $payment = Transaction::where('payment_intent_id', $paymentIntent->id)->first();

            // if ($payment) {
            //     $payment->update(['status' => $status]);
            // }

            return $this->success(['status' => $status], 'Transaction status '.$status, 200);
        } catch (\Throwable $th) {
            return $this->error('An error occurred while verifying the transaction.', 500, $th->getMessage(), $th);
        }
    }

    // store transaction without creating payment intent
    public function storeTransaction(array $data, $user)
    {
        try {
            $data = json_decode(json_encode($data));
            $str = Str::of($data->purpose)->take(3);
            $reference = uniqid("$str");

            // Save Payment record
            $payment = Transaction::create([
                // 'id' => Str::uuid(),
                'user_id' => $user->id,
                'reference' => $reference,
                'amount' => $data['amount'],
                'currency' => $data['currency'] ?? 'usd',
                'payment_provider' => 'stripe',
                'description' => $data['description'] ?? null,
                'purpose_type' => $data['purpose'],
                'purpose_id' => $data['purpose_id'] ?? null,
                'meta_data' => $data['meta_data'] ?? [], // Store as array, not JSON string
                'status' => $data['status'] ?? 'pending',
                'purchased_by' => $data['purchased_by'] ?? null,
                'payment_intent_id' => $data['payment_intent_id'] ?? null,
                // 'reference' => $reference,
                'type' => $data['type'] ?? 'purchase',
            ]);

            return $payment;
        } catch (\Throwable $th) {
            // throw $th;
            return $this->error('An error occurred while storing the transaction.', 500, $th->getMessage(), $th);
        }
    }

    // getTransactionQuery
    public function getTransactionQuery()
    {
        return Transaction::query()->with(['user', 'purpose' => function($query) {
            // Handle cases where the purpose relationship cannot be resolved
            $query->withDefault(function ($purpose, $parent) {
                // If the purpose record doesn't exist, set a default value
                $purpose->id = $parent->purpose_id;
                $purpose->exists = false;
            });
        }]);
    }

    // getTransactionById
    public function getTransactionById($id)
    {
        try {
            return Transaction::with(['user', 'purpose' => function($query) {
                // Handle cases where the purpose relationship cannot be resolved
                $query->withDefault(function ($purpose, $parent) {
                    // If the purpose record doesn't exist, set a default value
                    $purpose->id = $parent->purpose_id;
                    $purpose->exists = false;
                });
            }])->find($id);
        } catch (\Throwable $th) {
            return $this->error('An error occurred while retrieving the transaction.', 500, $th->getMessage(), $th);
        }
    }

    /**
     * Converts a currency amount from its major unit (e.g., dollars)
     * to its smallest subunit (e.g., cents).
     *
     * @param float|string $amount  The amount in the major currency unit (e.g., 10.50 for $10.50).
     * @param  string  $currency  The 3-letter ISO currency code (e.g., 'USD', 'EUR', 'JPY').
     * @return int The amount in the smallest currency subunit (e.g., 1050 for $10.50).
     *
     * @throws \InvalidArgumentException If the currency is not supported or amount is invalid.
     */
    public function convertToSubunit(float|string $amount, string $currency = 'usd'): int
    {
        if (! is_numeric($amount)) {
            throw new \InvalidArgumentException('Amount must be a numeric value.');
        }

        $currency = strtoupper($currency);

        // Currencies with zero decimal places (e.g., JPY, KRW) as per Stripe documentation
        $zeroDecimalCurrencies = [
            'BIF',
            'CLP',
            'DJF',
            'GNF',
            'JPY',
            'KMF',
            'KRW',
            'MGA',
            'PYG',
            'RWF',
            'UGX',
            'VND',
            'VUV',
            'XAF',
            'XOF',
            'XPF',
        ];

        if (in_array($currency, $zeroDecimalCurrencies)) {
            return (int) round($amount);
        }

        // For all other currencies (typically 2 decimal places), multiply by 100
        return (int) round($amount * 100);
    }

    /**
     * Converts a currency amount from its smallest subunit (e.g., cents)
     * back to its major unit (e.g., dollars).
     *
     * This is the inverse of convertToSubunit.
     *
     * @param int|float|string $amount The amount in the smallest subunit (e.g., 1050 for $10.50).
     * @param string $currency The 3-letter ISO currency code (e.g., 'USD', 'EUR', 'JPY').
     * @return float The amount in the major currency unit (e.g., 10.50 for 1050 cents).
     *
     * @throws \InvalidArgumentException If the amount is not numeric.
     */
    public function convertFromSubunit(int|float|string $amount, string $currency = 'usd'): float
    {
        if (! is_numeric($amount)) {
            throw new \InvalidArgumentException('Amount must be a numeric value.');
        }

        $currency = strtoupper($currency);

        // Currencies with zero decimal places (e.g., JPY, KRW) as per Stripe documentation
        $zeroDecimalCurrencies = [
            'BIF',
            'CLP',
            'DJF',
            'GNF',
            'JPY',
            'KMF',
            'KRW',
            'MGA',
            'PYG',
            'RWF',
            'UGX',
            'VND',
            'VUV',
            'XAF',
            'XOF',
            'XPF',
        ];

        // For zero-decimal currencies, the subunit equals the major unit
        if (in_array($currency, $zeroDecimalCurrencies)) {
            return (float) $amount;
        }

        // For typical 2-decimal currencies, divide by 100 and round to 2 decimals
        return round(((float) $amount) / 100, 2);
    }
}
