<?php

use App\Services\Creators\CreatorAlerts;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// All scheduled jobs live here. (Laravel 11+ does not read app/Console/Kernel.php, so jobs
// listed only there never ran.) Check with: php artisan schedule:list

// Catch any authors whose Stripe KYC completed but whose webhook was missed or failed.
Schedule::command('kyc:sync-stripe')->daily();

// Reconcile every confirmed purchase against book_user, including linked accounts.
// Runs every 10 minutes so a failed real-time grant is healed within one cycle.
Schedule::command('library:heal')->everyTenMinutes();

Schedule::command('subscriptions:expire')->daily();

// Sync purchases, views, bookmarks, reads into book_meta_data_analytics
// so the trending and top-picks classifications always reflect real data.
Schedule::command('books:sync-analytics')->dailyAt('00:30')->withoutOverlapping()->runInBackground();

// Grant library access to preordered books whose release date has now passed.
Schedule::command('books:release-preorders')->hourly()->withoutOverlapping()->runInBackground();

// SBA Creators: warn before a trial or paid plan ends, and confirm when it has.
Schedule::command('creators:plan-reminders')->dailyAt('09:00')->withoutOverlapping()
    ->onFailure(fn () => CreatorAlerts::send('The plan reminder job failed'));

// SBA Creators memberships: remind members before and after they end.
Schedule::command('creators:membership-reminders')->dailyAt('09:15')->withoutOverlapping()
    ->onFailure(fn () => CreatorAlerts::send('The membership reminder job failed'));

// SBA Creators: automatic membership renewals and instalments due (each charge is attempted once per due date).
Schedule::command('creators:charge-due')->hourly()->withoutOverlapping()
    ->onFailure(fn () => CreatorAlerts::send('The renewals and instalments job failed'));

// Wallets: every morning, check the Paystack balance covers what authors are owed.
Schedule::command('wallet:check-balance')->dailyAt('07:00')->withoutOverlapping()
    ->onFailure(fn () => CreatorAlerts::send('The daily wallet balance check failed'));
