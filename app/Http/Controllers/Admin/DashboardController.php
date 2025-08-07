<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\BookMetaDataAnalytics;
use App\Models\DigitalBookPurchase;
use App\Models\Order;
use App\Models\ReadingProgress;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Subscription\SubscriptionService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    use ApiResponse;

    protected $subscriptionService;

    public function __construct(SubscriptionService $sub)
    {
        $this->subscriptionService = $sub;
    }

    public function __invoke(Request $request)
    {
        try {
            // Basic counts
            $reader_count = User::where('account_type', 'reader')->count();
            $author_count = User::where('account_type', 'author')->count();
            $published_books_count = Book::where('status', 'approved')->count();
            $pending_books_count = Book::where('status', 'pending')->count();
            $active_subscription_count = $this->subscriptionService->getActiveSubscriptionCount();

            // Revenue calculations
            $revenue = Transaction::where('type', 'earning')
                ->where('status', 'succeeded')
                ->sum('amount');

            // Total sales from all sources
            $digital_sales = DigitalBookPurchase::where('status', 'completed')->sum('total_amount');
            $physical_sales = Order::where('status', 'completed')->sum('total_amount');
            $total_sales = $digital_sales + $physical_sales;

            // Reader engagement metrics
            $reader_engagement = $this->calculateReaderEngagement();

            // Books analytics
            $total_books_sold = BookMetaDataAnalytics::sum('purchases');

            // Recent data
            $recent_signups = User::orderBy('created_at', 'desc')->take(5)->get();
            $recent_transactions = Transaction::with('user:id,name,email')
                ->orderBy('created_at', 'desc')->take(5)->get();
            $recent_book_uploads = Book::with('authors:id,name')
                ->orderBy('created_at', 'desc')->take(5)->get();

            // Weekly trends
            $weekly_revenue = $this->getWeeklyRevenue();
            $weekly_signups = $this->getWeeklySignups();

            $data = [
                'reader_count' => $reader_count,
                'author_count' => $author_count,
                'published_books_count' => $published_books_count,
                'pending_books_count' => $pending_books_count,
                'active_subscription_count' => $active_subscription_count,
                'revenue' => round($revenue, 2),
                'total_sales' => round($total_sales, 2),
                'total_books_sold' => $total_books_sold,
                'reader_engagement' => $reader_engagement,
                'recent_signups' => $recent_signups,
                'recent_transactions' => $recent_transactions,
                'recent_book_uploads' => $recent_book_uploads,
                'weekly_revenue' => $weekly_revenue,
                'weekly_signups' => $weekly_signups,
            ];

            return $this->success($data, 'Dashboard data retrieved successfully.');
        } catch (\Exception $e) {
            return $this->error('An error occurred while retrieving dashboard data.', 500, null, $e);
        }
    }

    private function calculateReaderEngagement()
    {
        $active_readers = User::where('account_type', 'reader')
            ->whereHas('readingProgress', function ($q) {
                $q->where('updated_at', '>=', now()->subDays(30));
            })->count();

        $total_reading_sessions = ReadingProgress::count();
        $average_reading_progress = ReadingProgress::avg('progress') ?? 0;

        $total_reading_time = ReadingProgress::whereNotNull('session_duration')
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

    private function getWeeklyRevenue()
    {
        return Transaction::where('type', 'earning')
            ->where('status', 'succeeded')
            ->where('created_at', '>=', now()->subDays(7))
            ->sum('amount');
    }

    private function getWeeklySignups()
    {
        return User::where('created_at', '>=', now()->subDays(7))->count();
    }
}
