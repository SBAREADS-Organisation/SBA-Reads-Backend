<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessAuthorAIReviewJob;
use App\Jobs\ProcessBookAIReviewJob;
use App\Models\AppSetting;
use App\Models\Book;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AIReviewController extends Controller
{
    /**
     * Get current AI auto-review settings.
     */
    public function getSettings(): JsonResponse
    {
        return $this->success([
            'ai_auto_approve_books'   => AppSetting::bool('ai_auto_approve_books'),
            'ai_auto_approve_authors' => AppSetting::bool('ai_auto_approve_authors'),
            'ai_auto_decline_books'   => AppSetting::bool('ai_auto_decline_books'),
            'ai_confidence_threshold' => AppSetting::float('ai_confidence_threshold', 0.85),
        ], 'AI review settings retrieved.');
    }

    /**
     * Update AI auto-review settings.
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ai_auto_approve_books'   => 'sometimes|boolean',
            'ai_auto_approve_authors' => 'sometimes|boolean',
            'ai_auto_decline_books'   => 'sometimes|boolean',
            'ai_confidence_threshold' => 'sometimes|numeric|min:0.5|max:1.0',
        ]);

        foreach ($validated as $key => $value) {
            AppSetting::set($key, is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
        }

        return $this->success($validated, 'AI review settings updated.');
    }

    /**
     * Manually trigger AI review for a single book.
     */
    public function reviewBook(int $bookId): JsonResponse
    {
        $book = Book::findOrFail($bookId);
        ProcessBookAIReviewJob::dispatch($book->id)->onQueue('ai');
        return $this->success(['book_id' => $bookId], 'AI review queued for this book.');
    }

    /**
     * Manually trigger AI review for a single author.
     */
    public function reviewAuthor(int $userId): JsonResponse
    {
        $user = User::findOrFail($userId);
        ProcessAuthorAIReviewJob::dispatch($user->id)->onQueue('ai');
        return $this->success(['user_id' => $userId], 'AI review queued for this author.');
    }

    /**
     * Queue AI review for all pending books that haven't been reviewed yet.
     */
    public function reviewAllPendingBooks(): JsonResponse
    {
        $books = Book::where('status', 'pending')
                     ->whereNull('ai_review_status')
                     ->select('id')
                     ->get();

        foreach ($books as $book) {
            ProcessBookAIReviewJob::dispatch($book->id)->onQueue('ai');
        }

        return $this->success(['queued' => $books->count()], "{$books->count()} books queued for AI review.");
    }

    /**
     * Queue AI review for all pending authors that haven't been reviewed yet.
     */
    public function reviewAllPendingAuthors(): JsonResponse
    {
        $users = User::where('account_type', 'author')
                     ->whereIn('status', ['pending', 'unverified'])
                     ->whereNull('ai_review_status')
                     ->select('id')
                     ->get();

        foreach ($users as $user) {
            ProcessAuthorAIReviewJob::dispatch($user->id)->onQueue('ai');
        }

        return $this->success(['queued' => $users->count()], "{$users->count()} authors queued for AI review.");
    }

    /**
     * AI review queue stats for the admin dashboard.
     */
    public function stats(): JsonResponse
    {
        return $this->success([
            'books' => [
                'pending_ai_review'  => Book::where('status', 'pending')->whereNull('ai_review_status')->count(),
                'ai_approved'        => Book::where('ai_review_status', 'approved')->count(),
                'ai_declined'        => Book::where('ai_review_status', 'declined')->count(),
                'ai_human_review'    => Book::where('ai_review_status', 'human_review')->count(),
            ],
            'authors' => [
                'pending_ai_review' => User::where('account_type', 'author')
                                           ->whereIn('status', ['pending', 'unverified'])
                                           ->whereNull('ai_review_status')->count(),
                'ai_verified'       => User::where('ai_review_status', 'verified')->count(),
                'ai_needs_review'   => User::where('ai_review_status', 'needs_review')->count(),
            ],
        ], 'AI review stats retrieved.');
    }
}
