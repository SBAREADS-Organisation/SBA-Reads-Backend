<?php

use Illuminate\Support\Facades\Route;
use App\Mail\Onboarding\StripeOnboardingMail;
use App\Models\User;

Route::get('/', function () {
    return response()->json([
        'message' => 'SBA Reads API is running successfully!',
        'status' => 'healthy',
        'timestamp' => now()->toISOString()
    ]);
});

Route::get('/test-form', function () {
    return view('new');
});

// Preview the StripeOnboardingMail Markdown template in the browser.
// Usage: /preview/mail/stripe-onboarding?name=Jane%20Doe&email=jane@example.com&url=https%3A%2F%2Fconnect.stripe.com%2Fsetup%2F...
Route::get('/preview/mail/stripe-onboarding', function () {
    $name = request('name', 'John Doe');
    $email = request('email', 'john@example.com');
    $url = request('url', config('app.url') . '/onboarding/stripe/example');

    $user = new User([
        'name' => $name,
        'email' => $email,
        'account_type' => 'author',
    ]);

    return (new StripeOnboardingMail($user, $url))->render();
});
