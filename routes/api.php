<?php

use App\Http\Controllers\Address\AddressController;
use App\Http\Controllers\Admin\AppVersion\AppVersionController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Analytics\AnalyticsController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Author\AuthorDashboardController;
use App\Http\Controllers\Book\BookController;
use App\Http\Controllers\Category\CategoryController;
use App\Http\Controllers\KYC\KYCController;
use App\Http\Controllers\Notification\NotificationsController;
use App\Http\Controllers\Order\OrderController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\Socials\SocialAuthController;
use App\Http\Controllers\Stripe\StripeWebhookController;
use App\Http\Controllers\Subscription\SubscriptionController;
use App\Http\Controllers\Transaction\TransactionsController;
use App\Http\Controllers\Withdrawal\WithdrawalController;
use App\Http\Controllers\User\UserController;
use App\Models\WebhookEvent;
use Cloudinary\Api\Admin\AdminApi;
use Cloudinary\Api\Exception\ApiError;
use Cloudinary\Configuration\Configuration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;
use Stripe\StripeClient;

// Authentication Routes
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->name('login');
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->name('forgot-password');
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
    Route::post('verify-reset-password-otp', [AuthController::class, 'verifyOtp']);
});

// Withdrawal Routes
Route::middleware(['auth:sanctum'])->prefix('withdrawals')->group(function () {
    Route::post('/initiate', [WithdrawalController::class, 'initiate'])->name('withdrawals.initiate');
    Route::get('/history', [WithdrawalController::class, 'history'])->name('withdrawals.history');
    Route::get('/{id}', [WithdrawalController::class, 'show'])->name('withdrawals.show');
});

// User Routes
Route::prefix('user')->group(function () {
    Route::get('/', [UserController::class, 'index']);

    // Superadmin creation route
    Route::post('/superadmin/create', [UserController::class, 'createSuperadmin']);

    Route::post('/register', [UserController::class, 'register']);
    Route::post('/verify-email', [UserController::class, 'verifyAuthorEmail'])->name('verify-email');
    Route::post('/resend-email-token', [UserController::class, 'resendVerificationToken'])->name('resend-email-token');

    // Admin User Management Routes
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::middleware(['role:admin,superadmin'])->get('all', [UserController::class, 'allUsers']);
    });

    // User Management Routes
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::prefix('profile')->group(function () {
            Route::get('/', [UserController::class, 'profile']);
            Route::post('/', [UserController::class, 'updateProfile']);
            Route::get('/{user_id}', [UserController::class, 'singleUserById'])->where('user_id', '[0-9]+');
            Route::post('/action/{action}/{user_id}', [UserController::class, 'adminAproveOrDeclineActionOnUser'])->name('admin-approve-decline-user');
            Route::patch('/preference', [UserController::class, 'updatePreferences']);
            Route::patch('/settings', [UserController::class, 'updateSettings']);
        });

        // User Address Routes
        Route::prefix('address')->group(function () {
            Route::post('/', [AddressController::class, 'store']);
            Route::get('/all', [AddressController::class, 'addresses']);
        });

        // User Subscriptions Routes
        Route::prefix('subscriptions')->group(function () {
            Route::get('history', [SubscriptionController::class, 'history'])->name('subscription-history');
            Route::post('subscribe', [SubscriptionController::class, 'subscribe'])->name('subscribe');
        });

        // Refresh Token
        Route::post('token/refresh', function (Request $request) {
            return $request->user()->createToken('Personal Access Token');
        });

        // Logout
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');

        // Forgot Password
        Route::post('profile/change-password', [UserController::class, 'changePassword'])->name('change-password');

        // User KYC Routes
        Route::prefix('kyc')->group(function () {
            Route::post('initiate', [KYCController::class, 'initiate_KYC'])->name('initiate-kyc');
            Route::post('upload-document', [KYCController::class, 'uploadDocument'])->name('upload-document');
            Route::get('status', [KYCController::class, 'kycStatus'])->name('kyc-status');
        });

        // User Payment Method Routes
        Route::prefix('payment_method')->group(function () {
            Route::get('list', [UserController::class, 'listPaymentMethods'])->name('list-payment-methods');
            Route::post('delete', [UserController::class, 'deletePaymentMethod'])->name('delete-payment-method');
            Route::post('add-card', [UserController::class, 'addCard'])->name('add-card');
            Route::post('add-bank-account', [UserController::class, 'addBankAccount'])->name('add-bank-account');
        });

        // User Notification Routes
        Route::prefix('notifications')->group(function () {
            Route::get('/', [NotificationsController::class, 'index'])->name('user-notifications');
            Route::post('{notification}/mark-as-read', [NotificationsController::class, 'markNotificationAsRead'])->name('mark-notification-as-read');
            Route::post('/mark-all-as-read', [NotificationsController::class, 'markAllNotificationsAsRead'])->name('mark-all-notifications-as-read');
        });
    });
});

