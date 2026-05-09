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
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser;

class GenerateBookAudioJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300; // 5 min — PDF download + text extraction + batch dispatch

    public array $backoff = [60, 120];

    public function __construct(
        protected Book $book,
        protected User $author
    ) {}

    public function handle(): void
    {
        ini_set('memory_limit', '512M');

        // Prevent duplicate coordinators from dispatching parallel batches
        $lock = Cache::lock("audio_job_book_{$this->book->id}", 360);
        if (! $lock->get()) {
            Log::warning("GenerateBookAudioJob: duplicate detected for book {$this->book->id} — aborting.");

            return;
        }

        try {
            $this->book->update(['audio_status' => 'processing']);

            // Step 1: Download PDF
            $pdfUrl = $this->book->files[0]['public_url'] ?? null;
            if (! $pdfUrl) {
                throw new \RuntimeException('No PDF file found on this book.');
            }

            $tempPdfPath = tempnam(sys_get_temp_dir(), 'sbareads_pdf_').'.pdf';
            file_put_contents($tempPdfPath, Http::timeout(120)->get($pdfUrl)->body());

            // Step 2: Extract text — try smalot/pdfparser first, fall back to pdftotext
            $parser = new Parser;
            $text   = trim(preg_replace('/\s+/', ' ', $parser->parseFile($tempPdfPath)->getText()));

            if (empty($text)) {
                // pdftotext (poppler-utils) handles more PDF types including those with embedded fonts
                Log::info("GenerateBookAudioJob: smalot returned empty for book {$this->book->id} — trying pdftotext fallback");
                $text = $this->extractWithPdftotext($tempPdfPath);
            }

            @unlink($tempPdfPath);

            if (empty($text)) {
                throw new \RuntimeException(
                    'This PDF appears to be image-based (scanned). Please upload a text-based PDF so audio can be generated.'
                );
            }

            // Cache extracted text on the book so AI features can reuse it without re-downloading the PDF
            if (empty($this->book->text_content)) {
                $this->book->updateQuietly(['text_content' => $text]);
            }

            $voiceId = $this->author->elevenlabs_voice_id;
            if (! $voiceId) {
                throw new \RuntimeException('Author has no cloned voice. Please upload a voice sample first.');
            }

            // Step 3: Split into chunks and dispatch all as a parallel batch
            $chunks      = $this->chunkText($text, 4500);
            $totalChunks = count($chunks);
            $book        = $this->book;
            $author      = $this->author;

            Log::info("GenerateBookAudioJob: book {$book->id} — {$totalChunks} chunks dispatching in parallel");

            $chunkJobs = [];
            foreach ($chunks as $i => $chunk) {
                $chunkJobs[] = (new GenerateAudioChunkJob($book->id, $i, $chunk, $voiceId, $totalChunks))
                    ->onQueue('audio-chunks');
            }

            Bus::batch($chunkJobs)
                ->name("audio-book-{$book->id}")
                ->then(function () use ($book, $author) {
                    FinalizeBookAudioJob::dispatch($book, $author)->onQueue('audio');
                })
                ->catch(function (\Illuminate\Bus\Batch $batch, \Throwable $e) use ($book, $author) {
                    Log::error("GenerateBookAudioJob: batch failed for book {$book->id} — ".$e->getMessage());
                    $book->update(['audio_status' => 'failed']);
                    app(NotificationService::class)->send(
                        $author,
                        'Audio generation failed',
                        "We could not generate audio for \"{$book->title}\". Please try again.",
                        ['in-app', 'push']
                    );
                })
                ->onQueue('audio-chunks')
                ->dispatch();

        } catch (\Throwable $e) {
            Log::error(
                "GenerateBookAudioJob failed for book {$this->book->id} (attempt {$this->attempts()}/{$this->tries}): "
                .$e->getMessage(),
                ['exception' => $e]
            );
            $this->book->update(['audio_status' => 'failed']);
            throw $e;
        } finally {
            $lock->release();
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error(
            "GenerateBookAudioJob permanently failed for book {$this->book->id}: ".$exception->getMessage(),
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

    private function extractWithPdftotext(string $pdfPath): string
    {
        if (! shell_exec('which pdftotext')) {
            Log::warning('GenerateBookAudioJob: pdftotext not installed — skipping fallback');
            return '';
        }

        $escaped = escapeshellarg($pdfPath);
        $output  = shell_exec("pdftotext -layout {$escaped} - 2>/dev/null");

        return trim(preg_replace('/\s+/', ' ', $output ?? ''));
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
