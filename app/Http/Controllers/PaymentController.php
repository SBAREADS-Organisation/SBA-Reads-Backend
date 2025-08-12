<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Transaction;

class PaymentController extends Controller
{
    public function checkPaymentStatus(Request $request)
    {
        $paymentIntentId = $request->input('payment_intent_id');
        $transactionId = $request->input('transaction_id');

        try {
            $transaction = null;

            if ($transactionId) {
                $transaction = Transaction::find($transactionId);
            } elseif ($paymentIntentId) {
                $transaction = Transaction::where('payment_intent_id', $paymentIntentId)->first();
            }

            if (!$transaction) {
                return response()->json(['error' => 'Transaction not found'], 404);
            }

            $isSuccessful = $transaction->checkStripeStatus();

            return response()->json([
                'success' => true,
                'payment_successful' => $isSuccessful,
                'transaction_status' => $transaction->fresh()->status,
                'message' => $isSuccessful ? 'Payment confirmed and processed' : 'Payment still pending'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to check payment status',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
