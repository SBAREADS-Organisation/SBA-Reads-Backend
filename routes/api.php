<?php

use App\Http\Controllers\Admin\AppVersion\AppVersionController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Book\BookController;
use App\Http\Controllers\Category\CategoryController;
use App\Http\Controllers\KYC\KYCController;
use App\Http\Controllers\Order\OrderController;
use App\Http\Controllers\Socials\SocialAuthController;
use App\Http\Controllers\Subscription\SubscriptionController;
use App\Http\Controllers\User\UserController;
use App\Http\Controllers\Notification\NotificationsController;
use App\Http\Controllers\Address\AddressController;
use App\Http\Controllers\Analytics\AnalyticsController;
use App\Http\Controllers\Transaction\TransactionsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// Auth Routes
Route::prefix('auth')->group(function () {
    // Login
    Route::post('login', [AuthController::class, 'login'])->name('login');;
    // Route::post('/auth/login', [AuthController::class, 'login']);

    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->name('forgot-password');
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
    Route::post('verify-reset-password-otp', [AuthController::class, 'verifyOtp']);
});

// ========================================================
//                   USERS ROUTES
// ========================================================
Route::prefix('user')->group(function () {
    Route::get('/', [UserController::class, 'index']);

    // Superadmin creation route
    Route::post('/superadmin/create', [UserController::class, 'createSuperadmin']);//->middleware('auth:sanctum');

    Route::post('/register', [UserController::class, 'register']);
    Route::post('/verify-email', [UserController::class, 'verifyAuthorEmail'])->name('verify-email');
    Route::post('/resend-email-token', [UserController::class, 'resendVerificationToken'])->name('resend-email-token');

    // ================================================================================================================================================
    //                                                 ADMIN USER MANAGEMENT ROUTES
    // ================================================================================================================================================
    Route::middleware(['auth:sanctum'])/*->prefix('')*/->group(function () {
        Route::middleware(['role:admin,superadmin'])->get('all', [UserController::class, 'allUsers']);
    });

    // ================================================================================================================================================
    //                                                  USER MANAGEMENT ROUTES
    // ================================================================================================================================================
    Route::middleware(['auth:sanctum'])/*->prefix('user')*/->group(function () {
        Route::prefix('profile')->group(function () {
            Route::get('/', [UserController::class, 'profile']);
            Route::post('/', [UserController::class, 'updateProfile']);
            Route::get('/{user_id}', [UserController::class, 'singleUserById'])->where('user_id', '[0-9]+');
            Route::post('/action/{action}/{user_id}', [UserController::class, 'adminAproveOrDeclineActionOnUser'])->name('admin-approve-decline-user');
            Route::patch('/preference', [UserController::class, 'updatePreferences']);
            Route::patch('/settings', [UserController::class, 'updateSettings']);
        });

        // Add Delivery Address
        Route::prefix('address')->group(function () {
            Route::post('/', [AddressController::class, 'store']);
            Route::get('/all', [AddressController::class, 'addresses']);
        });

        // Subscriptions prefix
        Route::prefix('subscriptions')->group(function () {
            Route::get('history', [SubscriptionController::class, 'history'])->name('subscription-history');
            Route::post('subscribe', [SubscriptionController::class, 'subscribe'])->name('subscribe');
            // Route::post('cancel', [SubscriptionController::class, 'cancelSubscription'])->name('cancel-subscription');
        });

        Route::delete('/', function (Request $request) {
            //
        });

        // Refresh Token
        Route::post('token/refresh', function (Request $request) {
            // dd($request->user());
            return $request->user()->createToken('Personal Access Token');
        });

        // Logout
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');

        // Forgot Password
        Route::post('profile/change-password', [UserController::class, 'changePassword'])->name('change-password');

        // KYC
        Route::prefix('kyc')->group(function() {
            Route::post('initiate', [KYCController::class, 'initiate_KYC'])->name('initiate-kyc');
            Route::post('upload-document', [KYCController::class, 'uploadDocument'])->name('upload-document');
            Route::get('status', [KYCController::class, 'kycStatus'])->name('kyc-status');
        });

        // Payment methods
        Route::prefix('payment_method')->group(function() {
            Route::get('list', [UserController::class, 'listPaymentMethods'])->name('list-payment-methods');
            Route::post('delete', [UserController::class, 'deletePaymentMethod'])->name('delete-payment-method');
            Route::post('add-card', [UserController::class, 'addCard'])->name('add-card');
            Route::post('add-bank-account', [UserController::class, 'addBankAccount'])->name('add-bank-account');
        });

        // Notifications
        Route::prefix('notifications')->group(function () {
            Route::get('/', [NotificationsController::class, 'index'])->name('user-notifications');
            Route::post('{notification}/mark-as-read', [NotificationsController::class, 'markNotificationAsRead'])->name('mark-notification-as-read');
            Route::post('/mark-all-as-read', [NotificationsController::class, 'markAllNotificationsAsRead'])->name('mark-all-notifications-as-read');
        });
    });
});

