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

            if (!$author->isAuthor()) {
                return $this->error('Access denied. Only authors can access this dashboard.', 403);
            }

            // Get author's books with proper error handling
            $authorBooks = Book::whereHas('authors', function ($q) use ($author) {
                $q->where('author_id', $author->id);
            })->pluck('id');

            // Revenue placeholder — computed from gross sales after split is applied below

            // Books metrics - Enhanced with proper counting
            $books_published = Book::whereHas('authors', function ($q) use ($author) {
                $q->where('author_id', $author->id);
            })->where('status', 'approved')->count();

            $pending_books_count = Book::whereHas('authors', function ($q) use ($author) {
                $q->where('author_id', $author->id);
            })->where('status', 'pending')->count();

            $books_uploaded = Book::whereHas('authors', function ($q) use ($author) {
                $q->where('author_id', $author->id);
            })->count();

            $books_rejected = Book::whereHas('authors', function ($q) use ($author) {
                $q->where('author_id', $author->id);
            })->where('status', 'declined')->count();

            // Gross sales = sum of book prices for every copy sold (NGN)
            $total_sales = $this->calculateTotalSales($authorBooks->toArray());
            // Author revenue = 90% of gross sales (10% SBAReads commission)
            $revenue = round($total_sales * 0.9, 2);
            $books_sold = $this->calculateBooksSold($authorBooks->toArray());

            // Detect dominant currency from the author's books
            $dominantCurrency = Book::whereHas('authors', function ($q) use ($author) {
                $q->where('author_id', $author->id);
            })->select('currency')->groupBy('currency')
              ->orderByRaw('COUNT(*) DESC')->value('currency') ?? 'NGN';

            // Reader engagement
            $reader_engagement = $this->calculateReaderEngagement($authorBooks->toArray());

            // Recent data
            $recent_transactions = Transaction::where('user_id', $author->id)
                ->orderBy('created_at', 'desc')
                ->take(5)
                ->get();

            $recent_book_uploads = Book::whereHas('authors', function ($q) use ($author) {
                $q->where('author_id', $author->id);
            })->with([
                'authors:id,name,email,profile_picture,bio',
                'categories:id,name'
            ])->orderBy('created_at', 'desc')->take(5)->get();

            // Total book views across all author books
            $total_views = Book::whereHas('authors', function ($q) use ($author) {
                $q->where('author_id', $author->id);
            })->sum('views_count');

            // Monthly trends
            $monthly_sales = $this->getMonthlySales($authorBooks->toArray());

            // Additional metrics for better dashboard population
            $additional_metrics = $this->getAdditionalMetrics($author->id, $authorBooks->toArray());

            $response_data = [
                'revenue' => $revenue,
                'currency' => strtoupper($dominantCurrency ?? 'NGN'),
                'reader_engagement' => $reader_engagement,
                'books_published' => $books_published,
                'total_views' => (int) $total_views,
                'total_sales' => round($total_sales, 2),
                'books_sold' => $books_sold,
                'books_uploaded' => $books_uploaded,
                'books_rejected' => $books_rejected,
                'books_approved' => $books_published,
                'total_books_count' => $books_uploaded,
                'pending_books_count' => $pending_books_count,
                'recent_transactions' => $recent_transactions,
                'recent_book_uploads' => $recent_book_uploads,
                'monthly_sales' => $monthly_sales,
                'wallet_balance' => $author->wallet_balance ?? 0,

                // Enhanced payload with additional metrics
                'metrics' => $additional_metrics,
                'status_breakdown' => [
                    'approved' => $books_published,
                    'pending' => $pending_books_count,
                    'rejected' => $books_rejected,
                    'total' => $books_uploaded
                ]
            ];

            return $this->success($response_data, 'Author dashboard data retrieved successfully.');
        } catch (\Throwable $th) {

            return $this->error('An error occurred while retrieving author dashboard data.', 500, null, $th);
        }
    }

    /**
     * Calculate total gross sales for the author's books.
     * Sums the book's actual_price for every unit sold (via IAP book_user pivot or Paystack).
     * This gives the raw sales figure in the book's native currency (NGN).
     */
    private function calculateTotalSales(array $authorBookIds): float
    {
        if (empty($authorBookIds)) {
            return 0;
        }

        try {
            // Primary: sum actual_price for every copy sold (covers both IAP + Paystack)
            $total = DB::table('book_user')
                ->join('books', 'books.id', '=', 'book_user.book_id')
                ->whereIn('book_user.book_id', $authorBookIds)
                ->sum('books.actual_price');

            return (float) $total;
        } catch (\Throwable $th) {
            return 0;
        }
    }

    /**
     * Calculate reader engagement metrics for author's books.
     * Active readers = unique users who own (purchased) at least one of the author's books.
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

        try {
            // Active readers = unique purchasers across all author books (includes IAP + Paystack)
            $active_readers = DB::table('book_user')
                ->whereIn('book_id', $authorBookIds)
                ->distinct('user_id')
                ->count('user_id');

            // Reading sessions from progress tracker (best-effort, may be 0 if app hasn't tracked yet)
            $total_reading_sessions = ReadingProgress::whereIn('book_id', $authorBookIds)->count();

            $average_reading_progress = ReadingProgress::whereIn('book_id', $authorBookIds)
                ->avg('progress') ?? 0;

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
        } catch (\Throwable $th) {
            return [
                'active_readers' => 0,
                'total_reading_sessions' => 0,
                'average_reading_progress' => 0,
                'total_reading_time_minutes' => 0,
            ];
        }
    }

    /**
     * Calculate number of unique books sold (books that have been purchased).
     */
    private function calculateBooksSold(array $authorBookIds): int
    {
        if (empty($authorBookIds)) {
            return 0;
        }

        try {
            // Count unique books from digital purchases
            $digitalBooksSold = DigitalBookPurchase::whereHas('items', function ($q) use ($authorBookIds) {
                $q->whereIn('book_id', $authorBookIds);
            })->where('status', 'paid')
                ->distinct()
                ->count();

            // Count unique books from physical orders
            $physicalBooksSold = Order::whereHas('items', function ($q) use ($authorBookIds) {
                $q->whereIn('book_id', $authorBookIds);
            })->where('status', 'completed')
                ->distinct()
                ->count();

            $total = $digitalBooksSold + $physicalBooksSold;

            return $total;
        } catch (\Throwable $th) {

            return 0;
        }
    }

    /**
     * Get monthly revenue for author
     */
    private function getMonthlyRevenue(int $authorId)
    {
        try {
            return Transaction::where('user_id', $authorId)
                ->whereIn('type', ['payout', 'earning'])
                ->where('status', 'succeeded')
                ->where('created_at', '>=', now()->subDays(30))
                ->sum('amount');
        } catch (\Throwable $th) {

            return 0;
        }
    }

    /**
     * Get monthly sales count
     */
    private function getMonthlySales(array $authorBookIds)
    {
        if (empty($authorBookIds)) {
            return 0;
        }

        try {
            $digitalSales = DigitalBookPurchase::whereHas('items', function ($q) use ($authorBookIds) {
                $q->whereIn('book_id', $authorBookIds);
            })->where('status', 'paid')
                ->where('created_at', '>=', now()->subDays(30))
                ->count();

            $physicalSales = Order::whereHas('items', function ($q) use ($authorBookIds) {
                $q->whereIn('book_id', $authorBookIds);
            })->where('status', 'completed')
                ->where('created_at', '>=', now()->subDays(30))
                ->count();

            $total = $digitalSales + $physicalSales;

            return $total;
        } catch (\Throwable $th) {

            return 0;
        }
    }

    /**
     * Get additional metrics for enhanced dashboard
     */
    private function getAdditionalMetrics(int $authorId, array $authorBookIds): array
    {
        try {
            // Get sales by book
            $sales_by_book = [];
            if (!empty($authorBookIds)) {
                foreach ($authorBookIds as $bookId) {
                    $digitalSales = DigitalBookPurchase::whereHas('items', function ($q) use ($bookId) {
                        $q->where('book_id', $bookId);
                    })->where('status', 'paid')->count();

                    $physicalSales = Order::whereHas('items', function ($q) use ($bookId) {
                        $q->where('book_id', $bookId);
                    })->where('status', 'completed')->count();

                    $sales_by_book[$bookId] = [
                        'digital_sales' => $digitalSales,
                        'physical_sales' => $physicalSales,
                        'total_sales' => $digitalSales + $physicalSales
                    ];
                }
            }

            // Get top performing books
            $top_books = Book::whereHas('authors', function ($q) use ($authorId) {
                $q->where('author_id', $authorId);
            })->withCount(['digitalPurchases as sales_count' => function ($query) {
                $query->where('status', 'paid');
            }])->orderBy('sales_count', 'desc')
                ->limit(5)
                ->get(['id', 'title', 'cover_image', 'price']);

            return [
                'sales_by_book' => $sales_by_book,
                'top_performing_books' => $top_books,
                'total_books_with_sales' => count(array_filter($sales_by_book, function ($book) {
                    return $book['total_sales'] > 0;
                }))
            ];
        } catch (\Throwable $th) {

            return [];
        }
    }
}
