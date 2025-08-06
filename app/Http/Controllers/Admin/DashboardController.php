<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\Order;
use App\Models\ReadingProgress;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Subscription\SubscriptionService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    protected $subscriptionService;

    public function __construct(SubscriptionService $sub)
    {
        $this->subscriptionService = $sub;
    }

    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request)
    {
        try {
            /*
            Aggregate all the data for the dashboard
            - Count of readers
            - Count of authors
            - Count of published books
            - Count of pending books
            - Count of active subscriptions
            - Recent reader signups
            - Recent transactions
            - Recent book uploads
            - Revenue metrics
            - Reader engagement metrics
            - Total sales metrics
            */
            $reader_count = User::where('account_type', 'reader')->count();
            $author_count = User::where('account_type', 'author')->count();
            $published_books_count = Book::where('status', 'approved')->count();
            $pending_books_count = Book::where('status', 'pending')->count();
            $recent_signups = User::orderBy('created_at', 'desc')->take(5)->get();
            $recent_transactions = Transaction::orderBy('created_at', 'desc')->take(5)->get();
            $recent_book_uploads = Book::orderBy('created_at', 'desc')->take(5)->get();
            $active_subscription_count = $this->subscriptionService->getActiveSubscriptionCount();

            $revenue = Transaction::where('status', 'succeeded')->sum('amount');
            $total_sales = Order::where('status', 'completed')->sum('total_amount');

            $active_readers = ReadingProgress::distinct('user_id')->count();
            $total_reading_sessions = ReadingProgress::count();
            $average_reading_progress = ReadingProgress::avg('progress') ?? 0;
            $total_reading_time = ReadingProgress::sum('session_duration') ?? 0;

            return $this->success([
                'reader_count' => $reader_count,
                'author_count' => $author_count,
                'published_books_count' => $published_books_count,
                'pending_books_count' => $pending_books_count,
                'recent_signups' => $recent_signups,
                'recent_transactions' => $recent_transactions,
                'recent_book_uploads' => $recent_book_uploads,
                'active_subscription_count' => $active_subscription_count,
                'revenue' => $revenue,
                'reader_engagement' => [
                    'active_readers' => $active_readers,
                    'total_reading_sessions' => $total_reading_sessions,
                    'average_reading_progress' => round($average_reading_progress, 2),
                    'total_reading_time_minutes' => round($total_reading_time / 60, 2),
                ],
                'books_published' => $published_books_count,
                'total_sales' => $total_sales,
            ], 'Dashboard data retrieved successfully.');
        } catch (\Throwable $th) {
            return $this->error('An error occurred while retrieving dashboard data.', 500, null, $th);
        }
    }
}
