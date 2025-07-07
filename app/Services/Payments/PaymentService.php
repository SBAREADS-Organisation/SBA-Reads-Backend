<?php

namespace App\Services\Payments;

use App\Models\Transaction;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Support\Str;
use Stripe\Stripe;
use Stripe\PaymentIntent;
use App\Traits\ApiResponse;

class PaymentService
{
    protected $stripe;
    use ApiResponse;
    public function __construct(StripeConnectService $stripe)
    {
        $this->stripe = $stripe;
    }

    public function createPayment(array $data, $user)
    {
        try {
            // dd($data, $user);
            $data = json_decode(json_encode($data));
            $str = Str::of($data->purpose)->take(3);
            $reference = uniqid("$str".'_');//strtoupper(Str::random(12));

            $paymentIntentPayload = [
                'amount' => $data->amount,
                'currency' => $data->currency,
                'purpose' => $data->purpose,
                'reference' => $reference,
                'description' => $data->description ?? null,
                'purpose_id' => $data->purpose_id ?? null,
            ];

            $paymentIntent = $this->stripe->createPaymentIntent($paymentIntentPayload, $user);

            // dd($paymentIntent);

            if (isset($paymentIntent->error)) {
                return response()->json([
                    'data' => null,
                    'code' => 400,
                    'message' => $paymentIntent->error,
                    'error' => $paymentIntent->error,
                ], 400);
            }

            // Save Payment record
            $payment = Transaction::create([
                'id' => Str::uuid(),
                'user_id' => $user->id,
                'reference' => $reference,
                'payment_intent_id' => $paymentIntent->id,
                'payment_client_secret' => $paymentIntent->client_secret,
                'amount' => $data->amount,
                'currency' => $data->currency ?? 'usd',
                'payment_provider' => 'stripe',
                'description' => $data->description ?? null,
                'purpose_type' => $data->purpose,
                'purpose_id' => $data->purpose_id ?? null,
                'meta_data' => json_encode($data->meta_data ?? []),
                'status' => 'pending',
                'type' => 'purchase',
                'direction' => 'debit',
                // 'meta_data' => json_encode($data['meta_data'] ?? []),
            ]);

            return [
                'client_secret' => $paymentIntent->client_secret,
                'payment' => $payment,
            ];
        } catch (\Throwable $th) {
            //throw $th;
            // dd($th);
            return $this->error('An error occurred while creating the payment intent.', 500, $th->getMessage(), $th);
        }
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

            if (!$paymentIntentId && !$paymentId) {
                return $this->error('Either payment_intent_id or id must be provided.', 400);
            }

            // Retrieve payment intent from Stripe
            if ($paymentIntentId) {
                $paymentIntent = $this->stripe->retrievePaymentIntent($paymentIntentId);
            } else {
                $payment = Transaction::find($paymentId);
                if (!$payment) {
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
                'meta_data' => json_encode($data['meta_data'] ?? []),
                'status' => $data['status'] ?? 'pending',
                'purchased_by' => $data['purchased_by'] ?? null,
                'payment_intent_id' => $data['payment_intent_id'] ?? null,
                // 'reference' => $reference,
                'type' => $data['type'] ?? 'purchase',
            ]);

            return $payment;
        } catch (\Throwable $th) {
            //throw $th;
            return $this->error('An error occurred while storing the transaction.', 500, $th->getMessage(), $th);
        }
    }

    // getTransactionQuery
    public function getTransactionQuery()
    {
        return Transaction::query()->with(['user', 'purpose']);
    }

    // getTransactionById
    public function getTransactionById($id)
    {
        try {
            return Transaction::with(['user', 'purpose'])->find($id);
        } catch (\Throwable $th) {
            return $this->error('An error occurred while retrieving the transaction.', 500, $th->getMessage(), $th);
        }
    }
}
