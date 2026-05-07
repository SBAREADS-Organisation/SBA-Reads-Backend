<?php

namespace App\Jobs;

use App\Services\Cloudinary\CloudinaryMediaUploadService;
use App\Services\ElevenLabs\ElevenLabsService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class GenerateAudioChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public array $backoff = [30, 60, 120];

    public function __construct(
        protected int $bookId,
        protected int $chunkIndex,
        protected string $chunkText,
        protected string $voiceId,
        protected int $totalChunks,
    ) {}

    public function handle(
        ElevenLabsService $elevenLabs,
        CloudinaryMediaUploadService $cloudinary
    ): void {
        if ($this->batch()?->cancelled()) {
            return;
        }

        Log::info("GenerateAudioChunkJob: book {$this->bookId} chunk ".($this->chunkIndex + 1)."/{$this->totalChunks}");

        $audioBinary = $elevenLabs->generateSpeech($this->voiceId, $this->chunkText);

        $tempPath = tempnam(sys_get_temp_dir(), 'sbareads_chunk_').'.mp3';
        file_put_contents($tempPath, $audioBinary);

        $uploaded = $cloudinary->uploadFromPath(
            $tempPath,
            'book_audio',
            'book_'.$this->bookId.'_chunk_'.$this->chunkIndex
        );
        @unlink($tempPath);

        // Store URL keyed by index so the finalizer can assemble in correct order
        Redis::hset("audio_chunks:{$this->bookId}", $this->chunkIndex, $uploaded['url']);
        Redis::expire("audio_chunks:{$this->bookId}", 86400); // 24-hour safety TTL

        Log::info("GenerateAudioChunkJob: book {$this->bookId} chunk ".($this->chunkIndex + 1)." done");
    }

    public function failed(\Throwable $exception): void
    {
        Log::error(
            "GenerateAudioChunkJob permanently failed — book {$this->bookId} chunk ".($this->chunkIndex + 1).": "
            .$exception->getMessage()
        );
    }
}
