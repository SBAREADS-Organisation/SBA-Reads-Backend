<?php

namespace App\Jobs;

use App\Models\Book;
use App\Models\User;
use App\Services\Cloudinary\CloudinaryMediaUploadService;
use App\Services\ElevenLabs\ElevenLabsService;
use App\Services\Notification\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DownloadBookAudioJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // 20 retries × 2-minute delay = up to 40 minutes of polling
    public int $tries = 20;

    public int $timeout = 600;

    public function __construct(
        protected int $bookId,
        protected int $authorId,
        protected string $projectId,
        protected array $chapterIds
    ) {}

    public function handle(
        ElevenLabsService $elevenLabs,
        CloudinaryMediaUploadService $cloudinary,
        NotificationService $notifications
    ): void {
        $book   = Book::find($this->bookId);
        $author = User::find($this->authorId);

        if (! $book || ! $author) {
            Log::warning("DownloadBookAudioJob: book {$this->bookId} or author {$this->authorId} not found. Aborting.");
            return;
        }

        // Bail if audio was reset (e.g. author triggered re-generation)
        if (! in_array($book->audio_status, ['processing', 'pending'])) {
            Log::info("DownloadBookAudioJob: book {$this->bookId} audio_status is '{$book->audio_status}', skipping.");
            $this->cleanupProject($elevenLabs);
            return;
        }

        try {
            // Check each chapter's status
            $pendingCount = 0;
            $failedCount  = 0;

            foreach ($this->chapterIds as $chapterId) {
                $status = $elevenLabs->getChapterStatus($this->projectId, $chapterId);

                if ($status === 'failed') {
                    $failedCount++;
                } elseif (in_array($status, ['default', 'in_progress'])) {
                    $pendingCount++;
                }
            }

            // If any chapters are still generating, re-queue and check again in 2 minutes
            if ($pendingCount > 0) {
                Log::info("DownloadBookAudioJob: book {$this->bookId} — {$pendingCount} chapters still pending, re-queuing.");
                $this->release(120);
                return;
            }

            $allFailed = $failedCount === count($this->chapterIds);
            if ($allFailed) {
                throw new \RuntimeException("All {$failedCount} chapters failed to convert on ElevenLabs.");
            }

            // All done (some may have failed — we still download the successful ones)
            $segmentUrls = [];
            $totalWords  = 0;

            foreach ($this->chapterIds as $index => $chapterId) {
                $status = $elevenLabs->getChapterStatus($this->projectId, $chapterId);
                if ($status !== 'done') {
                    Log::warning("DownloadBookAudioJob: chapter {$chapterId} has status '{$status}', skipping download.");
                    continue;
                }

                $snapshots = $elevenLabs->getChapterSnapshots($this->projectId, $chapterId);
                if (empty($snapshots)) {
                    Log::warning("DownloadBookAudioJob: no snapshots for chapter {$chapterId}.");
                    continue;
                }

                $snapshotId  = $snapshots[0]['chapter_snapshot_id'] ?? $snapshots[0]['snapshot_id'] ?? null;
                if (! $snapshotId) {
                    Log::warning("DownloadBookAudioJob: could not determine snapshot ID for chapter {$chapterId}.");
                    continue;
                }

                try {
                    $audioBinary = $elevenLabs->downloadChapterAudio($this->projectId, $chapterId, $snapshotId);

                    $tempPath = tempnam(sys_get_temp_dir(), 'sbareads_chapter_').'.mp3';
                    file_put_contents($tempPath, $audioBinary);

                    $uploaded = $cloudinary->uploadFromPath(
                        $tempPath,
                        'book_audio',
                        'book_'.$this->bookId.'_ch_'.$index
                    );

                    @unlink($tempPath);

                    $segmentUrls[] = $uploaded['url'];

                    // Save first segment immediately as the audio sample
                    if (count($segmentUrls) === 1) {
                        $book->update(['audio_sample_url' => $uploaded['url']]);
                    }

                    // Rough word count estimate from snapshot duration if available
                    $durationSeconds = $snapshots[0]['duration_secs'] ?? 0;
                    $totalWords     += (int) ($durationSeconds / 60 * 150);

                } catch (\Throwable $e) {
                    Log::warning("DownloadBookAudioJob: failed to download chapter {$index} for book {$this->bookId}: ".$e->getMessage());
                }
            }

            if (empty($segmentUrls)) {
                throw new \RuntimeException('No audio segments could be downloaded for this book.');
            }

            $estimatedDuration = $totalWords > 0
                ? (int) (($totalWords / 150) * 60)
                : null;

            $book->update([
                'audio_status'          => 'ready',
                'audio_url'             => $segmentUrls[0],
                'audio_duration'        => $estimatedDuration,
                'audio_segments'        => $segmentUrls,
                'elevenlabs_project_id' => null,
            ]);

            Log::info("DownloadBookAudioJob: book {$this->bookId} complete — ".count($segmentUrls).' segments.');

            $this->cleanupProject($elevenLabs);

            $notifications->send(
                $author,
                'Your audiobook is ready!',
                "\"{$book->title}\" has been converted to audio and is now available.",
                ['in-app', 'push']
            );

        } catch (\Throwable $e) {
            Log::error("DownloadBookAudioJob failed for book {$this->bookId}: ".$e->getMessage());

            if ($this->attempts() >= $this->tries) {
                $book->update(['audio_status' => 'failed', 'elevenlabs_project_id' => null]);
                $this->cleanupProject($elevenLabs);

                $notifications->send(
                    $author,
                    'Audio generation failed',
                    "We could not generate audio for \"{$book->title}\". Please try again.",
                    ['in-app', 'push']
                );
            }

            throw $e;
        }
    }

    private function cleanupProject(ElevenLabsService $elevenLabs): void
    {
        try {
            $elevenLabs->deleteProject($this->projectId);
        } catch (\Throwable $e) {
            Log::warning("DownloadBookAudioJob: could not delete ElevenLabs project {$this->projectId}: ".$e->getMessage());
        }
    }
}
