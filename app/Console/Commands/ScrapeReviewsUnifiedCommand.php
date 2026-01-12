<?php

namespace App\Console\Commands;

use App\Services\Scrapers\AmazonReviewScraper;
use App\Services\Scrapers\FlipkartReviewScraper;
use App\Services\Scrapers\VijaySalesReviewScraper;
use App\Services\Scrapers\RelianceDigitalReviewScraper;
use App\Services\Scrapers\CromaReviewScraper;
use Illuminate\Console\Command;

class ScrapeReviewsUnifiedCommand extends Command
{
    protected $signature = 'scraper:reviews-platform 
                            {platform : Platform to scrape (amazon, flipkart, vijaysales, reliancedigital, croma, all)}
                            {--product-ids=* : Specific product IDs to scrape}
                            {--limit= : Limit number of products to scrape}';

    protected $description = 'Scrape product reviews for specified platform(s)';

    public function handle()
    {
        $platform = $this->argument('platform');
        $productIds = $this->option('product-ids');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        $this->info("Starting review scraping for: {$platform}");
        $this->newLine();

        try {
            $stats = [];

            if ($platform === 'all' || $platform === 'amazon') {
                $this->info('Scraping Amazon reviews...');
                $scraper = new AmazonReviewScraper();
                $stats['amazon'] = $scraper->scrapeReviews(
                    !empty($productIds) ? array_map('intval', $productIds) : null,
                    $limit
                );
            }

            if ($platform === 'all' || $platform === 'flipkart') {
                $this->info('Scraping Flipkart reviews...');
                $scraper = new FlipkartReviewScraper();
                $stats['flipkart'] = $scraper->scrapeReviews(
                    !empty($productIds) ? array_map('intval', $productIds) : null,
                    $limit
                );
            }

            if ($platform === 'all' || $platform === 'vijaysales') {
                $this->info('Scraping VijaySales reviews...');
                $scraper = new VijaySalesReviewScraper();
                $stats['vijaysales'] = $scraper->scrapeReviews(
                    !empty($productIds) ? array_map('intval', $productIds) : null,
                    $limit
                );
            }

            if ($platform === 'all' || $platform === 'reliancedigital') {
                $this->info('Scraping RelianceDigital reviews...');
                $scraper = new RelianceDigitalReviewScraper();
                $stats['reliancedigital'] = $scraper->scrapeAllReviews(
                    !empty($productIds) ? array_map('intval', $productIds) : null,
                    $limit
                );
            }

            if ($platform === 'all' || $platform === 'croma') {
                $this->info('Scraping croma reviews...');
                $scraper = new CromaReviewScraper();
                $stats['croma'] = $scraper->scrapeAllReviews(
                    !empty($productIds) ? array_map('intval', $productIds) : null,
                    $limit
                );
            }

            $this->newLine();
            $this->info('Review scraping completed!');
            $this->newLine();

            // Display statistics
            foreach ($stats as $plat => $stat) {
                $this->info(strtoupper($plat) . ' Statistics:');
                $this->table(
                    ['Metric', 'Count'],
                    [
                        ['Products Processed', $stat['products_processed']],
                        ['Reviews Found', $stat['reviews_found']],
                        ['Reviews Added', $stat['reviews_added']],
                        ['Reviews Updated', $stat['reviews_updated']],
                        ['Errors', $stat['errors_count']],
                    ]
                );
                $this->newLine();
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error during review scraping: ' . $e->getMessage());
            
            if ($this->getOutput()->isVerbose()) {
                $this->error($e->getTraceAsString());
            }

            return Command::FAILURE;
        }
    }
}