// Subscription Routes
Route::prefix('subscriptions')->group(function () {
    Route::get('/', [SubscriptionController::class, 'available'])->name('available-subscriptions');
});

// Stripe Webhook Route
Route::post('/webhooks/stripe', StripeWebhookController::class)->name('handle-webhook');

// Paystack Routes
Route::prefix('paystack')->group(function () {
    Route::post('/payment/initialize', [\App\Http\Controllers\PaystackPaymentController::class, 'initializePayment']);
    Route::post('/webhook', [\App\Http\Controllers\PaystackPaymentController::class, 'handleWebhook'])->name('paystack.webhook');
    Route::get('/callback', [\App\Http\Controllers\PaystackPaymentController::class, 'handleCallback'])->name('paystack.callback');
});

// Book Routes
Route::middleware(['auth:sanctum'])->group(function () {
    // Public book endpoints (for readers)
    Route::post('books', [BookController::class, 'store']);
    Route::get('books', [BookController::class, 'index']);
    Route::middleware(['role:admin,superadmin'])->get('books/all', [BookController::class, 'getAllBooks'])->name('get-all-books');
    Route::get('books/search', [BookController::class, 'search']);
    Route::get('books/{id}', [BookController::class, 'show'])->name('book.show');
    Route::get('books/{id}/reviews', [BookController::class, 'getReviews']);
    Route::post('books/preview', [BookController::class, 'extractPreview']);
    Route::put('books/{id}', [BookController::class, 'update']);
    Route::patch('books/{book}/toggle-visibility', [BookController::class, 'toggleVisibility']);
    Route::middleware(['role:admin,superadmin,author'])->delete('books/{book}', [BookController::class, 'destroy']);
    Route::post('books/purchase', [BookController::class, 'purchaseBooks'])->name('book.purchase');

    // Reader-specific endpoints
    Route::post('books/{id}/start-reading', [BookController::class, 'startReading']);
    Route::get('books/user/reading-progress', [BookController::class, 'userProgress']);
    Route::post('books/{id}/reviews', [BookController::class, 'postReview']);
    Route::get('books/bookmarks/all', [BookController::class, 'getAllBookmarks']);
    Route::post('books/{id}/bookmark', [BookController::class, 'bookmark']);
    Route::middleware(['role:admin,superadmin'])->post('books/{action}/{bookId}', [BookController::class, 'auditAction'])
        ->where('action', '^(request_changes|approve|decline|restore)$');
    Route::delete('books/{id}/bookmark', [BookController::class, 'removeBookmark']);
    //get my purchased books
    Route::get('books/my-purchases', [BookController::class, 'myPurchasedBooks']);

    // Author-specific endpoints (only for account_type = 'author')
    Route::middleware(['role:author'])->prefix('author')->group(function () {
        Route::get('my-books', [BookController::class, 'myBooks']);
        Route::get('dashboard', AuthorDashboardController::class);
        Route::get('transactions', [TransactionsController::class, 'getAuthorTransactions'])->name('author-transactions');
    });

    // Category Routes
    Route::prefix('categories')->group(function () {
        Route::get('/', [CategoryController::class, 'index']);
        Route::get('/{category}', [CategoryController::class, 'show']);
        Route::post('/', [CategoryController::class, 'store']);
        Route::put('/{category}', [CategoryController::class, 'update']);
        Route::delete('/{category}', [CategoryController::class, 'destroy']);
    });
});

// Order Routes
Route::middleware(['auth:sanctum'])->group(function () {
    // Admin order management
    Route::middleware(['role:admin,superadmin'])->get('order', [OrderController::class, 'index']);

    // User order endpoints
    Route::post('order', [OrderController::class, 'store'])->name('create-order');
    Route::get('order/my-orders', [OrderController::class, 'userOrders'])->name('user-orders');
    Route::get('order/{id}', [OrderController::class, 'show'])->name('show-order');
    Route::get('order/track/{tracking_id}', [OrderController::class, 'track'])->name('track-order');
    Route::put('order/{id}/status-update', [OrderController::class, 'updateStatus'])->name('update-order-status');
});

