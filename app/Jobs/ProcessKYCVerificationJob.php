<?php

namespace App\Jobs;

use App\Mail\Generic\GenericAppNotification;
use App\Models\AppSetting;
use App\Models\User;
use App\Services\AI\AIReviewService;
use App\Services\AI\ClaudeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * AI-powered KYC verification for Nigerian and other non-Stripe-country authors.
 *
 * Flow:
 *   1. Author submits KYC → kyc_status = 'pending_manual' → this job dispatched
 *   2. AI evaluates name, DOB, address, phone, document presence
 *   3. High confidence → kyc_status = 'verified', author emailed
 *   4. Low confidence / manual_review decision → kyc_status stays 'pending_manual',
 *      ai_review_status = 'needs_review' → admin sees it in the manual queue
 *
 * Also re-dispatched after document upload so the AI can re-evaluate with the document signal.
 */
class ProcessKYCVerificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 90;

    public function __construct(public readonly int $userId) {}

    public function handle(): void
    {
        $user = User::with('kycInfo')->find($this->userId);

        if (! $user) {
            return;
        }

        // Only run on authors that are still awaiting manual review.
        // If already verified or rejected, skip silently.
        if ($user->kyc_status !== 'pending_manual') {
            return;
        }

        // Mark as AI-reviewing so the admin UI can show the in-progress state
        $user->update(['ai_review_status' => 'pending', 'ai_review_notes' => null]);

        $service = new AIReviewService(new ClaudeService());
        $result  = $service->reviewKYCApplication($user);

        $confidence = (float) $result['confidence'];
        $threshold  = AppSetting::float('ai_confidence_threshold', 0.85);
        $autoApprove = AppSetting::bool('ai_auto_approve_kyc', true); // default ON

        $flagsText = ! empty($result['flags'])
            ? "\n\nFlags: " . implode('; ', $result['flags'])
            : '';
        $notesText = $result['reasons'] . $flagsText;

        if (
            $result['decision'] === 'approve' &&
            $confidence >= $threshold &&
            $autoApprove
        ) {
            // Auto-approve: mark KYC verified and notify author
            $user->update([
                'kyc_status'           => 'verified',
                'ai_review_status'     => 'verified',
                'ai_review_notes'      => $notesText,
                'ai_review_confidence' => $confidence,
                'ai_reviewed_at'       => now(),
                'first_name'           => $user->kycInfo?->first_name ?? $user->first_name,
                'last_name'            => $user->kycInfo?->last_name  ?? $user->last_name,
            ]);

            Log::info("ProcessKYCVerificationJob: auto-approved KYC for user {$user->id} (confidence {$confidence})");

            if ($user->email) {
                try {
                    Mail::to($user->email)->queue(new GenericAppNotification(
                        'SBA Reads — Identity Verified!',
                        "Hi {$user->first_name},\n\n"
                        . "Great news! Your identity has been verified automatically. "
                        . "You can now set up your payout method and start receiving earnings from your books.\n\n"
                        . "Open the app → Wallet → Payout Method to add your bank account.\n\n"
                        . "— The SBA Reads Team"
                    ));
                } catch (\Throwable $e) {
                    Log::warning("KYC auto-approve email failed for user {$user->id}: " . $e->getMessage());
                }
            }
        } else {
            // Flag for admin manual review — AI wasn't confident enough
            $user->update([
                'ai_review_status'     => 'needs_review',
                'ai_review_notes'      => $notesText,
                'ai_review_confidence' => $confidence,
                'ai_reviewed_at'       => now(),
            ]);

            Log::info("ProcessKYCVerificationJob: flagged for manual review — user {$user->id}, decision={$result['decision']}, confidence={$confidence}");
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error("ProcessKYCVerificationJob failed for user {$this->userId}: " . $e->getMessage());

        // On job failure, make sure the user ends up in the manual queue
        User::where('id', $this->userId)
            ->where('kyc_status', 'pending_manual')
            ->update([
                'ai_review_status' => 'needs_review',
                'ai_review_notes'  => 'AI KYC review job failed — please review manually.',
                'ai_reviewed_at'   => now(),
            ]);
    }
}
