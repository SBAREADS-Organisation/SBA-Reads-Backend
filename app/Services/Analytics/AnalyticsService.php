<?php

namespace App\Services\Analytics;

use App\Models\Book;
use App\Models\Subscription;
use App\Models\UserSubscription;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;

class AnalyticsService
{
    public function getAnalytics(User $user, string $scope, array $filters): array
    {
        return $scope === 'admin'
            ? $this->adminAnalytics($filters)
            : $this->userAnalytics($user, $filters);
    }

    protected function adminAnalytics(array $filters): array
    {
        $now = Carbon::now();

        return [
            'books' => [
                'total' => Book::count(),
                'published' => Book::where('status', 'approved')->count(),
                'rejected' => Book::where('status', 'declined')->count(),
                'expired' => Book::where('status', 'expired')->count(),
                'in_review' => Book::where('status', ['needs_changes', 'review'])->count(),
                'pending' => Book::where('status', 'pending')->count(),
                'views' => 'upcoming'/*Book::sum('views')*/,
            ],
            'users' => [
                'total' => User::count(),
                'readers' => User::where('account_type', 'reader')->count(),
                'authors' => User::where('account_type', 'author')->count(),
                'active' => User::where('status', 'active')->count(),
            ],
            'transactions' => [
                'total_sales' => Transaction::sum('amount'),
                'weekly_sales' => $this->weeklyRevenueTrend(),
                'pending_payouts' => 'loading'/*Transaction::where('payout_status', 'pending')->sum('amount')*/,
            ],
            'subscriptions' => [
                'active' => UserSubscription::where('status', 'active')->count(),
                'total' => UserSubscription::count(),
            ],
            'top_selling_books' => 'loading'/*Book::withCount('sales')
                ->orderBy('sales_count', 'desc')
                ->take(5)->get()*/,
            'top_authors' => /*User::with(['books' => function ($q) {
                $q->withCount('sales')->orderBy('sales_count', 'desc');
            }])->where('account_type', 'author')->get()*/'loading',
            'growth' => [
                'users' => $this->weeklyGrowth(User::class),
                'books' => $this->weeklyGrowth(Book::class),
            ]
        ];
    }

    protected function userAnalytics(User $user, array $filters): array
    {
        return [
            'books' => [
                'total' => $user->books()->count(),
                'published' => $user->books()->where('status', 'approved')->count(),
                'rejected' => $user->books()->where('status', 'declined')->count(),
                'expired' => $user->books()->where('status', 'expired')->count(),
                'in_review' => $user->books()->where('status', ['needs_changes', 'review'])->count(),
                'pending' => $user->books()->where('status', 'pending')->count(),
                'views' => 'upcoming'/*$user->books()->sum('views')*/,
                'sold' => /*$user->books()->withCount('sales')->sum('sales_count')*/'loading',
            ],
            'revenue' => [
                'total' => $user->payments()->sum('amount'),
                'weekly' => $this->weeklyRevenueTrend($user),
            ],
            'top_books' => /*$user->books()
                ->withCount('sales')
                ->orderBy('sales_count', 'desc')
                ->take(3)->get()*/'loading',
            'payouts' => [
                'pending' => 'loading'/*$user->payments()->where('payout_status', 'pending')->sum('amount')*/,
            ]
        ];
    }

    protected function weeklyRevenueTrend(?User $user = null)
    {
        $query = Transaction::selectRaw('DATE(created_at) as date, SUM(amount) as total')
            ->groupBy('date')
            ->orderBy('date', 'desc')
            ->limit(7);

        if ($user) {
            $query->where('user_id', $user->id);
        }

        return $query->get();
    }

    protected function weeklyGrowth(string $modelClass)
    {
        return $modelClass::selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date', 'desc')
            ->limit(7)
            ->get();
    }
}