// Transaction Routes
Route::middleware(['auth:sanctum'])->prefix('transaction')->group(function () {
    Route::get('/verify', [TransactionsController::class, 'verifyPayment']);
    Route::get('/my-transactions', [TransactionsController::class, 'getMyTransactions'])->name('my-transactions');
    Route::middleware(['role:admin,superadmin'])->get('/all', [TransactionsController::class, 'getAllTransactions']);
    Route::get('/{id}', [TransactionsController::class, 'getTransaction']);
    Route::post('/payment/status', [PaymentController::class, 'checkPaymentStatus']);
});

// Analytics Routes
Route::middleware(['auth:sanctum'])->prefix('analytics')->group(function () {
    Route::get('/', [AnalyticsController::class, 'index']);
    Route::get('/monthly-revenue', [AnalyticsController::class, 'monthlyRevenue']);
});

// Admin Routes
Route::middleware(['auth:sanctum', 'role:manager,superadmin'])->prefix('admin')->group(function () {
    // Super admin only - invite other managers
    Route::middleware(['role:superadmin'])->post('invite-admin', [UserController::class, 'inviteAdmin']);

    // App Version Support Routes
    Route::prefix('app-versions-support')->group(function () {
        Route::get('/', [AppVersionController::class, 'index']);
        Route::post('/', [AppVersionController::class, 'store']);
        Route::put('/{id}', [AppVersionController::class, 'update']);
        Route::get('/{id}', [AppVersionController::class, 'show']);
        Route::delete('/{id}', [AppVersionController::class, 'destroy']);
    });

    // Admin Subscription Management Routes
    Route::prefix('subscriptions')->group(function () {
        Route::get('/', [SubscriptionController::class, 'available'])->name('subscriptions');
        Route::get('/{id}', [SubscriptionController::class, 'show'])->name('subscription');
        Route::post('/', [SubscriptionController::class, 'store'])->name('create-subscription');
        Route::put('/{id}', [SubscriptionController::class, 'update'])->name('update-subscription');
        Route::delete('/{id}', [SubscriptionController::class, 'destroy'])->name('delete-subscription');
    });

    // Admin Dashboard Route
    Route::get('dashboard', DashboardController::class);
});

// Social Authentication Routes
Route::get('/auth/{provider}/redirect', [SocialAuthController::class, 'redirect']);
Route::get('/auth/{provider}/callback', [SocialAuthController::class, 'callback']);
Route::get('/auth/google', [SocialAuthController::class, 'redirectToProvider']);
Route::get('/auth/google/callback', [SocialAuthController::class, 'handleProviderCallback']);

// Utility Routes
//composer install
Route::get('composer-install', function () {
    Artisan::call('composer:install');
    $output = Artisan::output();

    return response()->json([
        'message' => 'Composer dependencies installed successfully',
        'code' => 200,
        'output' => $output,
    ], 200);
});

//configure scribe
Route::get('scribe-generate', function () {
    Artisan::call('vendor:publish', ['--tag' => 'scribe-config']);
    Artisan::call('scribe:generate');
    $output = Artisan::output();

    return response()->json([
        'message' => 'Scribe documentation generated successfully',
        'code' => 200,
        'output' => $output,
    ], 200);
});

// Database migration route
Route::get('migrate', function () {
    Artisan::call('migrate', ['--force' => true]);

    $output = Artisan::output();

    try {
        DB::select('SELECT 1');
        $connection = DB::connection()->getConfig();

        $host = $connection['host'] ?? 'unknown host';
        $database = $connection['database'] ?? 'unknown database';

        $connectionStatus = "Connected to host: {$host}, database: {$database}";
    } catch (\Exception $e) {
        $connectionStatus = 'Could not connect to database: ' . $e->getMessage();
    }

    return response()->json([
        'message' => 'Migration executed',
        'output' => $output,
        'db_connection_status' => $connectionStatus,
    ]);
});

//rollback migration
Route::get('migrate/rollback', function () {
    Artisan::call('migrate:rollback', ['--force' => true]);
    $output = Artisan::output();

    return response()->json([
        'message' => 'Rollback executed',
        'output' => $output,
    ]);
});

