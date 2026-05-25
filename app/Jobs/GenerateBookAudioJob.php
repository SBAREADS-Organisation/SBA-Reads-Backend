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
use Illuminate\Support\Facades\Redis;
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

            // Step 2: Extract text — try smalot/pdfparser per-page first (gives us exact
            // page numbers for chapter mapping), fall back to document-level getText() if
            // pages are empty (some PDFs lack a proper page tree), then pdftotext last.
            $parser    = new Parser;
            $pdfObj    = $parser->parseFile($tempPdfPath);
            $pageParts = array_map(fn ($p) => $p->getText(), $pdfObj->getPages());
            $rawText   = implode("\n", $pageParts);

            if (empty(trim($rawText))) {
                // Some PDFs expose text via getText() even when per-page fails
                $rawText   = $pdfObj->getText();
                $pageParts = [];
            }

            if (empty(trim($rawText))) {
                // pdftotext (poppler-utils) handles more PDF types including those with embedded fonts
                Log::info("GenerateBookAudioJob: smalot returned empty for book {$this->book->id} — trying pdftotext fallback");
                $rawText   = $this->extractWithPdftotext($tempPdfPath);
                $pageParts = [];
            }

            @unlink($tempPdfPath);

            if (empty(trim($rawText))) {
                throw new \RuntimeException(
                    'This PDF appears to be image-based (scanned). Please upload a text-based PDF so audio can be generated.'
                );
            }

            // Strip invalid UTF-8 bytes (ligatures, smart-quotes, Win-1252 sequences)
            // so json_encode never throws "Malformed UTF-8" when saving audio_chapters.
            $rawText = $this->sanitizeUtf8($rawText);

            // Cache the raw text so AI features and admin backfill can reuse it.
            // Store before the front-matter strip so the full book (including TOC) is searchable.
            if (empty($this->book->text_content)) {
                $wordCount           = str_word_count(strip_tags($rawText));
                $estimatedReadingMin = max(1, (int) ceil($wordCount / 238));
                $currentMeta         = $this->book->meta_data ?? [];
                $currentMeta['word_count']                 = $wordCount;
                $currentMeta['estimated_reading_minutes']  = $estimatedReadingMin;

                $this->book->updateQuietly([
                    'text_content' => $rawText,
                    'meta_data'    => $currentMeta,
                ]);
            }

            // Strip front matter (copyright, ToC, dedication) on raw text.
            // Line structure is intact here, which lets us detect TOC lines
            // (keyword + title text + trailing page number) vs real headings.
            $rawText = $this->skipFrontMatter($rawText);

            // Normalize for TTS — collapse all whitespace to single spaces
            $text = trim(preg_replace('/\s+/', ' ', $rawText));

            $voiceId = $this->author->elevenlabs_voice_id;
            if (! $voiceId) {
                throw new \RuntimeException('Author has no cloned voice. Please upload a voice sample first.');
            }

            // Step 3: Detect chapter boundaries, then split into chunks
            $chapterMarkers = $this->detectChapterMarkers($text);
            [$chunks, $chapterMap] = $this->chunkTextWithChapters($text, 4500, $chapterMarkers);
            $totalChunks = count($chunks);

            // Enrich each chapter entry with the PDF page it starts on so the
            // frontend can navigate the PDF reader in sync with the audio player.
            if (! empty($chapterMap) && ! empty($pageParts)) {
                $chapterPageMap = $this->buildChapterPageMap($pageParts);
                foreach ($chapterMap as &$entry) {
                    $entry['page'] = $this->matchPageForTitle($entry['title'], $chapterPageMap);
                }
                unset($entry);
            }

            // Store chapter map in Redis so FinalizeBookAudioJob can persist it
            if (! empty($chapterMap)) {
                Redis::set("audio_chapters:{$this->book->id}", json_encode($chapterMap), 'EX', 86400);
            }
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

        // Return raw output — caller normalizes after skipFrontMatter so line
        // structure is available for TOC detection.
        return $output ?? '';
    }

    /**
     * Strip everything before the first genuine chapter/introduction heading.
     *
     * Must be called on raw (un-normalized) text so that line endings are intact.
     * TOC lines look like "Chapter 1 — The Voice You Were Made to Hear   9" —
     * they end with a standalone page number.  Real headings do not.
     * The MULTILINE flag makes ^ anchor to the start of every line.
     */
    private function skipFrontMatter(string $text): string
    {
        $pattern = '/^[ \t]*((?:Chapter|CHAPTER|Introduction|INTRODUCTION|Prologue|PROLOGUE|Preface|PREFACE|Part\s+(?:\d+|[IVXLC]+))\b[^\n]{0,100})/m';

        $offset = 0;
        while (preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $headingText  = trim($matches[1][0]);
            $headingStart = $matches[1][1];
            $fullMatchEnd = $matches[0][1] + strlen($matches[0][0]);

            // A TOC line has a meaningful title (≥10 chars total) ending with a
            // page number: "Chapter 1 — The Voice You Were Made to Hear   9"
            // Pure headings like "Chapter 3" (9 chars) or "Introduction" are never flagged.
            // A TOC line either ends with a page number ("... 50")
            // OR has a page number in the middle when the PDF extractor merges
            // the next entry onto the same line ("... 50 Conclusion  52").
            // The second pattern requires ≥20 chars before the number and 2+
            // spaces of TOC-style padding before the digit block.
            $isTocEntry = (bool) preg_match('/^.{10,}\s+\d{1,4}\s*$/', $headingText)
                       || (bool) preg_match('/^.{20,}\s{2,}\d{1,4}\s+\S/', $headingText);

            if (! $isTocEntry && $headingStart > 150) {
                return ltrim(substr($text, $headingStart));
            }

            $offset = $fullMatchEnd;
        }

        return $text;
    }

    /**
     * Return a map of [charOffset => title] for all chapter/section headings found in the text.
     */
    private function detectChapterMarkers(string $text): array
    {
        $markers = [];

        // (?:^|\n|(?<=\s)) fires at line-start on raw text AND on space-separated
        // words in normalized (no-newline) text, so both pipeline paths are covered.
        $chapterPattern = '/(?:^|\n|(?<=\s))((?:Chapter|CHAPTER)\s+(?:\d+|[IVXLCDM]+|One|Two|Three|Four|Five|Six|Seven|Eight|Nine|Ten|Eleven|Twelve|Thirteen|Fourteen|Fifteen|Sixteen|Seventeen|Eighteen|Nineteen|Twenty)\b[^\n]{0,80})/m';

        $sectionPattern = '/(?:^|\n|(?<=\s))((?:Introduction|Prologue|Epilogue|Afterword|Preface|Part\s+\d+)\b[^\n]{0,60})/im';

        foreach ([$chapterPattern, $sectionPattern] as $pat) {
            preg_match_all($pat, $text, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[1] as $match) {
                $pos   = $match[1];
                $title = $this->sanitizeUtf8(trim($match[0]));
                if (strlen($title) > 60) {
                    $title = rtrim(substr($title, 0, 57)).'…';
                }
                $markers[$pos] = $title;
            }
        }

        ksort($markers);

        return $markers;
    }

    /**
     * Scan each page's raw text for chapter/section headings and return a map of
     * [normalised-title => 1-based-page-number]. First occurrence wins so the
     * chapter's start page is always recorded, not a later mention.
     */
    private function buildChapterPageMap(array $pageParts): array
    {
        $map            = [];
        $chapterPattern = '/(?:^|\n)((?:Chapter|CHAPTER)\s+(?:\d+|[IVXLCDM]+|One|Two|Three|Four|Five|Six|Seven|Eight|Nine|Ten|Eleven|Twelve|Thirteen|Fourteen|Fifteen|Sixteen|Seventeen|Eighteen|Nineteen|Twenty)\b[^\n]{0,80})/m';
        $sectionPattern = '/(?:^|\n)((?:Introduction|Prologue|Epilogue|Afterword|Preface|Part\s+\d+)\b[^\n]{0,60})/im';

        foreach ($pageParts as $i => $pageText) {
            $pageNum  = $i + 1;
            $pageText = $this->sanitizeUtf8($pageText);
            foreach ([$chapterPattern, $sectionPattern] as $pat) {
                preg_match_all($pat, $pageText, $matches);
                foreach ($matches[1] as $raw) {
                    $title = trim($raw);
                    if (strlen($title) > 60) {
                        $title = rtrim(substr($title, 0, 57)).'…';
                    }
                    if ($title !== '' && ! isset($map[$title])) {
                        $map[$title] = $pageNum;
                    }
                }
            }
        }

        return $map;
    }

    /**
     * Find the page number for a chapter title using exact match first,
     * then a prefix match to handle minor whitespace/truncation differences.
     */
    private function matchPageForTitle(string $needle, array $chapterPageMap): ?int
    {
        if (isset($chapterPageMap[$needle])) {
            return $chapterPageMap[$needle];
        }

        foreach ($chapterPageMap as $key => $page) {
            if (str_starts_with($needle, $key) || str_starts_with($key, $needle)) {
                return $page;
            }
        }

        return null;
    }

    /**
     * Strip bytes that are invalid UTF-8 so json_encode never throws
     * "Malformed UTF-8 characters" when persisting audio_chapters or text_content.
     */
    private function sanitizeUtf8(string $text): string
    {
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
        return ($clean !== false) ? $clean : mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }

    /**
     * Split text into ~$maxChars chunks at sentence boundaries, and build a
     * chapter map: [{segment: n, title: "Chapter 1"}, ...] keyed by which
     * segment each chapter heading first appears in.
     *
     * @return array{0: string[], 1: array<int, array{segment: int, title: string}>}
     */
    private function chunkTextWithChapters(string $text, int $maxChars, array $chapterMarkers): array
    {
        $chunks      = [];
        $chapterMap  = [];
        $absolutePos = 0;
        $remaining   = $text;
        $markerPos   = array_keys($chapterMarkers);
        $markerIdx   = 0;

        while (strlen($remaining) > 0) {
            if (strlen($remaining) <= $maxChars) {
                $chunkLen  = strlen($remaining);
                $chunkText = trim($remaining);
            } else {
                $slice    = substr($remaining, 0, $maxChars);
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

                $chunkLen  = $breakPos;
                $chunkText = trim(substr($remaining, 0, $chunkLen));
            }

            $segIdx    = count($chunks);
            $chunkEnd  = $absolutePos + $chunkLen;

            // Advance past any chapter markers that fall within this chunk
            while ($markerIdx < count($markerPos) && $markerPos[$markerIdx] < $chunkEnd) {
                $pos   = $markerPos[$markerIdx];
                $title = $chapterMarkers[$pos];

                // Only record marker if it starts within this chunk (not before it)
                if ($pos >= $absolutePos) {
                    $chapterMap[] = ['segment' => $segIdx, 'title' => $title];
                }

                $markerIdx++;
            }

            if (! empty($chunkText)) {
                $chunks[] = $chunkText;
            }

            $remaining   = substr($remaining, $chunkLen);
            $absolutePos = $chunkEnd;
        }

        return [$chunks, $chapterMap];
    }
}
