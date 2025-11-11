<?php

namespace App\Console\Commands;

use App\Services\Scrapers\AmazonReviewScraperWithAuth;
use Illuminate\Console\Command;

class ScrapeReviewsAuthCommand extends Command
{
    protected $signature = 'scraper:reviews-auth 
                            {--product-ids=* : Specific product IDs to scrape}
                            {--limit= : Limit number of products to scrape}';

    protected $description = 'Scrape Amazon product reviews using authenticated browser session (with cookies)';

    public function handle()
    {
        $this->info('Starting Amazon review scraping (Authenticated Browser mode)...');
        $this->info('This uses your Amazon login cookies to access reviews.');
        $this->newLine();

        // Check if cookies are configured
        $cookies = config('amazon_cookies.cookies', []);
        if (empty($cookies)) {
            $this->error('❌ No Amazon cookies configured!');
            $this->newLine();
            $this->warn('Please configure your Amazon cookies in config/amazon_cookies.php');
            $this->warn('See documentation for instructions on how to get cookies.');
            return 1;
        }

        $this->info('✅ Amazon cookies loaded: ' . count($cookies) . ' cookies');
        $this->newLine();

        $productIds = $this->option('product-ids');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        if (!empty($productIds)) {
            $this->info('Scraping reviews for specific product IDs: ' . implode(', ', $productIds));
        } elseif ($limit) {
            $this->info("Scraping reviews for {$limit} products");
        } else {
            $this->info('Scraping reviews for all products');
        }

        $this->newLine();

        $scraper = new AmazonReviewScraperWithAuth();
        $stats = $scraper->scrapeReviews(
            !empty($productIds) ? array_map('intval', $productIds) : null,
            $limit
        );

        $this->newLine();
        $this->info('Scraping completed!');
        $this->newLine();

        $this->table(
            ['Metric', 'Count'],
            [
                ['Products Processed', $stats['products_processed']],
                ['Reviews Found', $stats['reviews_found']],
                ['Reviews Added', $stats['reviews_added']],
                ['Reviews Updated', $stats['reviews_updated']],
                ['Errors', $stats['errors_count']],
            ]
        );

        // Show warning if no reviews found
        if ($stats['reviews_found'] === 0 && $stats['products_processed'] > 0) {
            $this->newLine();
            $this->warn('⚠️  No reviews found. Possible reasons:');
            $this->warn('   1. Cookies may be expired - login to Amazon again and update cookies');
            $this->warn('   2. Products may not have reviews');
            $this->warn('   3. Amazon may be blocking requests');
            $this->newLine();
            $this->info('Check logs for details: tail -f storage/logs/laravel.log');
        }

        return 0;
    }
}