// Database seeding route
Route::get('seed', function () {
    Artisan::call('db:seed');
    $output = Artisan::output();

    return response()->json([
        'message' => 'Seeder run successfully',
        'code' => 200,
        'output' => $output,
    ], 200);
});

// Cache clearing route
Route::get('clear', function () {
    Artisan::call('optimize:clear');
    $output = Artisan::output();

    return response()->json(
        [
            'message' => 'Cache cleared successfully',
            'code' => 200,
            'output' => $output,
        ],
        200
    );
});

// Routes listing route
Route::get('routes', function () {
    Artisan::call('route:list');
    $output = Artisan::output();

    return response()->json([
        'data' => explode("\n", $output),
        'code' => 200,
        'message' => 'Routes listed successfully',
    ], 200);
});

// Storage link creation route
Route::get('storage-link', function () {
    Artisan::call('storage:link');
    $output = Artisan::output();

    return response()->json([
        'message' => 'Storage linked successfully',
        'code' => 200,
        'output' => $output,
    ], 200);
});

// Application optimization route
Route::get('optimize', function () {
    Artisan::call('optimize');
    $output = Artisan::output();

    return response()->json([
        'message' => 'Optimized successfully',
        'code' => 200,
        'output' => $output,
    ], 200);
});

// Key generation route
Route::get('key-generate', function () {
    Artisan::call('key:generate');
});

// Database debugging route
Route::get('/debug-db', function () {
    try {
        $data = DB::select('SELECT * FROM roles');

        return response()->json([
            'status' => 'success',
            'count' => count($data),
            'data' => $data,
        ], 200);
    } catch (\Throwable $th) {
        return response()->json([
            'status' => 'error',
            'message' => $th->getMessage(),
        ], 500);
    }
});

// Database information route
Route::get('/show-db', function () {
    Artisan::call('db:show');
    $output = Artisan::output();

    return response()->json([
        'message' => 'Optimized successfully',
        'code' => 200,
        'output' => $output,
    ], 200);
});

