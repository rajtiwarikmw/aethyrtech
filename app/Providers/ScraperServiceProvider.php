<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\DatabaseService;
use App\Services\Scrapers\AmazonScraper;
use App\Services\Scrapers\FlipkartScraper;
use App\Services\Scrapers\VijaySalesScraper;
use App\Services\Scrapers\RelianceDigitalScraper;
use App\Services\Scrapers\CromaScraper;

class ScraperServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register DatabaseService as singleton
        $this->app->singleton(DatabaseService::class, function ($app) {
            return new DatabaseService();
        });

        // Register scrapers
        $this->app->bind('scraper.amazon', function ($app) {
            return new AmazonScraper();
        });

        $this->app->bind('scraper.flipkart', function ($app) {
            return new FlipkartScraper();
        });

        $this->app->bind('scraper.vijaysales', function ($app) {
            return new VijaySalesScraper();
        });

        $this->app->bind('scraper.reliancedigital', function ($app) {
            return new RelianceDigitalScraper();
        });

        $this->app->bind('scraper.croma', function ($app) {
            return new CromaScraper();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish configuration files
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/scraper.php' => config_path('scraper.php'),
            ], 'scraper-config');
        }

        // Register custom validation rules if needed
        $this->registerValidationRules();

        // Set up logging configuration
        $this->configureLogging();
    }

    /**
     * Register custom validation rules
     */
    protected function registerValidationRules(): void
    {
        // Custom validation rule for SKU format
        \Validator::extend('valid_sku', function ($attribute, $value, $parameters, $validator) {
            return preg_match('/^[A-Z0-9\-_]{3,50}$/i', $value);
        });

        // Custom validation rule for price range
        \Validator::extend('valid_price', function ($attribute, $value, $parameters, $validator) {
            $min = $parameters[0] ?? 1000;
            $max = $parameters[1] ?? 1000000;
            return is_numeric($value) && $value >= $min && $value <= $max;
        });

        // Custom validation rule for rating
        \Validator::extend('valid_rating', function ($attribute, $value, $parameters, $validator) {
            return is_numeric($value) && $value >= 0 && $value <= 5;
        });
    }

    /**
     * Configure logging for scraper
     */
    protected function configureLogging(): void
    {
        $config = config('logging.channels');
        
        // Add scraper-specific log channel
        $config['scraper'] = [
            'driver' => 'daily',
            'path' => storage_path('logs/scraper.log'),
            'level' => config('scraper.logging.level', 'info'),
            'days' => config('scraper.logging.retention_days', 30),
        ];

        config(['logging.channels' => $config]);
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            DatabaseService::class,
            'scraper.amazon',
            'scraper.flipkart',
            'scraper.vijaysales',
            'scraper.reliancedigital',
            'scraper.croma',
        ];
    }
}

