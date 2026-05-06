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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser;

class GenerateBookAudioJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    public array $backoff = [60, 120];

    public function __construct(
        protected Book $book,
        protected User $author
    ) {}

    public function handle(
        ElevenLabsService $elevenLabs,
        CloudinaryMediaUploadService $cloudinary
    ): void {
        ini_set('memory_limit', '512M');

        $this->book->update(['audio_status' => 'processing']);

        try {
            // Step 1: Download PDF to a temp file
            $pdfUrl = $this->book->files[0]['public_url'] ?? null;
            if (! $pdfUrl) {
                throw new \RuntimeException('No PDF file found on this book.');
            }

            $tempPdfPath = tempnam(sys_get_temp_dir(), 'sbareads_pdf_').'.pdf';
            file_put_contents($tempPdfPath, Http::timeout(120)->get($pdfUrl)->body());

            // Step 2: Extract plain text from PDF
            $parser = new Parser;
            $text   = trim(preg_replace('/\s+/', ' ', $parser->parseFile($tempPdfPath)->getText()));
            @unlink($tempPdfPath);

            if (empty($text)) {
                throw new \RuntimeException('PDF text extraction returned empty content. The PDF may be image-only.');
            }

            $voiceId = $this->author->elevenlabs_voice_id;
            if (! $voiceId) {
                throw new \RuntimeException('Author has no cloned voice. Please upload a voice sample first.');
            }

            // Step 3: Split into 4,500-char chunks and synthesise each with the TTS API
            $chunks      = $this->chunkText($text, 4500);
            $segmentUrls = [];

            Log::info("GenerateBookAudioJob: book {$this->book->id} — ".count($chunks)." chunks, voice {$voiceId}");

            foreach ($chunks as $i => $chunk) {
                Log::info("GenerateBookAudioJob: synthesising chunk ".($i + 1)."/".count($chunks)." for book {$this->book->id}");

                $audioBinary = $elevenLabs->generateSpeech($voiceId, $chunk);

                $tempPath = tempnam(sys_get_temp_dir(), 'sbareads_chunk_').'.mp3';
                file_put_contents($tempPath, $audioBinary);

                $uploaded = $cloudinary->uploadFromPath(
                    $tempPath,
                    'book_audio',
                    'book_'.$this->book->id.'_chunk_'.$i
                );
                @unlink($tempPath);

                $segmentUrls[] = $uploaded['url'];

                // Persist the first segment immediately so the sample is available early
                if (count($segmentUrls) === 1) {
                    $this->book->update(['audio_sample_url' => $uploaded['url']]);
                }
            }

            if (empty($segmentUrls)) {
                throw new \RuntimeException('No audio segments were generated for this book.');
            }

            $this->book->update([
                'audio_status'          => 'ready',
                'audio_url'             => $segmentUrls[0],
                'audio_segments'        => $segmentUrls,
                'audio_duration'        => null,
                'elevenlabs_project_id' => null,
            ]);

            Log::info("GenerateBookAudioJob: book {$this->book->id} complete — ".count($segmentUrls).' segments');

            app(NotificationService::class)->send(
                $this->author,
                'Your audiobook is ready!',
                "\"{$this->book->title}\" has been converted to audio and is now available.",
                ['in-app', 'push']
            );

        } catch (\Throwable $e) {
            Log::error(
                "GenerateBookAudioJob failed for book {$this->book->id} (attempt {$this->attempts()}/{$this->tries}): "
                .$e->getMessage(),
                ['exception' => $e]
            );

            if (str_starts_with($e->getMessage(), 'ELEVENLABS_RATE_LIMITED')) {
                Log::warning("GenerateBookAudioJob: rate-limited — releasing for 5 minutes.");
                $this->release(300);

                return;
            }

            throw $e;
        }
    }

    /**
     * Called by Laravel when the job permanently fails (retries exhausted or hard timeout).
     * Single place that marks the book failed and notifies the author.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error(
            "GenerateBookAudioJob permanently failed for book {$this->book->id}: ".$exception->getMessage(),
            ['exception' => $exception]
        );

        if (in_array($this->book->audio_status, ['processing', 'pending'])) {
            $this->book->update(['audio_status' => 'failed', 'elevenlabs_project_id' => null]);
        }

        app(NotificationService::class)->send(
            $this->author,
            'Audio generation failed',
            "We could not generate audio for \"{$this->book->title}\". Please try again.",
            ['in-app', 'push']
        );
    }

    private function chunkText(string $text, int $maxChars): array
    {
        $chunks = [];

        while (strlen($text) > 0) {
            if (strlen($text) <= $maxChars) {
                $chunks[] = trim($text);
                break;
            }

            $slice    = substr($text, 0, $maxChars);
            $breakPos = max(
                (int) strrpos($slice, '. '),
                (int) strrpos($slice, '! '),
                (int) strrpos($slice, '? '),
                (int) strrpos($slice, "\n")
            );

            if ($breakPos < $maxChars / 2) {
                $breakPos = $maxChars;
            } else {
                $breakPos += 2;
            }

            $chunks[] = trim(substr($text, 0, $breakPos));
            $text     = substr($text, $breakPos);
        }

        return array_values(array_filter($chunks, fn ($c) => ! empty(trim($c))));
    }
}
