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
        // Main scraping schedule - every 2 days (48 hours)
        $schedule->command('scraper:run all')
                 ->twiceDaily(2, 14) // Run at 2 AM and 2 PM
                 ->withoutOverlapping(7200) // Prevent overlap, max 2 hours
                 ->runInBackground()
                 ->emailOutputOnFailure(config('mail.admin_email'))
                 ->appendOutputTo(storage_path('logs/scraper-schedule.log'));

        // Alternative schedule option - every 48 hours exactly
        // $schedule->command('scraper:run all')
        //          ->cron('0 2 */2 * *') // Every 2 days at 2 AM
        //          ->withoutOverlapping(7200)
        //          ->runInBackground();

        // Cleanup old data weekly
        $schedule->command('scraper:cleanup')
                 ->weekly()
                 ->sundays()
                 ->at('03:00')
                 ->appendOutputTo(storage_path('logs/cleanup.log'));

        // Generate daily status report
        $schedule->command('scraper:status --detailed')
                 ->daily()
                 ->at('06:00')
                 ->appendOutputTo(storage_path('logs/daily-status.log'));

        // Health check every hour
        $schedule->command('scraper:status')
                 ->hourly()
                 ->between('8:00', '22:00') // Only during business hours
                 ->appendOutputTo(storage_path('logs/health-check.log'));

        // Platform-specific schedules (if needed for different intervals)
        
        // Amazon - more frequent due to high volume
        $schedule->command('scraper:run amazon')
                 ->daily()
                 ->at('01:00')
                 ->withoutOverlapping(3600)
                 ->when(function () {
                     return config('scraper.platforms.amazon.enabled', true);
                 });

        // Flipkart - daily
        $schedule->command('scraper:run flipkart')
                 ->daily()
                 ->at('03:00')
                 ->withoutOverlapping(3600)
                 ->when(function () {
                     return config('scraper.platforms.flipkart.enabled', true);
                 });

        // Other platforms - every 2 days
        $schedule->command('scraper:run vijaysales')
                 ->weekly(1, 4, '05:00') // Monday and Thursday
                 ->withoutOverlapping(1800);

        $schedule->command('scraper:run reliancedigital')
                 ->weekly(2, 5, '05:00') // Tuesday and Friday
                 ->withoutOverlapping(1800);

        $schedule->command('scraper:run croma')
                 ->weekly(3, 6, '05:00') // Wednesday and Saturday
                 ->withoutOverlapping(1800);

        // Emergency cleanup if disk space is low
        $schedule->call(function () {
            $diskUsage = disk_free_space('/') / disk_total_space('/');
            if ($diskUsage < 0.1) { // Less than 10% free space
                \Artisan::call('scraper:cleanup', ['--logs' => 7, '--inactive' => 30]);
            }
        })->hourly();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }

    /**
     * Get the timezone that should be used by default for scheduled events.
     */
    protected function scheduleTimezone(): string
    {
        return config('app.timezone', 'UTC');
    }
}

