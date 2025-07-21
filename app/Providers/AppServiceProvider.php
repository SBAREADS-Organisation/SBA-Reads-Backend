<?php

namespace App\Providers;

use App\Models\Order;
use App\Services\Orders\OrderService;
use App\Services\Payments\PaymentService;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
        // $this->app->bind(OrderService::class, function ($app) {
        //     return new OrderService(
        //         $app->make(StripeConnectService::class)
        //     );
        // });

        // $this->app->bind(PaymentService::class, function ($app) {
        //     return new PaymentService(
        //         $app->make(StripeConnectService::class)
        //     );
        // });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Resolve the router from the container
        /** @var Router $router */
        $router = $this->app->make(Router::class);
        Relation::morphMap([
            'order' => \App\Models\Order::class,
            'subscription' => \App\Models\UserSubscription::class,
            'user' => \App\Models\User::class,
            'mediable' => \App\Models\MediaUpload::class,
            'kyc' => \App\Models\KYCVerification::class,
            'book' => \App\Models\Book::class,
            'transaction' => \App\Models\Transaction::class,
            'order_item' => \App\Models\OrderItem::class,
            'book_review' => \App\Models\BookReviews::class,
        ]);
        $router->aliasMiddleware('role', \App\Http\Middleware\RoleMiddleware::class);
        // Broadcast::channel('order.{orderId}', function ($user, $orderId) {
        //     // Only allow the order’s owner (or the author of any book in the order) to listen
        //     if ($user->id === Order::find($orderId)?->user_id) {
        //         return true;
        //     }

        //     // And authors of books in the order can also listen to updates
        //     $order = Order::with('items.book.author')->find($orderId);
        //     if ($order) {
        //         foreach ($order->items as $item) {
        //             if ($item->book->author_id === $user->id) {
        //                 return true;
        //             }
        //         }
        //     }

        //     return false;
        // });
    }
}
