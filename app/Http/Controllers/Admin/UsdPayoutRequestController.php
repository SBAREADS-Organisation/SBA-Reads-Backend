<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\Generic\GenericAppNotification;
use App\Models\PayoutRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class UsdPayoutRequestController extends Controller
{
    /**
     * List payout requests.
     * GET /admin/usd-payout-requests?status=pending   (default)
     * GET /admin/usd-payout-requests?status=all
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', 'pending');

        $requests = PayoutRequest::with('user:id,name,email,bank_name,paystack_recipient_code,kyc_account_id')
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->latest()
            ->get();

        $summary = [
            'total_pending_usd'    => round(PayoutRequest::where('status', 'pending')->sum('amount'), 2),
            'total_processing_usd' => round(PayoutRequest::where('status', 'processing')->sum('amount'), 2),
        ];

        return $this->success(
            ['requests' => $requests, 'summary' => $summary],
            'USD payout requests retrieved.'
        );
    }

    /**
     * Update the status of a payout request.
     * PUT /admin/usd-payout-requests/{payoutRequest}
     *
     * Body: { status: "processing"|"completed"|"rejected", admin_note?: string }
     */
    public function process(Request $request, PayoutRequest $payoutRequest): JsonResponse
    {
        $validated = $request->validate([
            'status'     => 'required|in:processing,completed,rejected',
            'admin_note' => 'nullable|string|max:1000',
        ]);

        $payoutRequest->update([
            'status'       => $validated['status'],
            'admin_note'   => $validated['admin_note'] ?? $payoutRequest->admin_note,
            'processed_by' => $request->user()->id,
            'processed_at' => in_array($validated['status'], ['completed', 'rejected']) ? now() : $payoutRequest->processed_at,
        ]);

        // Notify the author
        try {
            $author  = $payoutRequest->user;
            $status  = $validated['status'];
            $amount  = number_format((float) $payoutRequest->amount, 2);

            $subject = match ($status) {
                'processing' => 'Your USD Payout Request Is Being Processed',
                'completed'  => 'Your USD Payout of $' . $amount . ' Has Been Sent',
                'rejected'   => 'Your USD Payout Request Was Not Approved',
                default      => 'Update on Your Payout Request',
            };

            $note = $validated['admin_note'] ? "\n\nNote from SBA Reads: " . $validated['admin_note'] : '';

            $body = match ($status) {
                'processing' => "Hi {$author->name},\n\nYour USD payout request of \${$amount} is now being processed. You will receive another update once the funds have been sent.{$note}",
                'completed'  => "Hi {$author->name},\n\nGreat news! Your USD payout of \${$amount} has been sent. Please allow 1–5 business days for the funds to arrive depending on your bank.{$note}",
                'rejected'   => "Hi {$author->name},\n\nWe were unable to process your USD payout request of \${$amount} at this time.{$note}\n\nPlease contact support@sbareads.com if you have any questions.",
                default      => "Hi {$author->name},\n\nYour payout request status has been updated to: {$status}.{$note}",
            };

            Mail::to($author->email)->queue(new GenericAppNotification($subject, $body));
        } catch (\Throwable $e) {
            Log::warning('PayoutRequest: author notification failed: ' . $e->getMessage());
        }

        Log::info('Payout request updated by admin', [
            'request_id' => $payoutRequest->id,
            'status'     => $validated['status'],
            'admin_id'   => $request->user()->id,
        ]);

        return $this->success(
            ['request' => $payoutRequest->fresh(['user'])],
            'Payout request updated.'
        );
    }
}
