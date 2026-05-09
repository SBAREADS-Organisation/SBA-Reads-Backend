<?php

namespace App\Http\Controllers\AI;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\BookChapter;
use App\Models\ReadingProgress;
use App\Models\Transaction;
use App\Services\AI\ClaudeService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Smalot\PdfParser\Parser;

class BookAIController extends Controller
{
    use ApiResponse;

    // Max characters sent to Claude as book context (~37k tokens — well within 200k limit)
    private const MAX_CONTEXT_CHARS = 150_000;

    public function __construct(protected ClaudeService $claude) {}

    // ─────────────────────────────────────────────
    // Feature 1 — Ask the Book
    // POST /books/{bookId}/ai/chat
    // ─────────────────────────────────────────────

    public function chat(Request $request, int $bookId)
    {
        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        $book = Book::find($bookId);
        if (! $book) {
            return $this->error('Book not found.', 404);
        }

        $user = $request->user();

        // Allow author or anyone who purchased the book
        $isAuthor    = $book->author_id === $user->id || $book->authors->contains('id', $user->id);
        $hasPurchase = $book->purchasers()->where('user_id', $user->id)->exists();

        if (! $isAuthor && ! $hasPurchase) {
            return $this->error('You must purchase this book to use the AI chat feature.', 403);
        }

        try {
            $text = $this->resolveBookText($book);

            if (empty($text)) {
                return $this->error('This book has not been indexed for AI chat yet. Please ask the author to generate audio first.', 422);
            }

            $context = substr($text, 0, self::MAX_CONTEXT_CHARS);

            $answer = $this->claude->message(
                userPrompt: "Question: {$request->message}",
                systemPrompt: "You are a helpful reading assistant for the book \"{$book->title}\". ".
                    "Answer questions based only on the book content provided below. ".
                    "Be concise, accurate, and helpful. If the answer is not in the book, say so.\n\n".
                    "BOOK CONTENT:\n{$context}",
                maxTokens: 512
            );

            return $this->success(['answer' => $answer], 'AI response generated.');

        } catch (\Throwable $e) {
            Log::error("BookAI chat failed for book {$bookId}: ".$e->getMessage());
            return $this->error($e->getMessage(), 500);
        }
    }

    // ─────────────────────────────────────────────
    // Feature 2 — AI Book Description Generator
    // POST /books/{bookId}/ai/generate-description
    // ─────────────────────────────────────────────

    public function generateDescription(Request $request, int $bookId)
    {
        $book = Book::find($bookId);
        if (! $book) {
            return $this->error('Book not found.', 404);
        }

        $user     = $request->user();
        $isAuthor = $book->author_id === $user->id || $book->authors->contains('id', $user->id);

        if (! $isAuthor) {
            return $this->error('Only the author can generate a book description.', 403);
        }

        try {
            $text = $this->resolveBookText($book);

            if (empty($text)) {
                return $this->error('Could not extract text from the book PDF. Please ensure it is a text-based PDF.', 422);
            }

            // Use first 30k chars — enough to capture style, plot, and themes
            $sample = substr($text, 0, 30_000);

            $description = $this->claude->message(
                userPrompt: "Write a compelling book description for \"{$book->title}\" based on the content below. ".
                    "The description should be 150–200 words, engaging, and suitable for a book listing page. ".
                    "Do NOT include spoilers for major plot twists. Return only the description text, no headings.\n\n".
                    "BOOK CONTENT SAMPLE:\n{$sample}",
                maxTokens: 350
            );

            $save = filter_var($request->input('save', false), FILTER_VALIDATE_BOOLEAN);
            if ($save) {
                $book->update(['description' => $description]);
            }

            return $this->success([
                'description' => $description,
                'saved'       => $save,
            ], 'AI description generated successfully.');

        } catch (\Throwable $e) {
            Log::error("BookAI generateDescription failed for book {$bookId}: ".$e->getMessage());
            return $this->error($e->getMessage(), 500);
        }
    }

    // ─────────────────────────────────────────────
    // Feature 4 — Smart Chapter Detection
    // POST /books/{bookId}/ai/detect-chapters
    // ─────────────────────────────────────────────

