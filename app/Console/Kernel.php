<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('subscriptions:expire')->daily();

        // Sync purchases, views, bookmarks, reads into book_meta_data_analytics
        // so the trending and top-picks classifications always reflect real data.
        $schedule->command('books:sync-analytics')
            ->dailyAt('00:30')
            ->withoutOverlapping()
            ->runInBackground();

        // Grant library access to preordered books whose release date has now passed.
        $schedule->command('books:release-preorders')
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground();

        // SBA Creators: warn before a trial or paid plan ends, and confirm when it has.
        $schedule->command('creators:plan-reminders')
            ->dailyAt('09:00')
            ->withoutOverlapping()
            ->onFailure(fn () => \App\Services\Creators\CreatorAlerts::send('The plan reminder job failed'));

        // SBA Creators memberships don't renew by themselves: remind members before and after they end.
        $schedule->command('creators:membership-reminders')
            ->dailyAt('09:15')
            ->withoutOverlapping()
            ->onFailure(fn () => \App\Services\Creators\CreatorAlerts::send('The membership reminder job failed'));

        // SBA Creators: automatic membership renewals and instalments due (each charge is attempted once per due date).
        $schedule->command('creators:charge-due')
            ->hourly()
            ->withoutOverlapping()
            ->onFailure(fn () => \App\Services\Creators\CreatorAlerts::send('The renewals and instalments job failed'));
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
        require base_path('routes/console.php');
    }
}
