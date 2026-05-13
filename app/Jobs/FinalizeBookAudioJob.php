<?php

namespace App\Jobs;

use App\Models\Book;
use App\Models\User;
use App\Services\Notification\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class FinalizeBookAudioJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public array $backoff = [30, 60];

    public function __construct(
        protected Book $book,
        protected User $author
    ) {}

    public function handle(): void
    {
        // Read all chunk URLs from Redis — keyed by chunk index
        $chunks = Redis::hgetall("audio_chunks:{$this->book->id}");

        if (empty($chunks)) {
            Log::error("FinalizeBookAudioJob: no chunks in Redis for book {$this->book->id} — marking failed");
            $this->book->update(['audio_status' => 'failed']);

            return;
        }

        // Sort by index to guarantee segment order regardless of which chunk finished first
        ksort($chunks, SORT_NUMERIC);
        $segmentUrls = array_values($chunks);

        // Read chapter map written by GenerateBookAudioJob (if any)
        $chaptersJson = Redis::get("audio_chapters:{$this->book->id}");
        $audioChapters = $chaptersJson ? json_decode($chaptersJson, true) : null;

        $this->book->update([
            'audio_status'          => 'ready',
            'audio_url'             => $segmentUrls[0],
            'audio_sample_url'      => $segmentUrls[0],
            'audio_segments'        => $segmentUrls,
            'audio_duration'        => null,
            'elevenlabs_project_id' => null,
            'audio_chapters'        => $audioChapters,
        ]);

        Redis::del("audio_chunks:{$this->book->id}");
        if ($chaptersJson) {
            Redis::del("audio_chapters:{$this->book->id}");
        }

        Log::info("FinalizeBookAudioJob: book {$this->book->id} ready — ".count($segmentUrls).' segments');

        app(NotificationService::class)->send(
            $this->author,
            'Your audiobook is ready!',
            "\"{$this->book->title}\" has been converted to audio and is now available.",
            ['in-app', 'push']
        );
    }

    public function failed(\Throwable $exception): void
    {
        Log::error(
            "FinalizeBookAudioJob permanently failed for book {$this->book->id}: ".$exception->getMessage(),
            ['exception' => $exception]
        );

        if (in_array($this->book->audio_status, ['processing', 'pending'])) {
            $this->book->update(['audio_status' => 'failed']);
        }

        app(NotificationService::class)->send(
            $this->author,
            'Audio generation failed',
            "We could not generate audio for \"{$this->book->title}\". Please try again.",
            ['in-app', 'push']
        );
    }
}
