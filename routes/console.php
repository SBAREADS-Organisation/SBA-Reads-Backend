<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Catch any authors whose Stripe KYC completed but whose webhook was missed or failed.
Schedule::command('kyc:sync-stripe')->daily();

// Reconcile every confirmed purchase against book_user, including linked accounts.
// Runs every 10 minutes so a failed real-time grant is healed within one cycle.
Schedule::command('library:heal')->everyTenMinutes();
