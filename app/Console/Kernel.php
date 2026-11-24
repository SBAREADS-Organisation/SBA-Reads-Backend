<?php

namespace App\Console;

use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

/**
 * Not used for scheduling: Laravel 11+ reads scheduled jobs from routes/console.php only.
 * Add new scheduled jobs there (check with: php artisan schedule:list).
 */
class Kernel extends ConsoleKernel
{
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
        require base_path('routes/console.php');
    }
}
