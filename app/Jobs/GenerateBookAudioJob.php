<?php

namespace App\Jobs;

use App\Models\Book;
use App\Models\User;
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

    public int $timeout = 300;

    public function __construct(
        protected Book $book,
        protected User $author
    ) {}

    public function handle(
        ElevenLabsService $elevenLabs,
        NotificationService $notifications
    ): void {
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

            // Step 3: Create an ElevenLabs project for long-form audio
            $projectName = 'book-'.$this->book->id.'-'.time();
            Log::info("Creating ElevenLabs project for book {$this->book->id}, voice {$voiceId}");
            $projectId   = $elevenLabs->createProject($projectName, $voiceId);
            Log::info("ElevenLabs project created: {$projectId}");

            $this->book->update(['elevenlabs_project_id' => $projectId]);

            // Step 4: Split into 45,000-char chapters and kick off conversion
            $chapters   = $this->chunkText($text, 45000);
            $chapterIds = [];

            foreach ($chapters as $i => $chapterText) {
                Log::info("Adding chapter ".($i + 1)." (".strlen($chapterText)." chars) to project {$projectId}");
                $chapterId    = $elevenLabs->addChapter($projectId, 'Chapter '.($i + 1), $chapterText);
                $chapterIds[] = $chapterId;
                Log::info("Chapter ".($i + 1)." added: {$chapterId}, triggering conversion");
                $elevenLabs->convertChapter($projectId, $chapterId);
            }

            Log::info("ElevenLabs project {$projectId} created for book {$this->book->id} with ".count($chapterIds).' chapters.');

            // Step 5: Hand off to the polling job — checks every 2 minutes until all chapters are done
            DownloadBookAudioJob::dispatch($this->book->id, $this->author->id, $projectId, $chapterIds)
                ->onQueue('audio')
                ->delay(now()->addMinutes(2));

        } catch (\Throwable $e) {
            Log::error("Audio generation setup failed for book {$this->book->id}: ".$e->getMessage());
            $this->book->update(['audio_status' => 'failed']);

            if ($this->attempts() >= $this->tries) {
                $notifications->send(
                    $this->author,
                    'Audio generation failed',
                    "We could not start audio generation for \"{$this->book->title}\". Please try again.",
                    ['in-app', 'push']
                );
            }

            throw $e;
        }
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
