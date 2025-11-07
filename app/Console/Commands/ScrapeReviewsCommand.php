<?php

namespace App\Console\Commands;

use App\Services\Scrapers\AmazonReviewScraper;
use Illuminate\Console\Command;

class ScrapeReviewsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scraper:reviews 
                            {--product-ids=* : Specific product IDs to scrape reviews for}
                            {--limit= : Limit number of products to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scrape reviews for Amazon products';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting Amazon review scraping...');
        $this->newLine();

        $productIds = $this->option('product-ids');
        $limit = $this->option('limit');

        if ($productIds) {
            $this->info('Scraping reviews for specific product IDs: ' . implode(', ', $productIds));
        } elseif ($limit) {
            $this->info("Scraping reviews for up to {$limit} products");
        } else {
            $this->info('Scraping reviews for all Amazon products');
        }

        $this->newLine();

        try {
            $scraper = new AmazonReviewScraper();
            
            $stats = $scraper->scrapeReviews(
                $productIds ?: null,
                $limit ? (int) $limit : null
            );

            $this->newLine();
            $this->info('Review scraping completed!');
            $this->newLine();

            // Display statistics
            $this->displayStats($stats);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error during review scraping: ' . $e->getMessage());
            
            if ($this->option('verbose')) {
                $this->error($e->getTraceAsString());
            }

            return Command::FAILURE;
        }
    }

    /**
     * Display scraping statistics
     */
    protected function displayStats(array $stats): void
    {
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
    }
}