    public function detectChapters(Request $request, int $bookId)
    {
        $book = Book::find($bookId);
        if (! $book) {
            return $this->error('Book not found.', 404);
        }

        $user     = $request->user();
        $isAuthor = $book->author_id === $user->id || $book->authors->contains('id', $user->id);

        if (! $isAuthor) {
            return $this->error('Only the author can detect chapters.', 403);
        }

        try {
            $text = $this->resolveBookText($book);

            if (empty($text)) {
                return $this->error('Could not extract text from the book PDF.', 422);
            }

            $sample = substr($text, 0, self::MAX_CONTEXT_CHARS);

            $raw = $this->claude->message(
                userPrompt: "Analyze this book text and identify all chapters. ".
                    "Return a JSON array only — no explanation, no markdown, just the JSON. ".
                    "Each item: {\"number\": 1, \"title\": \"Chapter Title\", \"start\": <character_offset>}. ".
                    "Use the character position in the text where each chapter begins. ".
                    "If no clear chapters exist, return one item with title \"Main Content\" and start 0.\n\n".
                    "BOOK TEXT:\n{$sample}",
                maxTokens: 1024
            );

            // Strip markdown code fences if Claude wrapped the JSON
            $json     = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($raw));
            $chapters = json_decode($json, true);

            if (! is_array($chapters)) {
                return $this->error('Could not parse chapter structure from the book. Please try again.', 422);
            }

            // Persist chapters — replace any existing detection
            BookChapter::where('book_id', $bookId)->delete();

            $saved = [];
            foreach ($chapters as $i => $ch) {
                $next        = $chapters[$i + 1] ?? null;
                $saved[] = BookChapter::create([
                    'book_id'        => $bookId,
                    'chapter_number' => $ch['number'] ?? ($i + 1),
                    'title'          => $ch['title'] ?? "Chapter ".($i + 1),
                    'start_position' => $ch['start'] ?? 0,
                    'end_position'   => $next ? ($next['start'] ?? null) : strlen($text),
                ]);
            }

            return $this->success([
                'chapters' => $saved,
                'count'    => count($saved),
            ], count($saved).' chapters detected and saved.');

        } catch (\Throwable $e) {
            Log::error("BookAI detectChapters failed for book {$bookId}: ".$e->getMessage());
            return $this->error($e->getMessage(), 500);
        }
    }

    // ─────────────────────────────────────────────
    // Feature 6 — Reader Recommendations
    // GET /books/{bookId}/ai/recommendations
    // ─────────────────────────────────────────────

    public function recommendations(Request $request, int $bookId)
    {
        $book = Book::with('categories')->find($bookId);
        if (! $book) {
            return $this->error('Book not found.', 404);
        }

        try {
            $bookGenres     = is_array($book->genres)   ? $book->genres   : [];
            $bookTags       = is_array($book->tags)     ? $book->tags     : [];
            $bookCategories = $book->categories->pluck('id')->toArray();

            // Score every other approved book by attribute overlap
            $candidates = Book::where('id', '!=', $bookId)
                ->where('status', 'approved')
                ->where('visibility', 'public')
                ->with('categories')
                ->get(['id', 'title', 'description', 'cover_image', 'actual_price',
                       'currency', 'genres', 'tags', 'author_id', 'audio_status']);

            $scored = $candidates->map(function ($candidate) use ($bookGenres, $bookTags, $bookCategories) {
                $genres     = is_array($candidate->genres) ? $candidate->genres : [];
                $tags       = is_array($candidate->tags)   ? $candidate->tags   : [];
                $categories = $candidate->categories->pluck('id')->toArray();

                $score = count(array_intersect($genres, $bookGenres))     * 3
                       + count(array_intersect($categories, $bookCategories)) * 2
                       + count(array_intersect($tags, $bookTags))         * 1;

                $candidate->relevance_score = $score;
                return $candidate;
            })
            ->filter(fn ($b) => $b->relevance_score > 0)
            ->sortByDesc('relevance_score')
            ->take(6)
            ->values();

            // Fall back to recent approved books if no genre overlap found
            if ($scored->isEmpty()) {
                $scored = Book::where('id', '!=', $bookId)
                    ->where('status', 'approved')
                    ->where('visibility', 'public')
                    ->latest()
                    ->take(6)
                    ->get(['id', 'title', 'description', 'cover_image', 'actual_price', 'currency', 'author_id', 'audio_status'])
                    ->map(function ($b) { $b->relevance_score = 0; return $b; });
            }

            return $this->success([
                'recommendations' => $scored,
                'based_on'        => [
                    'genres'     => $bookGenres,
                    'categories' => $bookCategories,
                ],
            ], 'Recommendations generated.');

        } catch (\Throwable $e) {
            Log::error("BookAI recommendations failed for book {$bookId}: ".$e->getMessage());
            return $this->error($e->getMessage(), 500);
        }
    }

    // ─────────────────────────────────────────────
    // Feature 7 — Author Analytics AI Insights
    // GET /author/ai/insights
    // ─────────────────────────────────────────────

    public function aiInsights(Request $request)
    {
        $author = $request->user();

        if (! $author->isAuthor()) {
            return $this->error('Only authors can access AI insights.', 403);
        }

        try {
            $authorBookIds = Book::whereHas('authors', fn ($q) => $q->where('author_id', $author->id))
                ->pluck('id')
                ->toArray();

            $books = Book::whereHas('authors', fn ($q) => $q->where('author_id', $author->id))
                ->withCount(['purchasers as sales_count'])
                ->get(['id', 'title', 'status', 'audio_status', 'views_count', 'created_at']);

            $totalRevenue = Transaction::where('user_id', $author->id)
                ->whereIn('type', ['payout', 'earning'])
                ->where('status', 'succeeded')
                ->sum('amount_usd');

            $activeReaders = ReadingProgress::whereIn('book_id', $authorBookIds)
                ->distinct('user_id')->count();

            $avgProgress = ReadingProgress::whereIn('book_id', $authorBookIds)->avg('progress') ?? 0;

            $topBook = $books->sortByDesc('sales_count')->first();

            $summary = "Author: {$author->name}\n".
                "Total books: {$books->count()}\n".
                "Approved books: {$books->where('status','approved')->count()}\n".
                "Pending review: {$books->where('status','pending')->count()}\n".
                "Total revenue: \${$totalRevenue}\n".
                "Active readers: {$activeReaders}\n".
                "Average reading progress across all books: ".round($avgProgress, 1)."%\n".
                "Books with audio ready: {$books->where('audio_status','ready')->count()}\n".
                "Best performing book: ".($topBook ? "\"{$topBook->title}\" ({$topBook->sales_count} sales)" : "None yet")."\n\n".
                "Book-by-book breakdown:\n";

            foreach ($books as $b) {
                $summary .= "- \"{$b->title}\": {$b->sales_count} sales, status={$b->status}, ".
                    "audio={$b->audio_status}, views={$b->views_count}\n";
            }

            $insights = $this->claude->message(
                userPrompt: $summary,
                systemPrompt: "You are an expert publishing analytics advisor. ".
                    "Analyze this author's data and give exactly 3 numbered insights. ".
                    "Each insight: one sentence of finding + one sentence of action. Max 30 words each. ".
                    "Be specific to the numbers. No intros, no conclusions, no generic advice.",
                maxTokens: 300
            );

            return $this->success([
                'insights' => $insights,
                'snapshot' => [
                    'total_books'     => $books->count(),
                    'total_revenue'   => round($totalRevenue, 2),
                    'active_readers'  => $activeReaders,
                    'avg_progress'    => round($avgProgress, 1),
                    'audio_ready'     => $books->where('audio_status', 'ready')->count(),
                ],
            ], 'AI insights generated.');

        } catch (\Throwable $e) {
            Log::error("BookAI aiInsights failed for author {$author->id}: ".$e->getMessage());
            return $this->error($e->getMessage(), 500);
        }
    }

    // ─────────────────────────────────────────────
    // Internal helpers
    // ─────────────────────────────────────────────

    /**
     * Return the book's stored text or re-extract from the PDF URL.
     * Also caches the text back onto the book for future requests.
     */
    private function resolveBookText(Book $book): string
    {
        if (! empty($book->text_content)) {
            return $book->text_content;
        }

        $pdfUrl = $book->files[0]['public_url'] ?? null;
        if (! $pdfUrl) {
            return '';
        }

        try {
            $tempPath = tempnam(sys_get_temp_dir(), 'sbareads_pdf_').'.pdf';
            file_put_contents($tempPath, Http::timeout(120)->get($pdfUrl)->body());

            $parser = new Parser;
            $text   = trim(preg_replace('/\s+/', ' ', $parser->parseFile($tempPath)->getText()));

            if (empty($text) && shell_exec('which pdftotext')) {
                $escaped = escapeshellarg($tempPath);
                $output  = shell_exec("pdftotext -layout {$escaped} - 2>/dev/null");
                $text    = trim(preg_replace('/\s+/', ' ', $output ?? ''));
            }

            @unlink($tempPath);

            if (! empty($text)) {
                // Save so we don't re-extract next time
                $book->updateQuietly(['text_content' => $text]);
            }

            return $text;

        } catch (\Throwable $e) {
            Log::warning("BookAI: could not extract text for book {$book->id}: ".$e->getMessage());
            return '';
        }
    }
}
