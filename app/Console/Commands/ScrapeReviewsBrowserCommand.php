<?php

namespace App\Console\Commands;

use App\Services\Scrapers\AmazonReviewScraperBrowser;
use Illuminate\Console\Command;

class ScrapeReviewsBrowserCommand extends Command
{
    protected $signature = 'scraper:reviews-browser 
                            {--product-ids=* : Specific product IDs to scrape}
                            {--limit= : Limit number of products to scrape}';

    protected $description = 'Scrape Amazon product reviews using browser (bypasses anti-bot protection)';

    public function handle()
    {
        $this->info('Starting Amazon review scraping (Browser mode)...');
        $this->info('This uses a real browser to bypass Amazon\'s anti-bot protection.');
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

        $scraper = new AmazonReviewScraperBrowser();
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

        return 0;
    }
}