// Get all available subscriptions
Route::prefix('subscriptions')->group(function() {
    Route::get('/', [SubscriptionController::class, 'available'])->name('available-subscriptions');
});
// Route::get('subscriptions', [SubscriptionController::class, 'available'])->name('available-subscriptions');

Route::post('/webhooks/stripe', [KYCController::class, 'handle'])->name('handle-webhook');

// Books
Route::middleware([/*'auth:api', */'auth:sanctum'])->group(function () {
    // Public book endpoints (for readers)
    Route::post('books', [BookController::class, 'store']);
    Route::get('books', [BookController::class, 'index']);
    Route::middleware(['role:admin,superadmin'])->get('books/all', [BookController::class, 'getAllBooks'])->name('get-all-books');
    Route::get('books/{id}', [BookController::class, 'show'])->name('book.show');
    Route::get('books/{id}/download', [BookController::class, 'download'])->name('book.download');
    Route::post('books/preview', [BookController::class, 'extractPreview']);
    Route::put('books/{id}', [BookController::class, 'update']);
    Route::delete('books/{id}', [BookController::class, 'destroy']);
    Route::get('books/search', [BookController::class, 'search']);

    // Reader-specific endpoints
    Route::post('books/{id}/start-reading', [BookController::class, 'startReading']);
    Route::get('books/user/reading-progress', [BookController::class, 'userProgress']);
    Route::post('books/{id}/reviews', [BookController::class, 'postReview']);
    // Route::get('books/bookmarks/all', [BookController::class, 'getBookmarks']);
    Route::get('books/bookmarks/all', [BookController::class, 'getAllBookmarks']);
    Route::post('books/{id}/bookmark', [BookController::class, 'bookmark']);
    Route::middleware(['role:admin,superadmin'])->post('books/{action}/{id}', [BookController::class, 'auditAction'])
        ->where('action', '^(request_changes|approve|decline|restore)$');
    Route::delete('books/{id}/bookmark', [BookController::class, 'removeBookmark']);

    // Author-specific endpoints (only for account_type = 'author')
    Route::get('author/my-books', [BookController::class, 'myBooks']);

    // Categories
    Route::prefix('categories')->group(function(){
        Route::get('/',    [CategoryController::class,'index']);
        Route::get('/{category}', [CategoryController::class,'show']);
        Route::post('/',   [CategoryController::class,'store']);
        Route::put('/{category}', [CategoryController::class,'update']);
        Route::delete('/{category}', [CategoryController::class,'destroy']);
    });
});

Route::middleware(['auth:sanctum'])->prefix('order')->group(function () {
    // Orders
    Route::middleware(['role:admin,superadmin'])->get('/', [OrderController::class, 'index']);
    Route::get('/my-orders', [OrderController::class, 'userOrders'])->name('user-orders');
    Route::post('/', [OrderController::class, 'store']);
    Route::get('/{id}', [OrderController::class, 'show']);
    Route::get('/track/{tracking_id}', [OrderController::class, 'track']);
    // updateStatus
    Route::put('/{id}/status-update', [OrderController::class, 'updateStatus'])->name('update-order-status');
});

// Payments
Route::middleware(['auth:sanctum'])->prefix('transaction')->group(function () {
    Route::get('/verify', [TransactionsController::class, 'verifyPayment']);
    Route::get('/my-transactions', [TransactionsController::class, 'getMyTransactions'])->name('my-transactions');
    Route::middleware(['role:admin,superadmin'])->get('/all', [TransactionsController::class, 'getAllTransactions']);
    Route::get('/{id}', [TransactionsController::class, 'getTransaction']);/*->where('id', '[0-9]+');*/
});

