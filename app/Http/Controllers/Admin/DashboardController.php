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
            $reader_count = User::where('account_type', 'reader')->count();
            $author_count = User::where('account_type', 'author')->count();
            $published_books_count = Book::where('status', 'approved')->count();
            $pending_books_count = Book::where('status', 'pending')->count();
            $recent_signups = User::orderBy('created_at', 'desc')->take(5)->get();
            $recent_transactions = Transaction::orderBy('created_at', 'desc')->take(5)->get();
            $recent_book_uploads = Book::orderBy('created_at', 'desc')->take(5)->get();

            $active_subscription_count = $this->subscriptionService->getActiveSubscriptionCount();

            $data = [
                'reader_count' => $reader_count,
                'author_count' => $author_count,
                'published_books_count' => $published_books_count,
                'pending_books_count' => $pending_books_count,
                'active_subscription_count' => $active_subscription_count,
                'recent_signups' => $recent_signups,
                'recent_transactions' => $recent_transactions,
                'recent_book_uploads' => $recent_book_uploads,
            ];

            return response()->json([
                'code' => 200,
                'data' => $data,
                'message' => 'Dashboard data retrieved successfully.',
                'error' => null,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'code' => 500,
                'data' => null,
                'message' => null,
                'error' => 'An error occurred while retrieving dashboard data.'
            ], 500);
        }
    }
}
