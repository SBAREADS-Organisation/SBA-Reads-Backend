<?php

namespace App\Http\Controllers\Audio;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateBookAudioJob;
use App\Jobs\VoiceCloningJob;
use App\Models\Book;
use App\Services\Cloudinary\CloudinaryMediaUploadService;
use App\Services\ElevenLabs\ElevenLabsService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class AudioController extends Controller
{
    use ApiResponse;

    /**
     * Save voice sample to local disk and dispatch async cloning job.
     * POST /user/voice-sample
     */
    public function uploadVoiceSample(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'voice_sample' => 'required|file|mimes:mp3,m4a,wav,mp4,aac,ogg|max:25600',
        ], [
            'voice_sample.required' => 'Please provide a voice sample recording.',
            'voice_sample.mimes'    => 'Voice sample must be an audio file (mp3, m4a, wav, aac, ogg).',
            'voice_sample.max'      => 'Voice sample must be under 25 MB.',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        $user = $request->user();

        try {
            $file = $request->file('voice_sample');
            $ext  = $file->getClientOriginalExtension() ?: 'm4a';

            if ($file->getSize() < 25600) {
                return $this->error('Voice sample is too small. Please upload at least 30 seconds of clean audio.', 422);
            }

            // Save to local disk immediately (fast) — Cloudinary upload + ElevenLabs cloning run in the background job
            $storagePath = $file->storeAs(
                'voice-samples',
                "user_{$user->id}_".time().".$ext",
                'local'
            );

            if (! $storagePath) {
                return $this->error('Failed to save voice sample. Please try again.', 500);
            }

            $user->update(['voice_status' => 'processing']);

            $voiceName = ($user->name ?? 'author').'-'.$user->id;
            $version   = time();
            Cache::put("voice_upload_version:{$user->id}", $version, 3600);
            VoiceCloningJob::dispatch($user->id, Storage::disk('local')->path($storagePath), $voiceName, $version)
                ->onQueue('voice');

            return $this->success([
                'voice_status' => 'processing',
                'has_voice'    => false,
            ], 'Voice sample received. Cloning is in progress — you will be notified when it is ready.');

        } catch (\Throwable $e) {
            Log::error('Voice sample upload failed for user '.$user->id.': '.$e->getMessage());

            return $this->error('Failed to save voice sample. Please try again.', 500);
        }
    }

    /**
     * Generate a short TTS preview using the author's cloned voice.
     * The audio is uploaded to Cloudinary and the URL is returned immediately
     * so the author can play it before committing to publishing the full book.
     * POST /user/voice-preview
     */
    public function voicePreview(Request $request, ElevenLabsService $elevenLabs, CloudinaryMediaUploadService $cloudinary)
    {
        $validated = Validator::make($request->all(), [
            'text' => 'required|string|min:10|max:500',
        ])->validate();

        $user = $request->user();

        if ($user->voice_status !== 'ready' || empty($user->elevenlabs_voice_id)) {
            return $this->error(
                'Your voice is not ready yet. Please upload a voice sample and wait for cloning to complete.',
                422
            );
        }

        try {
            $audioBinary = $elevenLabs->generateSpeech($user->elevenlabs_voice_id, $validated['text']);

            $tempPath = tempnam(sys_get_temp_dir(), 'sbareads_preview_') . '.mp3';
            file_put_contents($tempPath, $audioBinary);

            $uploaded = $cloudinary->uploadFromPath(
                $tempPath,
                'voice_preview',
                'preview_user_' . $user->id . '_' . time()
            );

            @unlink($tempPath);

            return $this->success(['url' => $uploaded['url']], 'Voice preview generated successfully.');

        } catch (\Throwable $e) {
            Log::error('Voice preview failed for user ' . $user->id . ': ' . $e->getMessage());
            return $this->error('Could not generate voice preview. Please try again.', 500);
        }
    }

    /**
     * Get the current voice cloning status for the authenticated user.
     * GET /user/voice-status
     */
    public function getVoiceStatus(Request $request)
    {
        $user = $request->user();

        return $this->success([
            'voice_status'     => $user->voice_status ?? 'none',
            'has_voice'        => $user->voice_status === 'ready',
            'voice_sample_url' => $user->voice_sample_url,
        ]);
    }

    /**
     * Trigger asynchronous audio generation for a book using the author's cloned voice.
     * POST /books/{bookId}/generate-audio
     */
    public function generateAudio(Request $request, int $bookId)
    {
        $user = $request->user();
        $book = Book::find($bookId);

        if (! $book) {
            return $this->error('Book not found.', 404);
        }

        $isAuthor = $book->author_id === $user->id
            || $book->authors->contains('id', $user->id);

        if (! $isAuthor) {
            return $this->error('You are not authorized to generate audio for this book.', 403);
        }

        if ($user->voice_status !== 'ready' || ! $user->elevenlabs_voice_id) {
            return $this->error('Your voice is not ready yet. Please upload a voice sample and wait for cloning to complete.', 422);
        }

        if (empty($book->files)) {
            return $this->error('This book has no PDF file attached.', 422);
        }

        $dispatched = DB::transaction(function () use ($book, $user) {
            $fresh = Book::where('id', $book->id)->lockForUpdate()->first();

            if (in_array($fresh->audio_status, ['pending', 'processing'])) {
                return false;
            }

            $fresh->update([
                'audio_status'          => 'pending',
                'audio_url'             => null,
                'audio_sample_url'      => null,
                'audio_segments'        => null,
                'audio_duration'        => null,
                'elevenlabs_project_id' => null,
            ]);

            GenerateBookAudioJob::dispatch($fresh, $user)->onQueue('audio');

            return true;
        });

        if (! $dispatched) {
            return $this->error('Audio generation is already in progress for this book.', 422);
        }

        return $this->success([
            'audio_status' => 'pending',
            'book_id'      => $book->id,
        ], 'Audio generation has started. You will be notified when it is ready.');
    }

    /**
     * Admin-only: reset a stuck audio job back to 'none' so the author can retry.
     * POST /books/{bookId}/reset-audio
     */
    public function resetAudio(int $bookId)
    {
        $book = Book::find($bookId);

        if (! $book) {
            return $this->error('Book not found.', 404);
        }

        $book->update([
            'audio_status'          => 'none',
            'audio_url'             => null,
            'audio_sample_url'      => null,
            'audio_segments'        => null,
            'audio_duration'        => null,
            'elevenlabs_project_id' => null,
        ]);

        Log::info("Admin manually reset audio status for book {$bookId}.");

        return $this->success(['book_id' => $bookId, 'audio_status' => 'none'], 'Audio status reset. The author can now retry generation.');
    }

    /**
     * Get the current audio generation status and URLs for a book.
     * GET /books/{bookId}/audio-status
     */
    public function getAudioStatus(int $bookId)
    {
        $book = Book::select('id', 'audio_status', 'audio_url', 'audio_sample_url', 'audio_duration', 'audio_segments')
            ->find($bookId);

        if (! $book) {
            return $this->error('Book not found.', 404);
        }

        return $this->success([
            'audio_status'     => $book->audio_status ?? 'none',
            'audio_url'        => $book->audio_url,
            'audio_sample_url' => $book->audio_sample_url,
            'audio_duration'   => $book->audio_duration,
            'audio_segments'   => $book->audio_segments ?? [],
        ]);
    }

    /**
     * Admin-only: return ElevenLabs character quota usage.
     * GET /admin/elevenlabs/quota
     */
    public function getElevenLabsQuota(ElevenLabsService $elevenLabs)
    {
        try {
            $quota = $elevenLabs->getQuota();

            return $this->success($quota, $quota['is_low']
                ? 'Warning: ElevenLabs credit is running low (under 10% remaining).'
                : 'ElevenLabs quota fetched successfully.');
        } catch (\Throwable $e) {
            Log::error('Failed to fetch ElevenLabs quota: '.$e->getMessage());

            return $this->error('Could not fetch ElevenLabs quota: '.$e->getMessage(), 500);
        }
    }

    /**
     * Admin-only: re-detect chapter markers from stored text_content and save
     * as audio_chapters without regenerating audio.  Useful for older books
     * that were generated before chapter detection was added.
     * POST /admin/books/{bookId}/audio-backfill-chapters
     */
    public function backfillChapters(int $bookId): \Illuminate\Http\JsonResponse
    {
        $book = Book::findOrFail($bookId);

        if (empty($book->text_content)) {
            return $this->error('No text content stored for this book. Regenerate audio to populate chapter data.', 422);
        }

        if (empty($book->audio_segments)) {
            return $this->error('No audio segments found. Regenerate audio first.', 422);
        }

        // skipFrontMatter needs line structure intact, so run it on raw text first.
        // Then normalize exactly as GenerateBookAudioJob does so chapter titles
        // match what was originally stored in audio_chapters (newlines collapsed).
        $text           = $this->skipFrontMatter($this->sanitizeUtf8($book->text_content));
        $text           = trim(preg_replace('/\s+/', ' ', $text));
        $chapterMarkers = $this->detectChapterMarkers($text);

        if (empty($chapterMarkers)) {
            return $this->error('No chapter headings detected in this book\'s text (tried "Chapter N", "Introduction", "Prologue", etc.).', 422);
        }

        [, $chapterMap] = $this->chunkTextWithChapters($text, 4500, $chapterMarkers);

        if (empty($chapterMap)) {
            return $this->error('Chapter markers found but could not map them to segments.', 422);
        }

        // Try to get exact page numbers by re-downloading the PDF and extracting
        // per-page text — same approach as GenerateBookAudioJob.
        $pageParts = [];
        $pdfUrl    = $book->files[0]['public_url'] ?? null;
        if ($pdfUrl) {
            try {
                $tempPdf = tempnam(sys_get_temp_dir(), 'sbareads_backfill_').'.pdf';
                file_put_contents($tempPdf, \Illuminate\Support\Facades\Http::timeout(60)->get($pdfUrl)->body());

                // pdftotext handles complex typography (spaced-letter headings, etc.) better than smalot
                $pageParts = [];
                if (shell_exec('which pdftotext')) {
                    $escaped  = escapeshellarg($tempPdf);
                    $ptOutput = @shell_exec("pdftotext -layout {$escaped} - 2>/dev/null");
                    if ($ptOutput) {
                        $parts = explode("\x0C", $ptOutput);
                        if (isset($parts[count($parts) - 1]) && trim($parts[count($parts) - 1]) === '') {
                            array_pop($parts);
                        }
                        if (! empty($parts)) {
                            $pageParts = $parts;
                        }
                    }
                }

                // Fall back to smalot per-page
                if (empty($pageParts)) {
                    $parser    = new \Smalot\PdfParser\Parser();
                    $pdfObj    = $parser->parseFile($tempPdf);
                    $smalotParts = array_map(fn ($p) => $p->getText(), $pdfObj->getPages());
                    if (! empty(trim(implode('', $smalotParts)))) {
                        $pageParts = $smalotParts;
                    }
                }

                @unlink($tempPdf);
            } catch (\Throwable $e) {
                Log::warning("backfillChapters: PDF download/parse failed for book {$bookId} — falling back to char-offset estimate. ".$e->getMessage());
            }
        }

        if (! empty($pageParts)) {
            // Exact per-page mapping — most accurate
            $chapterPageMap = $this->buildChapterPageMap($pageParts);
            foreach ($chapterMap as &$entry) {
                $entry['page'] = $this->matchPageForTitle($entry['title'], $chapterPageMap);
            }
            unset($entry);
        } else {
            // Fallback: proportional estimate from character offset
            $totalChars    = max(1, strlen($text));
            $totalPages    = (int) ($book->meta_data['pages'] ?? 0);
            $titleToOffset = [];
            foreach ($chapterMarkers as $offset => $title) {
                $titleToOffset[$title] = $offset;
            }
            if ($totalPages > 0) {
                foreach ($chapterMap as &$entry) {
                    $charOffset    = $titleToOffset[$entry['title']] ?? null;
                    $entry['page'] = $charOffset !== null
                        ? max(1, (int) round(($charOffset / $totalChars) * $totalPages))
                        : null;
                }
                unset($entry);
            }
        }

        $book->update(['audio_chapters' => $chapterMap]);

        Log::info("Admin backfilled audio_chapters for book {$bookId}: ".count($chapterMap).' chapters.');

        return $this->success([
            'book_id'        => $bookId,
            'chapter_count'  => count($chapterMap),
            'audio_chapters' => $chapterMap,
        ], 'Chapter map rebuilt from stored text. No audio was regenerated.');
    }

    /**
     * Scan each page's raw text for chapter/section headings.
     * Returns [normalised-title => 1-based-page-number]; first occurrence wins.
     */
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
                    if (strlen($title) > 60) {
                        $title = rtrim(substr($title, 0, 57)).'…';
                    }
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
            // Detect spaced-letter section headings standing alone on a line.
            // ^...$+m anchors prevent matching TOC entries like "Introduction: The Valley... 5".
            // [ \t]* between letters handles fused groups like "I N T ROD U C T ION".
            $spacedSections = [
                'INTRODUCTION' => '/^[ \t]*I[ \t]*N[ \t]*T[ \t]*R[ \t]*O[ \t]*D[ \t]*U[ \t]*C[ \t]*T[ \t]*I[ \t]*O[ \t]*N[ \t]*$/mi',
                'PROLOGUE'     => '/^[ \t]*P[ \t]*R[ \t]*O[ \t]*L[ \t]*O[ \t]*G[ \t]*U[ \t]*E[ \t]*$/mi',
                'EPILOGUE'     => '/^[ \t]*E[ \t]*P[ \t]*I[ \t]*L[ \t]*O[ \t]*G[ \t]*U[ \t]*E[ \t]*$/mi',
                'PREFACE'      => '/^[ \t]*P[ \t]*R[ \t]*E[ \t]*F[ \t]*A[ \t]*C[ \t]*E[ \t]*$/mi',
                'AFTERWORD'    => '/^[ \t]*A[ \t]*F[ \t]*T[ \t]*E[ \t]*R[ \t]*W[ \t]*O[ \t]*R[ \t]*D[ \t]*$/mi',
            ];
            foreach ($spacedSections as $normalizedKey => $pat) {
                if (preg_match($pat, $pageText) && ! isset($map[$normalizedKey])) {
                    $map[$normalizedKey] = $pageNum;
                }
            }
        }

        return $map;
    }

    /**
     * Find the page for a chapter title: exact match first, then prefix match
     * to handle minor whitespace/truncation differences between extraction paths.
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
     * Strip everything before the first genuine chapter/introduction heading.
     * Works on both raw text (newlines intact) and pre-normalized text (spaces only).
     * On raw text, uses the MULTILINE flag and detects TOC lines by their trailing
     * page number.  On normalized text, uses a lookahead heuristic: if within the
     * next 200 chars after a keyword there is a "text + page-number + another keyword"
     * sequence, the current keyword is still inside the TOC.
     */
    private function skipFrontMatter(string $text): string
    {
        $hasNewlines = str_contains($text, "\n");

        if ($hasNewlines) {
            // Raw text — use line anchoring + trailing-page-number check + proximity
            $pattern = '/^[ \t]*((?:Chapter|CHAPTER|Introduction|INTRODUCTION|Prologue|PROLOGUE|Preface|PREFACE|Part\s+(?:\d+|[IVXLC]+))\b[^\n]{0,100})/m';
            $offset  = 0;
            while (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE, $offset)) {
                $headingText  = trim($m[1][0]);
                $headingStart = $m[1][1];
                $offset       = $m[0][1] + strlen($m[0][0]);
                $isTocEntry   = (bool) preg_match('/^.{10,}\s+\d{1,4}\s*$/', $headingText)
                             || (bool) preg_match('/^.{20,}\s{2,}\d{1,4}\s+\S/', $headingText);
                if ($isTocEntry || $headingStart <= 150) continue;
                // Proximity check: another heading within 500 chars → still in TOC
                if (preg_match($pattern, substr($text, $offset, 500))) continue;
                return ltrim(substr($text, $headingStart));
            }
        } else {
            // Normalized text — use lookahead proximity heuristic
            $pattern = '/(?:^|(?<=\s))((?:Chapter|CHAPTER)\s+(?:\d+|[IVXLCDM]+|One|Two|Three|Four|Five|Six|Seven|Eight|Nine|Ten)\b|Introduction\b|Prologue\b|Preface\b)/';
            $offset  = 0;
            while (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE, $offset)) {
                $pos    = $m[1][1];
                $end    = $m[0][1] + strlen($m[0][0]);
                $offset = $end;
                if ($pos < 200) {
                    continue;
                }
                // Still in TOC if "text + page-number + another keyword" follows
                $lookahead = substr($text, $end, 200);
                $isInToc   = (bool) preg_match(
                    '/[^.?!]{0,120}\s\d{1,4}\s+(?:Chapter|Introduction|Prologue|Epilogue|Preface|Part\s)/i',
                    $lookahead
                );
                if (! $isInToc) {
                    return ltrim(substr($text, $pos));
                }
            }
        }

        return $text;
    }

    private function detectChapterMarkers(string $text): array
    {
        $markers = [];

        // (?:^|\n|(?<=\s)) works on both raw text (newlines present) and pre-normalized
        // text (spaces only), so backfill works regardless of how text_content was stored.
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

    private function sanitizeUtf8(string $text): string
    {
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
        return ($clean !== false) ? $clean : mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }

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

            $segIdx   = count($chunks);
            $chunkEnd = $absolutePos + $chunkLen;

            while ($markerIdx < count($markerPos) && $markerPos[$markerIdx] < $chunkEnd) {
                $pos   = $markerPos[$markerIdx];
                $title = $chapterMarkers[$pos];
                if ($pos >= $absolutePos) {
                    $segOffset    = round(max(0.0, ($pos - $absolutePos)) / max(1, $chunkLen), 3);
                    $textPreview  = $this->sanitizeUtf8(trim(mb_substr($remaining, $pos - $absolutePos, 2000)));
                    $chapterMap[] = ['segment' => $segIdx, 'title' => $title, 'segment_offset' => $segOffset, 'text_preview' => $textPreview];
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
