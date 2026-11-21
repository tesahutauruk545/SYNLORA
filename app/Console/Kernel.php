<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Jalankan `php artisan schedule:run` tiap menit (Windows: Task Scheduler, lihat README).
        $schedule->command('synlora:backup --keep=14')->dailyAt('02:00')->withoutOverlapping()->onOneServer();
        $schedule->command('synlora:check-integrity --fix')->dailyAt('02:30')->withoutOverlapping();
        $schedule->command('synlora:prune-logs')->weeklyOn(0, '03:00')->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
