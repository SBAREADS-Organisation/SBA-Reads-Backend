<?php

namespace App\Jobs;

use App\Models\AppSetting;
use App\Models\Book;
use App\Services\AI\AIReviewService;
use App\Services\AI\ClaudeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessBookAIReviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 90;

    public function __construct(public readonly int $bookId) {}

    public function handle(): void
    {
        $book = Book::find($this->bookId);

        if (! $book || $book->status !== 'pending') {
            return; // Already processed or deleted
        }

        $service = new AIReviewService(new ClaudeService());
        $result  = $service->reviewBook($book);

        $aiStatus = match ($result['decision']) {
            'approve'      => 'approved',
            'decline'      => 'declined',
            default        => 'human_review',
        };

        $notes = $result['reasons'];
        if (! empty($result['concerns'])) {
            $notes .= "\nConcerns: " . implode('; ', $result['concerns']);
        }

        $book->update([
            'ai_review_status'     => $aiStatus,
            'ai_review_notes'      => $notes,
            'ai_review_confidence' => $result['confidence'],
            'ai_reviewed_at'       => now(),
        ]);

        // Auto-execute only when the admin has opted in AND confidence clears the threshold
        $threshold = AppSetting::float('ai_confidence_threshold', 0.85);

        if ($result['confidence'] >= $threshold) {
            if ($aiStatus === 'approved' && AppSetting::bool('ai_auto_approve_books')) {
                $book->update([
                    'status'      => 'approved',
                    'visibility'  => 'public',
                    'approved_at' => now(),
                ]);
                Log::info("AI auto-approved book {$book->id} (confidence {$result['confidence']})");
            }

            if ($aiStatus === 'declined' && AppSetting::bool('ai_auto_decline_books')) {
                $book->update([
                    'status'          => 'declined',
                    'rejection_note'  => $notes,
                ]);
                Log::info("AI auto-declined book {$book->id} (confidence {$result['confidence']})");
            }
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error("ProcessBookAIReviewJob failed for book {$this->bookId}: " . $e->getMessage());
        // Mark as needing human review so it doesn't silently disappear
        Book::where('id', $this->bookId)->whereNull('ai_review_status')->update([
            'ai_review_status' => 'human_review',
            'ai_review_notes'  => 'AI review job failed — please review manually.',
            'ai_reviewed_at'   => now(),
        ]);
    }
}
