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

    public int $timeout = 1800;

    public function __construct(
        protected Book $book,
        protected User $author
    ) {}

    public function handle(
        ElevenLabsService $elevenLabs,
        CloudinaryMediaUploadService $cloudinary,
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

            // Step 3: Chunk text into ~2500-char segments
            $chunks  = $this->chunkText($text, 2500);
            $voiceId = $this->author->elevenlabs_voice_id;

            if (! $voiceId) {
                throw new \RuntimeException('Author has no cloned voice. Please upload a voice sample first.');
            }

            // Step 4: Generate audio for each chunk — skip failures rather than aborting the whole book
            $segmentUrls = [];
            $totalWords  = 0;
            $failed      = 0;

            foreach ($chunks as $index => $chunk) {
                try {
                    $audioBinary = $elevenLabs->generateSpeech($voiceId, $chunk);

                    $tempAudioPath = tempnam(sys_get_temp_dir(), 'sbareads_audio_').'.mp3';
                    file_put_contents($tempAudioPath, $audioBinary);

                    $uploaded = $cloudinary->uploadFromPath(
                        $tempAudioPath,
                        'book_audio',
                        'book_'.$this->book->id.'_seg_'.$index
                    );

                    @unlink($tempAudioPath);
                    $segmentUrls[] = $uploaded['url'];
                    $totalWords   += str_word_count($chunk);

                } catch (\Throwable $e) {
                    $failed++;
                    Log::warning("Audio chunk {$index} failed for book {$this->book->id}: ".$e->getMessage());
                    // Continue with remaining chunks — partial audio is better than no audio
                }
            }

            if (empty($segmentUrls)) {
                throw new \RuntimeException("All {$failed} audio chunks failed to generate.");
            }

            // Estimate duration: ~150 words/min average reading speed
            $estimatedDuration = (int) (($totalWords / 150) * 60);

            $this->book->update([
                'audio_status'   => 'ready',
                'audio_url'      => $segmentUrls[0],
                'audio_duration' => $estimatedDuration,
                'audio_segments' => $segmentUrls,
            ]);

            $segmentCount = count($segmentUrls);
            $skipped      = $failed > 0 ? " ({$failed} segments skipped due to errors)" : '';
            Log::info("Audio generation complete for book {$this->book->id}: {$segmentCount} segments, ~{$estimatedDuration}s{$skipped}");

            // Notify the author that their audiobook is ready
            $notifications->send(
                $this->author,
                'Your audiobook is ready!',
                "\"{$this->book->title}\" has been converted to audio and is now available.",
                ['in-app', 'push']
            );

        } catch (\Throwable $e) {
            Log::error("Audio generation failed for book {$this->book->id}: ".$e->getMessage());
            $this->book->update(['audio_status' => 'failed']);

            if ($this->attempts() >= $this->tries) {
                $notifications->send(
                    $this->author,
                    'Audio generation failed',
                    "We could not generate audio for \"{$this->book->title}\". Please try again.",
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
