<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Custom scraper commands
Artisan::command('scraper:test', function () {
    $this->info('Testing scraper configuration...');

    // Test database connection
    try {
        DB::connection()->getPdo();
        $this->info('✓ Database connection: OK');
    } catch (Exception $e) {
        $this->error('✗ Database connection: FAILED');
        $this->error($e->getMessage());
        return;
    }

    // Test scraper configuration
    $platforms = config('scraper.platforms', []);
    $this->info('✓ Platforms configured: ' . count($platforms));

    foreach ($platforms as $key => $platform) {
        $this->line("  - {$platform['name']}: " . count($platform['category_urls']) . " URLs");
    }

    $this->info('✓ Scraper test completed successfully!');
})->purpose('Test scraper configuration and connectivity');

Artisan::command('scraper:install', function () {
    $this->info('Installing Data Scraper...');

    // Run migrations
    $this->call('migrate');

    // Clear caches
    $this->call('config:clear');
    $this->call('cache:clear');
    $this->call('view:clear');

    // Set up storage link if needed
    if (!file_exists(public_path('storage'))) {
        $this->call('storage:link');
    }

    $this->info('✓ Installation completed successfully!');
    $this->info('Next steps:');
    $this->line('1. Configure your .env file');
    $this->line('2. Set up cron job: ./setup-cron.sh');
    $this->line('3. Test scraper: php artisan scraper:test');
    $this->line('4. Run first scrape: php artisan scraper:run all --limit=10');
})->purpose('Install and set up the scraper application');
