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
use Illuminate\Support\Facades\Log;

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

            // Log the request for debugging
            Log::info('Author dashboard request', [
                'author_id' => $author->id,
                'author_email' => $author->email
            ]);

            // Get author's books with proper error handling
            $authorBooks = Book::whereHas('authors', function ($q) use ($author) {
                $q->where('author_id', $author->id);
            })->pluck('id');

            Log::info('Author books retrieved', [
                'author_id' => $author->id,
                'book_count' => $authorBooks->count(),
                'book_ids' => $authorBooks->toArray()
            ]);

            // Revenue - Total earnings from successful payout transactions
            $revenue = Transaction::where('user_id', $author->id)
                ->whereIn('type', ['payout', 'earning'])
                ->where('status', 'succeeded')
                ->sum('amount');

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

            // Sales calculations - Enhanced with detailed logging
            $total_sales = $this->calculateTotalSales($authorBooks->toArray());
            $books_sold = $this->calculateBooksSold($authorBooks->toArray());

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

            // Monthly trends
            $monthly_sales = $this->getMonthlySales($authorBooks->toArray());

            // Additional metrics for better dashboard population
            $additional_metrics = $this->getAdditionalMetrics($author->id, $authorBooks->toArray());

            $response_data = [
                'revenue' => round($revenue, 2),
                'reader_engagement' => $reader_engagement,
                'books_published' => $books_published,
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

            Log::info('Author dashboard data retrieved successfully', [
                'author_id' => $author->id,
                'response_data' => $response_data
            ]);

            return $this->success($response_data, 'Author dashboard data retrieved successfully.');
        } catch (\Throwable $th) {
            Log::error('Error retrieving author dashboard data', [
                'author_id' => $request->user()->id ?? null,
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString()
            ]);

            return $this->error('An error occurred while retrieving author dashboard data.', 500, null, $th);
        }
    }

    /**
     * Calculate total sales for author's books from both digital purchases and physical orders.
     */
    private function calculateTotalSales(array $authorBookIds): float
    {
        if (empty($authorBookIds)) {
            Log::warning('No author book IDs provided for total sales calculation');
            return 0;
        }

        try {
            $digitalSales = DigitalBookPurchase::whereHas('items', function ($q) use ($authorBookIds) {
                $q->whereIn('book_id', $authorBookIds);
            })->where('status', 'paid')->sum('total_amount');

            $physicalSales = Order::whereHas('items', function ($q) use ($authorBookIds) {
                $q->whereIn('book_id', $authorBookIds);
            })->where('status', 'completed')->sum('total_amount');

            $total = $digitalSales + $physicalSales;

            Log::info('Total sales calculated', [
                'book_ids' => $authorBookIds,
                'digital_sales' => $digitalSales,
                'physical_sales' => $physicalSales,
                'total_sales' => $total
            ]);

            return $total;
        } catch (\Throwable $th) {
            Log::error('Error calculating total sales', [
                'book_ids' => $authorBookIds,
                'error' => $th->getMessage()
            ]);
            return 0;
        }
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

        try {
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
        } catch (\Throwable $th) {
            Log::error('Error calculating reader engagement', [
                'book_ids' => $authorBookIds,
                'error' => $th->getMessage()
            ]);
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
            Log::warning('No author book IDs provided for books sold calculation');
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

            Log::info('Books sold calculated', [
                'book_ids' => $authorBookIds,
                'digital_books_sold' => $digitalBooksSold,
                'physical_books_sold' => $physicalBooksSold,
                'total_books_sold' => $total
            ]);

            return $total;
        } catch (\Throwable $th) {
            Log::error('Error calculating books sold', [
                'book_ids' => $authorBookIds,
                'error' => $th->getMessage()
            ]);
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
            Log::error('Error calculating monthly revenue', [
                'author_id' => $authorId,
                'error' => $th->getMessage()
            ]);
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

            Log::info('Monthly sales calculated', [
                'book_ids' => $authorBookIds,
                'digital_sales' => $digitalSales,
                'physical_sales' => $physicalSales,
                'total_monthly_sales' => $total
            ]);

            return $total;
        } catch (\Throwable $th) {
            Log::error('Error calculating monthly sales', [
                'book_ids' => $authorBookIds,
                'error' => $th->getMessage()
            ]);
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
            Log::error('Error calculating additional metrics', [
                'author_id' => $authorId,
                'book_ids' => $authorBookIds,
                'error' => $th->getMessage()
            ]);
            return [];
        }
    }
}
