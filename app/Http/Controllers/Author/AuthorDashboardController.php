<?php

namespace App\Http\Controllers\Author;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\DigitalBookPurchase;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ReadingProgress;
use App\Models\Transaction;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuthorDashboardController extends Controller
{
    use ApiResponse;

    /**
     * Get author dashboard analytics and metrics.
     */
    public function __invoke(Request $request)
    {
        try {
            $author = $request->user();

            // Ensure the user is an author
            if (!$author->isAuthor()) {
                return $this->error('Access denied. Only authors can access this dashboard.', 403);
            }

            // Get author's books
            $authorBooks = Book::whereHas('authors', function ($q) use ($author) {
                $q->where('author_id', $author->id);
            })->pluck('id');

            // Revenue - Total earnings from successful transactions for this author
            $revenue = Transaction::where('user_id', $author->id)
                ->where('type', 'earning')
                ->where('status', 'succeeded')
                ->sum('amount');

            // Books published - Count of approved books by this author
            $books_published = Book::whereHas('authors', function ($q) use ($author) {
                $q->where('author_id', $author->id);
            })->where('status', 'approved')->count();

            // Total sales - Sum of all completed sales for author's books
            $total_sales = $this->calculateTotalSales($authorBooks->toArray());

            // Reader engagement for author's books
            $reader_engagement = $this->calculateReaderEngagement($authorBooks->toArray());

            // Additional author-specific metrics
            $pending_books_count = Book::whereHas('authors', function ($q) use ($author) {
                $q->where('author_id', $author->id);
            })->where('status', 'pending')->count();

            $total_books_count = Book::whereHas('authors', function ($q) use ($author) {
                $q->where('author_id', $author->id);
            })->count();

            // Recent transactions for this author
            $recent_transactions = Transaction::where('user_id', $author->id)
                ->orderBy('created_at', 'desc')
                ->take(5)
                ->get();

            // Recent book uploads by this author
            $recent_book_uploads = Book::whereHas('authors', function ($q) use ($author) {
                $q->where('author_id', $author->id);
            })->orderBy('created_at', 'desc')->take(5)->get();

            return $this->success([
                'revenue' => round($revenue, 2),
                'reader_engagement' => $reader_engagement,
                'books_published' => $books_published,
                'total_sales' => round($total_sales, 2),

                // Additional useful metrics for authors
                'total_books_count' => $total_books_count,
                'pending_books_count' => $pending_books_count,
                'recent_transactions' => $recent_transactions,
                'recent_book_uploads' => $recent_book_uploads,
            ], 'Author dashboard data retrieved successfully.');
        } catch (\Throwable $th) {
            return $this->error('An error occurred while retrieving author dashboard data.', 500, null, $th);
        }
    }

    /**
     * Calculate total sales for author's books from both digital purchases and physical orders.
     */
    private function calculateTotalSales(array $authorBookIds): float
    {
        // Digital book purchases
        $digitalSales = DigitalBookPurchase::whereHas('items', function ($q) use ($authorBookIds) {
            $q->whereIn('book_id', $authorBookIds);
        })->where('status', 'paid')->sum('total_amount');

        // Physical order sales
        $physicalSales = OrderItem::whereIn('book_id', $authorBookIds)
            ->whereHas('order', function ($q) {
                $q->where('status', 'completed');
            })->sum('total_price');

        return $digitalSales + $physicalSales;
    }

    /**
     * Calculate reader engagement metrics for author's books.
     */
    private function calculateReaderEngagement(array $authorBookIds): array
    {
        if (empty($authorBookIds)) {
            return [
                'active_readers' => 0,
                'total_reading_sessions' => 0,
                'average_reading_progress' => 0,
                'total_reading_time_minutes' => 0,
            ];
        }

        // Active readers - users who have reading progress on author's books
        $active_readers = ReadingProgress::whereIn('book_id', $authorBookIds)
            ->distinct('user_id')
            ->count();

        // Total reading sessions for author's books
        $total_reading_sessions = ReadingProgress::whereIn('book_id', $authorBookIds)->count();

        // Average reading progress across all author's books
        $average_reading_progress = ReadingProgress::whereIn('book_id', $authorBookIds)
            ->avg('progress') ?? 0;

        // Total reading time for author's books (assuming session_duration is stored in minutes)
        $total_reading_time = ReadingProgress::whereIn('book_id', $authorBookIds)
            ->whereNotNull('session_duration')
            ->get()
            ->sum(function ($progress) {
                $sessionData = json_decode($progress->session_duration, true);
                return is_array($sessionData) ? array_sum($sessionData) : 0;
            });

        return [
            'active_readers' => $active_readers,
            'total_reading_sessions' => $total_reading_sessions,
            'average_reading_progress' => round($average_reading_progress, 2),
            'total_reading_time_minutes' => round($total_reading_time, 2),
        ];
    }
}
