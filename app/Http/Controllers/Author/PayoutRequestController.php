<?php

namespace App\Http\Controllers\Author;

use App\Http\Controllers\Controller;
use App\Mail\Generic\GenericAppNotification;
use App\Models\PayoutRequest;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PayoutRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $requests = PayoutRequest::where('user_id', $request->user()->id)
            ->latest()
            ->get(['id', 'amount', 'currency', 'status', 'admin_note', 'processed_at', 'created_at']);

        return $this->success(['requests' => $requests], 'Payout requests retrieved.');
    }

    public function store(Request $request): JsonResponse
    {
        $author = $request->user();

        $validated = $request->validate([
            'amount' => 'required|numeric|min:1',
        ]);

        $amount = (float) $validated['amount'];

        $hasActive = PayoutRequest::where('user_id', $author->id)
            ->whereIn('status', ['pending', 'processing'])
            ->exists();

        if ($hasActive) {
            return $this->error(
                'You already have a payout request in progress. Please wait for it to be processed before submitting a new one.',
                422
            );
        }

        // Exclude IAP earnings — those are handled by IAPPayoutController and already
        // converted + paid via Paystack. Including them would allow double-payment.
        $lifetimeUSD = (float) Transaction::where('user_id', $author->id)
            ->where('direction', 'credit')
            ->where('status', 'succeeded')
            ->whereIn('currency', ['USD', 'usd'])
            ->whereNotIn('payment_provider', ['apple', 'google_play'])
            ->sum('amount');

        $alreadyHandled = (float) PayoutRequest::where('user_id', $author->id)
            ->whereIn('status', ['pending', 'processing', 'completed'])
            ->sum('amount');

        $available = max(0.0, $lifetimeUSD - $alreadyHandled);

        if ($amount > $available) {
            return $this->error(
                'Amount exceeds your available USD balance of $' . number_format($available, 2) . '.',
                422
            );
        }

        $payoutRequest = PayoutRequest::create([
            'user_id'  => $author->id,
            'amount'   => $amount,
            'currency' => 'USD',
            'status'   => 'pending',
        ]);

        // Notify all admins by email
        try {
            $admins = User::role(['admin', 'superadmin', 'manager'])->get(['id', 'email', 'name']);
            foreach ($admins as $admin) {
                Mail::to($admin->email)->queue(new GenericAppNotification(
                    'New USD Payout Request — ' . $author->name,
                    "Author {$author->name} ({$author->email}) has requested a USD payout of \${$amount}.\n\n" .
                    "Request ID: {$payoutRequest->id}\n" .
                    "Available balance: \${$available}\n\n" .
                    "Log in to the admin panel to review and process this request."
                ));
            }
        } catch (\Throwable $e) {
            Log::warning('PayoutRequest: admin email failed: ' . $e->getMessage());
        }

        Log::info('Payout request submitted', [
            'user_id'  => $author->id,
            'amount'   => $amount,
            'id'       => $payoutRequest->id,
        ]);

        return $this->success(
            ['request' => $payoutRequest],
            'Payout request submitted. The SBA Reads team will process it within 2–5 business days.'
        );
    }
}
