<?php

namespace App\Jobs;

use App\Models\Book;
use App\Models\User;
use App\Services\Cloudinary\CloudinaryMediaUploadService;
use App\Services\ElevenLabs\ElevenLabsService;
use Cloudinary\Configuration\Configuration;
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

    public string $queue = 'audio';

    public function __construct(
        protected Book $book,
        protected User $author
    ) {}

    public function handle(ElevenLabsService $elevenLabs, CloudinaryMediaUploadService $cloudinary): void
    {
        $this->book->update(['audio_status' => 'processing']);

        try {
            // Step 1: Download PDF to a temp file
            $pdfUrl = $this->book->files[0]['public_url'] ?? null;
            if (! $pdfUrl) {
                throw new \RuntimeException('No PDF file found on this book');
            }

            $tempPdfPath = tempnam(sys_get_temp_dir(), 'sbareads_pdf_').'.pdf';
            file_put_contents($tempPdfPath, Http::timeout(120)->get($pdfUrl)->body());

            // Step 2: Extract plain text from PDF
            $parser = new Parser;
            $pdf = $parser->parseFile($tempPdfPath);
            $text = $pdf->getText();
            @unlink($tempPdfPath);

            $text = trim(preg_replace('/\s+/', ' ', $text));

            if (empty($text)) {
                throw new \RuntimeException('PDF text extraction returned empty content');
            }

            // Step 3: Chunk text into segments ElevenLabs can handle (~4000 chars each)
            $chunks = $this->chunkText($text, 4000);

            // Step 4: Generate audio for each chunk and upload to Cloudinary
            $voiceId = $this->author->elevenlabs_voice_id;
            if (! $voiceId) {
                throw new \RuntimeException('Author has no cloned voice. Please upload a voice sample first.');
            }

            $segmentUrls = [];
            $totalWords = 0;

            foreach ($chunks as $index => $chunk) {
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
                $totalWords += str_word_count($chunk);
            }

            // Estimate total duration: average reading speed ~150 words/min
            $estimatedDuration = (int) (($totalWords / 150) * 60);

            $this->book->update([
                'audio_status' => 'ready',
                'audio_url' => $segmentUrls[0],
                'audio_duration' => $estimatedDuration,
                'audio_segments' => $segmentUrls,
            ]);

            Log::info("Audio generation complete for book {$this->book->id}: ".count($segmentUrls).' segments, ~'.$estimatedDuration.'s');

        } catch (\Throwable $e) {
            Log::error("Audio generation failed for book {$this->book->id}: ".$e->getMessage());
            $this->book->update(['audio_status' => 'failed']);
            throw $e;
        }
    }

    /**
     * Split text into chunks at sentence boundaries, each under $maxChars.
     */
    private function chunkText(string $text, int $maxChars): array
    {
        $chunks = [];

        while (strlen($text) > 0) {
            if (strlen($text) <= $maxChars) {
                $chunks[] = trim($text);
                break;
            }

            $slice = substr($text, 0, $maxChars);

            // Prefer breaking at sentence end closest to the limit
            $breakPos = max(
                (int) strrpos($slice, '. '),
                (int) strrpos($slice, '! '),
                (int) strrpos($slice, '? '),
                (int) strrpos($slice, "\n")
            );

            // Fall back to hard cut if no good break found in the second half
            if ($breakPos < $maxChars / 2) {
                $breakPos = $maxChars;
            } else {
                $breakPos += 2; // include the punctuation and space
            }

            $chunks[] = trim(substr($text, 0, $breakPos));
            $text = substr($text, $breakPos);
        }

        return array_values(array_filter($chunks, fn ($c) => ! empty(trim($c))));
    }
}