// Analytics
Route::middleware(['auth:sanctum'])->prefix('analytics')->group(function () {
    Route::get('/', [AnalyticsController::class, 'index']);
});

// System Access permissions management routes // ['auth:sanctum'/*, 'role:admin,superadmin'*/]
Route::middleware(['auth:sanctum', 'role:admin,superadmin'])->prefix('admin')->group(function () {
    Route::prefix('app-versions-support')->group(function () {
        Route::get('/', [AppVersionController::class, 'index']);
        Route::post('/', [AppVersionController::class, 'store']);
        Route::put('/{id}', [AppVersionController::class, 'update']);
        Route::get('/{id}', [AppVersionController::class, 'show']);
        Route::delete('/{id}', [AppVersionController::class, 'destroy']);
    });

    // Handle subscription management
    Route::prefix('subscriptions')->group(function () {
        Route::get('/', [SubscriptionController::class, 'available'])->name('subscriptions');
        Route::get('/{id}', [SubscriptionController::class, 'show'])->name('subscription');
        Route::post('/', [SubscriptionController::class, 'store'])->name('create-subscription');
        Route::put('/{id}', [SubscriptionController::class, 'update'])->name('update-subscription');
        Route::delete('/{id}', [SubscriptionController::class, 'destroy'])->name('delete-subscription');
    });
});

Route::get('/auth/{provider}/redirect', [SocialAuthController::class, 'redirect']);
Route::get('/auth/{provider}/callback', [SocialAuthController::class, 'callback']);

/**!SECTION Social Auth */
Route::get('/auth/google', [SocialAuthController::class, 'redirectToProvider']);
Route::get('/auth/google/callback', [SocialAuthController::class, 'handleProviderCallback']);

//
// Route::get('migrate', function () {
//     Artisan::call('migrate');
//     $output = Artisan::output();
//     return response()->json([
//         'message' => 'Migrated successfully',
//         'code' => 200,
//         'output' => $output
//     ], 200);
// });

Route::get('migrate', function () {
    // Run migrations
    Artisan::call('migrate');
    $output = Artisan::output();

    // Get current DB connection host info
    try {
        $connectionStatus = DB::connection()->getPdo()->getAttribute(PDO::ATTR_CONNECTION_STATUS);
    } catch (\Exception $e) {
        $connectionStatus = 'Could not connect to database: ' . $e->getMessage();
    }

    return response()->json([
        'message' => 'Migration executed',
        'code' => 200,
        'output' => $output,
        'db_connection_status' => $connectionStatus
    ], 200);
});

Route::get('seed', function () {
    Artisan::call('db:seed');
    $output = Artisan::output();
     return response()->json([
        'message' => 'Seeder run successfully',
        'code' => 200,
        'output' => $output
    ], 200);
});

Route::get('clear', function () {
    Artisan::call('optimize:clear');
    $output = Artisan::output();
    return response()->json(
        [
            'message' => 'Cache cleared successfully',
            'code' => 200,
            'output' => $output
        ],
        200
    );
});

// Routes List
Route::get('routes', function () {
    Artisan::call('route:list');
    $output = Artisan::output();
    return response()->json([
        'data' => explode("\n", $output),
        'code' => 200,
        'message' => 'Routes listed successfully'
    ], 200);
});

Route::get('storage-link', function () {
    Artisan::call('storage:link');
    $output = Artisan::output();
     return response()->json([
        'message' => 'Storage linked successfully',
        'code' => 200,
        'output' => $output
    ], 200);
});

Route::get('optimize', function () {
    Artisan::call('optimize');
    $output = Artisan::output();
     return response()->json([
        'message' => 'Optimized successfully',
        'code' => 200,
        'output' => $output
    ], 200);
});

Route::get('key-generate', function () {
    Artisan::call('key:generate');
});

Route::get('/debug-db', function () {
    try {
        $data = DB::select("SELECT * FROM roles");

        return response()->json([
            'status' => 'success',
            'count' => count($data),
            'data' => $data
        ], 200);
    } catch (\Throwable $th) {
        return response()->json([
            'status' => 'error',
            'message' => $th->getMessage()
        ], 500);
    }
});
