<?php

namespace App\Http\Controllers\KYC;

use App\Http\Controllers\Controller;
use App\Mail\Generic\GenericAppNotification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class AdminKYCController extends Controller
{
    /**
     * List authors that require manual admin KYC review.
     *
     * This is the AI fallback queue — only authors the AI could not confidently
     * approve. Authors the AI verified never appear here.
     *
     * Includes three sub-groups:
     *   - needs_review: AI ran and flagged (most common — admin action required)
     *   - pending: AI is currently processing
     *   - awaiting_ai: AI hasn't run yet (edge case — shouldn't happen normally)
     */
    public function pendingManual(Request $request): JsonResponse
    {
        $authors = User::where('account_type', 'author')
            ->where(function ($q) {
                $q->where('kyc_status', 'pending_manual')
                  ->orWhere(function ($q2) {
                      $q2->where('kyc_status', 'in-review')
                         ->whereHas('kycInfo', fn ($q3) => $q3->whereNotNull('document_url'));
                  });
            })
            ->with('kycInfo')
            ->orderByRaw("CASE ai_review_status WHEN 'needs_review' THEN 0 WHEN 'pending' THEN 1 ELSE 2 END")
            ->orderByDesc('ai_reviewed_at')
            ->paginate(20)
            ->through(fn ($author) => $this->formatAuthor($author));

        return $this->success($authors, 'AI-flagged KYC applications retrieved.');
    }

    /**
     * Approve a manual KYC submission — marks the author as verified.
     */
    public function approve(Request $request, User $user): JsonResponse
    {
        if ($user->account_type !== 'author') {
            return $this->error('User is not an author.', 422);
        }

        if (! in_array($user->kyc_status, ['pending_manual', 'in-review'])) {
            return $this->error("Cannot approve: current KYC status is '{$user->kyc_status}'.", 422);
        }

        $user->update([
            'kyc_status' => 'verified',
            'first_name' => $user->kycInfo?->first_name ?? $user->first_name,
            'last_name'  => $user->kycInfo?->last_name  ?? $user->last_name,
        ]);

        // Notify the author
        if ($user->email) {
            Mail::to($user->email)->queue(new GenericAppNotification(
                'SBA Reads — KYC Verified!',
                "Hi {$user->first_name},\n\nGreat news! Your identity verification has been approved. "
                . "You can now set up your payout method in the app and start receiving earnings.\n\n"
                . "Go to: Wallet → Payout Method to add your bank account.\n\n"
                . "— The SBA Reads Team"
            ));
        }

        return $this->success(['kyc_status' => 'verified'], 'Author KYC approved.');
    }

    /**
     * Reject a manual KYC submission with a reason.
     */
    public function reject(Request $request, User $user): JsonResponse
    {
        $request->validate(['reason' => 'nullable|string|max:500']);

        if ($user->account_type !== 'author') {
            return $this->error('User is not an author.', 422);
        }

        $user->update(['kyc_status' => 'rejected']);

        // Notify the author with the rejection reason
        if ($user->email) {
            $reasonMsg = $request->input('reason')
                ? "\n\nReason: {$request->input('reason')}"
                : '';
            Mail::to($user->email)->queue(new GenericAppNotification(
                'SBA Reads — KYC Verification Update',
                "Hi {$user->first_name},\n\nUnfortunately we were unable to verify your identity with the information provided.{$reasonMsg}\n\n"
                . "Please re-submit your KYC details in the app with accurate information and a valid ID document.\n\n"
                . "If you have questions, contact us at support@sbareads.com.\n\n"
                . "— The SBA Reads Team"
            ));
        }

        return $this->success(['kyc_status' => 'rejected'], 'Author KYC rejected.');
    }

    /**
     * List all verified manual-path authors (AI auto-approved + admin approved).
     * Excludes Stripe-only authors who have no kycInfo record.
     */
    public function approved(Request $request): JsonResponse
    {
        $authors = User::where('account_type', 'author')
            ->where('kyc_status', 'verified')
            ->whereHas('kycInfo')
            ->with('kycInfo')
            ->orderByDesc('ai_reviewed_at')
            ->paginate(20)
            ->through(fn ($author) => $this->formatAuthor($author));

        return $this->success($authors, 'Approved KYC submissions retrieved.');
    }

    private function formatAuthor(User $author): array
    {
        return [
            'id'                   => $author->id,
            'name'                 => $author->name,
            'email'                => $author->email,
            'created_at'           => $author->created_at,
            'kyc_status'           => $author->kyc_status,
            'has_document'         => ! empty($author->kycInfo?->document_url),
            'ai_review_status'     => $author->ai_review_status,
            'ai_review_notes'      => $author->ai_review_notes,
            'ai_review_confidence' => $author->ai_review_confidence,
            'ai_reviewed_at'       => $author->ai_reviewed_at,
            'kyc_info'             => [
                'first_name'           => $author->kycInfo?->first_name,
                'last_name'            => $author->kycInfo?->last_name,
                'dob'                  => $author->kycInfo?->dob,
                'phone'                => $author->kycInfo?->phone,
                'gender'               => $author->kycInfo?->gender,
                'address'              => $author->kycInfo?->address_line1,
                'city'                 => $author->kycInfo?->city,
                'state'                => $author->kycInfo?->state,
                'country'              => $author->kycInfo?->country,
                'document_type'        => $author->kycInfo?->document_type,
                'document_url'         => $author->kycInfo?->document_url,
                'document_uploaded_at' => $author->kycInfo?->document_uploaded_at,
            ],
        ];
    }
}
