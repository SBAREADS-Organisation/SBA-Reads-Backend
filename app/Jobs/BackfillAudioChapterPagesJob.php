<?php

namespace App\Jobs;

use App\Models\Book;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser;

/**
 * Populate audio_chapters[N].page for a single book without regenerating audio.
 * Dispatched automatically by BookController when a book is served with null pages,
 * and by the audio:backfill-pages artisan command for batch runs.
 */
class BackfillAudioChapterPagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 120;

    public function __construct(protected Book $book) {}

    public function handle(): void
    {
        $book = $this->book;

        if (empty($book->text_content) || empty($book->audio_segments)) {
            return;
        }

        $rawText        = $this->sanitizeUtf8($book->text_content);
        $text           = $this->skipFrontMatter($rawText);
        $text           = trim(preg_replace('/\s+/', ' ', $text));
        $chapterMarkers = $this->detectChapterMarkers($text);

        if (empty($chapterMarkers)) {
            Log::info("BackfillAudioChapterPagesJob: no chapter markers found in book {$book->id}");
            return;
        }

        [, $chapterMap] = $this->chunkTextWithChapters($text, 4500, $chapterMarkers);

        if (empty($chapterMap)) {
            return;
        }

        $pageParts = $this->extractPageParts($book);

        if (! empty($pageParts)) {
            $chapterPageMap = $this->buildChapterPageMap($pageParts);
            foreach ($chapterMap as &$entry) {
                $entry['page'] = $this->matchPageForTitle($entry['title'], $chapterPageMap);
            }
            unset($entry);
            Log::info("BackfillAudioChapterPagesJob: exact page mapping for book {$book->id} — ".count($chapterMap).' chapters');
        } else {
            // Character-offset proportional estimate — better than nothing
            $totalChars    = max(1, strlen($text));
            $totalPages    = (int) ($book->meta_data['pages'] ?? 0);
            $titleToOffset = [];
            foreach ($chapterMarkers as $offset => $title) {
                $titleToOffset[$title] = $offset;
            }
            if ($totalPages > 0) {
                foreach ($chapterMap as &$entry) {
                    $offset        = $titleToOffset[$entry['title']] ?? null;
                    $entry['page'] = $offset !== null
                        ? max(1, (int) round(($offset / $totalChars) * $totalPages))
                        : null;
                }
                unset($entry);
                Log::info("BackfillAudioChapterPagesJob: char-offset fallback for book {$book->id} — ".count($chapterMap).' chapters');
            }
        }

        // Sanitize every title before saving — some books have UTF-8 sequences
        // that survive iconv but still cause json_encode to throw.
        foreach ($chapterMap as &$entry) {
            $entry['title'] = mb_convert_encoding(
                preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $entry['title'] ?? ''),
                'UTF-8', 'UTF-8'
            );
        }
        unset($entry);

        $book->update(['audio_chapters' => $chapterMap]);
    }

    private function extractPageParts(Book $book): array
    {
        $pdfUrl = $book->files[0]['public_url'] ?? null;
        if (! $pdfUrl) return [];

        $tempPdf = tempnam(sys_get_temp_dir(), 'sbareads_bfp_').'.pdf';
        try {
            $response = Http::timeout(60)->get($pdfUrl);
            if (! $response->successful()) return [];
            file_put_contents($tempPdf, $response->body());

            $parser    = new Parser();
            $pdfObj    = $parser->parseFile($tempPdf);
            $pageParts = array_map(fn ($p) => $p->getText(), $pdfObj->getPages());
            if (! empty(trim(implode('', $pageParts)))) return $pageParts;

            if (shell_exec('which pdftotext')) {
                $escaped  = escapeshellarg($tempPdf);
                $ptOutput = @shell_exec("pdftotext -layout {$escaped} - 2>/dev/null");
                if ($ptOutput) {
                    $parts = explode("\x0C", $ptOutput);
                    if (isset($parts[count($parts) - 1]) && trim($parts[count($parts) - 1]) === '') {
                        array_pop($parts);
                    }
                    if (! empty($parts)) return $parts;
                }
            }
        } catch (\Throwable $e) {
            Log::warning("BackfillAudioChapterPagesJob: PDF parse failed for book {$book->id} — ".$e->getMessage());
        } finally {
            @unlink($tempPdf);
        }

        return [];
    }

    private function buildChapterPageMap(array $pageParts): array
    {
        $map            = [];
        $chapterPattern = '/(?:^|\n)[ \t]*((?:Chapter|CHAPTER)\s+(?:\d+|[IVXLCDM]+|One|Two|Three|Four|Five|Six|Seven|Eight|Nine|Ten|Eleven|Twelve|Thirteen|Fourteen|Fifteen|Sixteen|Seventeen|Eighteen|Nineteen|Twenty)\b[^\n]{0,80})/m';
        $sectionPattern = '/(?:^|\n)[ \t]*((?:Introduction|Prologue|Epilogue|Afterword|Preface|Part\s+\d+)\b[^\n]{0,60})/im';

        foreach ($pageParts as $i => $pageText) {
            $pageNum  = $i + 1;
            $pageText = $this->sanitizeUtf8($pageText);
            foreach ([$chapterPattern, $sectionPattern] as $pat) {
                preg_match_all($pat, $pageText, $matches);
                foreach ($matches[1] as $raw) {
                    $title = trim(preg_replace('/\s+/', ' ', $raw));
                    if (strlen($title) > 60) $title = rtrim(substr($title, 0, 57)).'…';
                    if ($title !== '' && ! isset($map[$title])) {
                        $map[$title] = $pageNum;
                    }
                }
            }
            // Detect decorative spaced-letter headings: "C H A P T E R" + number on nearby line
            preg_match_all('/C\s+H\s+A\s+P\s+T\s+E\s+R[\s\n]+(\d+)/i', $pageText, $spacedMatches);
            foreach ($spacedMatches[1] as $num) {
                $key = 'CHAPTER ' . trim($num);
                if (! isset($map[$key])) $map[$key] = $pageNum;
            }
        }

        return $map;
    }

    private function matchPageForTitle(string $needle, array $chapterPageMap): ?int
    {
        if (isset($chapterPageMap[$needle])) return $chapterPageMap[$needle];
        foreach ($chapterPageMap as $key => $page) {
            if (str_starts_with($needle, $key) || str_starts_with($key, $needle)) return $page;
        }
        return null;
    }

    private function skipFrontMatter(string $text): string
    {
        $pattern = '/^[ \t]*((?:Chapter|CHAPTER|Introduction|INTRODUCTION|Prologue|PROLOGUE|Preface|PREFACE|Part\s+(?:\d+|[IVXLC]+))\b[^\n]{0,100})/m';
        $offset  = 0;
        while (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $headingText  = trim($m[1][0]);
            $headingStart = $m[1][1];
            $offset       = $m[0][1] + strlen($m[0][0]);
            $isTocEntry   = (bool) preg_match('/^.{10,}\s+\d{1,4}\s*$/', $headingText)
                         || (bool) preg_match('/^.{20,}\s{2,}\d{1,4}\s+\S/', $headingText);
            if (! $isTocEntry && $headingStart > 150) return ltrim(substr($text, $headingStart));
        }
        return $text;
    }

    private function detectChapterMarkers(string $text): array
    {
        $markers        = [];
        $chapterPattern = '/(?:^|\n|(?<=\s))((?:Chapter|CHAPTER)\s+(?:\d+|[IVXLCDM]+|One|Two|Three|Four|Five|Six|Seven|Eight|Nine|Ten|Eleven|Twelve|Thirteen|Fourteen|Fifteen|Sixteen|Seventeen|Eighteen|Nineteen|Twenty)\b[^\n]{0,80})/m';
        $sectionPattern = '/(?:^|\n|(?<=\s))((?:Introduction|Prologue|Epilogue|Afterword|Preface|Part\s+\d+)\b[^\n]{0,60})/im';
        foreach ([$chapterPattern, $sectionPattern] as $pat) {
            preg_match_all($pat, $text, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[1] as $match) {
                $title = $this->sanitizeUtf8(trim($match[0]));
                if (strlen($title) > 60) $title = rtrim(substr($title, 0, 57)).'…';
                $markers[$match[1]] = $title;
            }
        }
        ksort($markers);
        return $markers;
    }

    private function chunkTextWithChapters(string $text, int $maxChars, array $chapterMarkers): array
    {
        $chunks = $chapterMap = [];
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
                    (int) strrpos($slice, '. '), (int) strrpos($slice, '! '),
                    (int) strrpos($slice, '? '), (int) strrpos($slice, "\n")
                );
                $chunkLen  = ($breakPos < $maxChars / 2) ? $maxChars : $breakPos + 2;
                $chunkText = trim(substr($remaining, 0, $chunkLen));
            }
            $segIdx   = count($chunks);
            $chunkEnd = $absolutePos + $chunkLen;
            while ($markerIdx < count($markerPos) && $markerPos[$markerIdx] < $chunkEnd) {
                $pos   = $markerPos[$markerIdx];
                if ($pos >= $absolutePos) {
                    $chapterMap[] = ['segment' => $segIdx, 'title' => $chapterMarkers[$pos]];
                }
                $markerIdx++;
            }
            if (! empty($chunkText)) $chunks[] = $chunkText;
            $remaining   = substr($remaining, $chunkLen);
            $absolutePos = $chunkEnd;
        }

        return [$chunks, $chapterMap];
    }

    private function sanitizeUtf8(string $text): string
    {
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
        return ($clean !== false) ? $clean : mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }
}