// Monitor Routes
Route::middleware(['monitor.auth'])->prefix('monitor')->group(function () {

    // Health check route
    Route::get('/health', function () {
        $status = 'ok';
        $checks = [];

        try {
            DB::connection()->getPdo();
            $checks['database'] = 'ok';
        } catch (\Exception $e) {
            $checks['database'] = 'error: ' . $e->getMessage();
            $status = 'degraded';
        }

        try {
            Cache::put('monitor_test', 'test', 10);
            $cacheValue = Cache::get('monitor_test');
            if ($cacheValue === 'test') {
                $checks['cache'] = 'ok';
            } else {
                $checks['cache'] = 'error: cache not working as expected';
                $status = 'degraded';
            }
        } catch (\Exception $e) {
            $checks['cache'] = 'error: ' . $e->getMessage();
            $status = 'degraded';
        }

        try {
            Storage::disk('local')->put('monitor_test.txt', 'test content');
            if (Storage::disk('local')->exists('monitor_test.txt')) {
                Storage::disk('local')->delete('monitor_test.txt');
                $checks['storage'] = 'ok';
            } else {
                $checks['storage'] = 'error: storage not writable';
                $status = 'degraded';
            }
        } catch (\Exception $e) {
            $checks['storage'] = 'error: ' . $e->getMessage();
            $status = 'degraded';
        }

        $responseCode = ($status === 'ok') ? 200 : 503;

        return response()->json([
            'status' => $status,
            'application' => config('app.name'),
            'environment' => config('app.env'),
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
        ], $responseCode);
    });

    // Queue monitoring route
    Route::get('/queue', function () {
        $queueConnection = config('queue.default');
        $status = 'ok';
        $queueInfo = [];

        try {
            if ($queueConnection === 'database') {
                $pendingJobs = DB::table(config('queue.connections.database.table'))
                    ->where('queue', config('queue.connections.database.queue'))
                    ->whereNull('reserved_at')
                    ->count();
                $queueInfo['pending_jobs'] = $pendingJobs;
            } else {
                $queueInfo['pending_jobs'] = 'N/A (database queue only for direct count)';
            }

            $failedJobs = DB::table(config('queue.failed.table'))->count();
            $queueInfo['failed_jobs'] = $failedJobs;

            if ($failedJobs > 0) {
                $status = 'degraded';
            }
        } catch (\Exception $e) {
            $queueInfo['error'] = $e->getMessage();
            $status = 'error';
        }

        $responseCode = ($status === 'ok') ? 200 : 503;

        return response()->json([
            'status' => $status,
            'queue_connection' => $queueConnection,
            'queue_info' => $queueInfo,
            'timestamp' => now()->toIso8601String(),
        ], $responseCode);
    });

    // Schedule monitoring route
    Route::get('/schedule', function (Schedule $schedule) {
        $events = $schedule->events();
        $taskCount = count($events);
        $status = 'ok';
        $message = 'Scheduler is active and ' . $taskCount . ' tasks are registered.';

        if ($taskCount === 0) {
            $status = 'warning';
            $message = 'No scheduled tasks registered. Is scheduler running?';
        }

        return response()->json([
            'status' => $status,
            'message' => $message,
            'registered_tasks_count' => $taskCount,
            'timestamp' => now()->toIso8601String(),
        ], 200);
    });

    // Recent webhooks monitoring route
    Route::get('/webhooks/recent', function () {
        $recentEvents = WebhookEvent::orderByDesc('created_at')
            ->limit(20)
            ->get(['stripe_event_id', 'type', 'status', 'created_at', 'error_message']);

        return response()->json([
            'status' => 'ok',
            'recent_webhook_events' => $recentEvents,
            'timestamp' => now()->toIso8601String(),
        ], 200);
    });

    // Version information route
    Route::get('/version', function () {
        return response()->json([
            'status' => 'ok',
            'app_name' => config('app.name'),
            'app_env' => config('app.env'),
            'app_version' => config('app.version', '1.0.0'),
            'laravel_version' => app()->version(),
            'timestamp' => now()->toIso8601String(),
        ], 200);
    });

    // Stripe connection monitoring route
    Route::get('/stripe', function () {
        $status = 'ok';
        $message = 'Stripe API connection successful.';
        $details = [];

        try {
            $stripeSecret = config('services.stripe.secret');

            if (empty($stripeSecret)) {
                $status = 'error';
                $message = 'Stripe secret key is not configured.';
            } else {
                $stripe = new StripeClient(['api_key' => $stripeSecret]);
                $balance = $stripe->balance->retrieve();

                $details['balance_available'] = $balance->available;
                $details['balance_pending'] = $balance->pending;
                $details['default_currency'] = $balance->available[0]->currency ?? 'N/A';
            }
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $status = 'error';
            $message = 'Stripe API Error: ' . $e->getMessage();
            $details['stripe_code'] = $e->getStripeCode();
            $details['http_status'] = $e->getHttpStatus();
        } catch (\Throwable $e) {
            $status = 'error';
            $message = 'General error connecting to Stripe: ' . $e->getMessage();
        }

        $responseCode = ($status === 'ok') ? 200 : 503;

        return response()->json([
            'status' => $status,
            'message' => $message,
            'details' => $details,
            'timestamp' => now()->toIso8601String(),
        ], $responseCode);
    });

    // Cloudinary connection monitoring route
    Route::get('/cloudinary', function () {
        $status = 'ok';
        $message = 'Cloudinary API connection successful.';
        $details = [];

        try {
            Configuration::instance([
                'cloud_name' => config('services.cloud.cloud_name'),
                'api_key' => config('services.cloud.api_key'),
                'api_secret' => config('services.cloud.api_secret'),
            ]);

            $api = new AdminApi();
            $usage = $api->usage();

            $details['cloud_name'] = config('services.cloud.cloud_name');
            $details['storage_usage_bytes'] = $usage['storage']['usage'] ?? 'N/A';
            $details['media_count'] = $usage['objects']['usage'] ?? 'N/A';
        } catch (ApiError $e) {
            $status = 'error';
            $message = 'Cloudinary API Error: ' . $e->getMessage();
            $details['cloudinary_error_code'] = $e->getCode();
            $details['cloudinary_error_message'] = $e->getMessage();
        } catch (\Throwable $e) {
            $status = 'error';
            $message = 'General error connecting to Cloudinary: ' . $e->getMessage();
        }

        $responseCode = ($status === 'ok') ? 200 : 503;

        return response()->json([
            'status' => $status,
            'message' => $message,
            'details' => $details,
            'timestamp' => now()->toIso8601String(),
        ], $responseCode);
    });
});
