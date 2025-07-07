<?php

namespace App\Http;

use App\Http\Middleware\CheckAppVersion;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\AuthenticateWithBasicAuth;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Foundation\Http\Middleware\ValidatePostSize;
use Illuminate\Http\Middleware\SetCacheHeaders;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use App\Http\Middleware\RoleMiddleware;
// use App\Http\Middleware\CorsMiddleware;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Session\Middleware\StartSession as StartSessionMiddleware;
use Illuminate\Foundation\Http\Middleware\CheckForMaintenanceMode;
use Illuminate\Http\Middleware\TrustProxies;
// use Spatie\Permission\Middlewares\RoleMiddleware;
// use Spatie\Permission\Middlewares\PermissionMiddleware;
// use Spatie\Permission\Middlewares\RoleOrPermissionMiddleware;

class Kernel extends HttpKernel
{
    /**
     * The application's global HTTP middleware stack.
     * These middleware are run during every request to your application.
     */
    protected $middleware = [
        \Illuminate\Http\Middleware\HandleCors::class,
        TrustProxies::class,
        CheckForMaintenanceMode::class,
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSessionMiddleware::class,
        ShareErrorsFromSession::class,
        TrimStrings::class,
        ConvertEmptyStringsToNull::class,
    ];

    /**
     * The application's route middleware groups.
     */
    protected $middlewareGroups = [
        'web' => [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            TrimStrings::class,
            ConvertEmptyStringsToNull::class,
            SubstituteBindings::class,
        ],

        'api' => [
            EnsureFrontendRequestsAreStateful::class,
            'throttle:api',
            SubstituteBindings::class,
            // CorsMiddleware::class,
            CheckAppVersion::class, // Custom middleware for checking app version and ID
        ],
    ];

    /**
     * The application's route middleware.
     */
    protected $routeMiddleware = [
        'auth' => Authenticate::class,
        'auth.basic' => AuthenticateWithBasicAuth::class,
        'bindings' => SubstituteBindings::class,
        'cache.headers' => SetCacheHeaders::class,
        'can' => Authorize::class,
        // 'guest' => \App\Http\Middleware\RedirectIfAuthenticated::class,
        'password.confirm' => \Illuminate\Auth\Middleware\RequirePassword::class,
        'signed' => ValidateSignature::class,
        'throttle' => ThrottleRequests::class,
        'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
        // 'role' => \App\Http\Middleware\RoleMiddleware::class, // Custom role-based access control middleware
        // 'role' => RoleMiddleware::class,
        // 'permission' => PermissionMiddleware::class,
        // 'role_or_permission' => RoleOrPermissionMiddleware::class,
        'check.app.version' => CheckAppVersion::class,
    ];

    /**!SECTION
     *
     * x-app-version: 2.1.0
x-device-id: a1b2c3d4e5f6
x-platform: android
x-app-id: com.example.myapp
Authorization: Bearer your_token_here
Content-Type: application/json

     */
}
