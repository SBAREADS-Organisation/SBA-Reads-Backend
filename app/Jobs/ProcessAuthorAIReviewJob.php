<?php

namespace App\Jobs;

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

class ProcessAuthorAIReviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 60;

    public function __construct(public readonly int $userId) {}

    public function handle(): void
    {
        $user = User::with('kyc_info')->find($this->userId);

        if (! $user || $user->account_type !== 'author') {
            return;
        }

        // Only review authors that are still pending
        if (! in_array($user->status, ['pending', 'unverified'])) {
            return;
        }

        $service = new AIReviewService(new ClaudeService());
        $result  = $service->reviewAuthor($user);

        $aiStatus = $result['decision'] === 'verify' ? 'verified' : 'needs_review';

        $user->update([
            'ai_review_status'     => $aiStatus,
            'ai_review_notes'      => $result['reasons'],
            'ai_review_confidence' => $result['confidence'],
            'ai_reviewed_at'       => now(),
        ]);

        $threshold = AppSetting::float('ai_confidence_threshold', 0.85);

        if (
            $aiStatus === 'verified' &&
            $result['confidence'] >= $threshold &&
            AppSetting::bool('ai_auto_approve_authors')
        ) {
            $user->update(['status' => 'verified']);
            Log::info("AI auto-verified author {$user->id} (confidence {$result['confidence']})");
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error("ProcessAuthorAIReviewJob failed for user {$this->userId}: " . $e->getMessage());
        User::where('id', $this->userId)->whereNull('ai_review_status')->update([
            'ai_review_status' => 'needs_review',
            'ai_review_notes'  => 'AI review job failed — please review manually.',
            'ai_reviewed_at'   => now(),
        ]);
    }
}
