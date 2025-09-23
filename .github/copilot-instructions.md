# Copilot Instructions for SBA Reads Backend

## Project Overview

This is a Laravel 12 digital book platform with dual payment providers (Stripe for international, Paystack for African markets), author payouts, and comprehensive book management. Uses Service-Repository pattern with Controllers delegating to Services.

## Architecture Patterns

### Service Layer Structure

-   **Controllers** validate input and delegate to Services (e.g., `BookController` → `BookService`)
-   **Services** contain business logic in `app/Services/` (organized by domain: `Book/`, `Payments/`, `Withdrawal/`)
-   **Models** use relationships and observers (see `Transaction` model observers for cache invalidation)
-   **Jobs** handle async processing (`ProcessAuthorPayout`, `ProcessWithdrawal`)

### Payment Provider Selection

Currency-based automatic selection:

-   USD/EUR/GBP/CAD/AUD → Stripe
-   NGN/GHS/KES/ZAR → Paystack

```php
// In PaymentService.php
$provider = $this->getPaymentProvider($data->currency ?? 'USD');
```

### Key Domain Boundaries

-   **Books**: Creation, purchasing, digital library management
-   **Payments**: Dual-provider system with webhooks
-   **Withdrawals**: Author earnings via Stripe Connect/Paystack transfers
-   **Users**: Role-based (reader/author/admin) with KYC verification

## Critical Development Workflows

### Running the Application

```bash
# Development with concurrent processes
composer run dev
# Runs: Laravel server, queue worker, Pail logging, Vite

# Manual setup
php artisan serve
php artisan queue:listen --tries=1
npm run dev
```

### Payment Integration

-   Book purchases create `DigitalBookPurchase` with 70% author payout calculation
-   Webhooks update transaction status and trigger `ProcessAuthorPayout` job
-   Authors get immediate wallet credits, withdrawals via Stripe Connect

### Database Migrations

-   UUID primary keys for `withdrawals`, `transactions`
-   JSON columns for flexible metadata (`payout_data`, `meta_data`)
-   Soft deletes on core models (`Book`, `User`)

## Request Validation Patterns

### Form Requests Structure

```php
// Pattern: app/Http/Requests/{Domain}/{Action}Request.php
// Example: StripePayoutRequest, WithdrawalRequest
public function authorize(): bool {
    return auth()->check() && auth()->user()->account_type === 'author';
}
```

### Validation Rules

-   Amounts: `'required|numeric|min:1'` (with currency-specific minimums)
-   Currency: `'required|string|size:3|in:usd,eur,gbp,ngn'`
-   UUIDs for primary keys, references for foreign keys

## Integration Points

### External Services

-   **Stripe Connect**: Author payouts, international payments
-   **Paystack**: African market payments and transfers
-   **Cloudinary**: Book cover and PDF storage
-   **Laravel Sanctum**: API authentication

### Webhooks

-   Stripe: `/api/stripe/webhook` → `StripeWebhookController`
-   Paystack: `/api/paystack/webhook` → `PaystackPaymentController`
-   Both update transaction status and trigger payout jobs

### Cache Management

-   Dashboard cache auto-invalidation via model observers
-   `DashboardCacheService::clearAuthorDashboard()` on transaction updates

## File Patterns

### API Routes Organization

```php
// Grouped by domain with middleware
Route::middleware(['auth:sanctum'])->prefix('withdrawals')->group(function () {
    Route::post('/initiate', [WithdrawalController::class, 'initiate']);
});
```

### Service Dependencies

```php
// Constructor injection pattern
public function __construct(
    StripeConnectService $stripe,
    PaystackService $paystack
) {
    $this->stripe = $stripe;
    $this->paystack = $paystack;
}
```

## Testing & Documentation

-   PHPUnit config in `phpunit.xml`
-   Comprehensive API docs in `API_DOCUMENTATION.md`
-   Payment flow documentation in `PAYMENT_WITHDRAWAL_DOCUMENTATION.md`
-   Postman collection for endpoint testing

## Key Files for Context

-   `routes/api.php` - All endpoint definitions
-   `app/Services/Payments/PaymentService.php` - Payment provider logic
-   `app/Models/Book.php` - Core book model with pricing
-   `PAYMENT_WITHDRAWAL_DOCUMENTATION.md` - Complete payment flows
-   `composer.json` dev script - Multi-process development setup
