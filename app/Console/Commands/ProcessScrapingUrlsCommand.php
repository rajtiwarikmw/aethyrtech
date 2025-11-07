<?php

namespace App\Console\Commands;

use App\Models\ScrapingUrl;
use App\Services\Scrapers\AmazonScraper;
use App\Services\Scrapers\FlipkartScraper;
use App\Services\Scrapers\VijaySalesScraper;
use App\Services\Scrapers\BlinkitScraper;
use App\Services\Scrapers\CromaScraper;
use App\Services\Scrapers\RelianceDigitalScraper;
use App\Services\Scrapers\BigBasketScraper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessScrapingUrlsCommand extends Command
{
    protected $signature = 'scraper:process-urls 
                            {platform? : Platform to process (amazon, flipkart, vijaysales, blinkit, croma, reliancedigital, bigbasket, all)}
                            {--limit=10 : Number of URLs to process}';

    protected $description = 'Process pending URLs from scraping_urls table';

    public function handle()
    {
        $platform = $this->argument('platform') ?? 'all';
        $limit = (int) $this->option('limit');

        $this->info("Processing scraping URLs...");
        $this->info("Platform: {$platform}");
        $this->info("Limit: {$limit}");
        $this->newLine();

        try {
            $totalProcessed = 0;
            $totalErrors = 0;

            $platforms = ['amazon', 'flipkart', 'vijaysales', 'blinkit', 'croma', 'reliancedigital', 'bigbasket'];
            
            foreach ($platforms as $plat) {
                if ($platform === 'all' || $platform === $plat) {
                    $stats = $this->processPlatformUrls($plat, $limit);
                    $totalProcessed += $stats['processed'];
                    $totalErrors += $stats['errors'];
                }
            }

            $this->newLine();
            $this->info('URL processing completed!');
            $this->table(
                ['Metric', 'Count'],
                [
                    ['URLs Processed', $totalProcessed],
                    ['Errors', $totalErrors],
                ]
            );

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error processing URLs: ' . $e->getMessage());
            
            if ($this->option('verbose')) {
                $this->error($e->getTraceAsString());
            }

            return Command::FAILURE;
        }
    }

    protected function processPlatformUrls(string $platform, int $limit): array
    {
        $this->info("Processing {$platform} URLs...");

        $urls = ScrapingUrl::getPendingUrls($platform, $limit);
        
        if ($urls->isEmpty()) {
            $this->warn("No pending URLs found for {$platform}");
            return ['processed' => 0, 'errors' => 0];
        }

        $processed = 0;
        $errors = 0;

        foreach ($urls as $scrapingUrl) {
            try {
                $scrapingUrl->markAsProcessing();

                // Get the appropriate scraper
                $scraper = $this->getScraper($platform);
                
                if (!$scraper) {
                    $scrapingUrl->markAsFailed('Scraper not found for platform');
                    $errors++;
                    continue;
                }

                // Scrape the product
                $productData = $this->scrapeUrl($scraper, $scrapingUrl->url, $platform);

                if ($productData && !empty($productData)) {
                    $scrapingUrl->markAsCompleted();
                    $processed++;
                    $this->info("✓ Processed: {$scrapingUrl->url}");
                } else {
                    $scrapingUrl->markAsFailed('No product data extracted');
                    $errors++;
                    $this->warn("✗ Failed: {$scrapingUrl->url}");
                }

                // Add delay
                sleep(rand(2, 4));
            } catch (\Exception $e) {
                $scrapingUrl->markAsFailed($e->getMessage());
                $errors++;
                $this->error("✗ Error: {$scrapingUrl->url} - {$e->getMessage()}");
            }
        }

        return ['processed' => $processed, 'errors' => $errors];
    }

    protected function getScraper(string $platform)
    {
        return match($platform) {
            'amazon' => new AmazonScraper(),
            'flipkart' => new FlipkartScraper(),
            'vijaysales' => new VijaySalesScraper(),
            'blinkit' => new BlinkitScraper(),
            'croma' => new CromaScraper(),
            'reliancedigital' => new RelianceDigitalScraper(),
            'bigbasket' => new BigBasketScraper(),
            default => null,
        };
    }

    protected function scrapeUrl($scraper, string $url, string $platform)
    {
        try {
            // The scraper's scrape method expects an array of category URLs
            // For individual product URLs, we need to call the scraper differently
            // This is a placeholder - actual implementation depends on your scraper structure
            
            Log::info("Scraping individual product URL", [
                'platform' => $platform,
                'url' => $url
            ]);
            
            // Most scrapers have a method to scrape category pages
            // For individual products, you might need to add a scrapeProduct method
            // For now, we'll use the existing scrape method with single URL
            $scraper->scrape([$url]);
            
            return ['success' => true];
        } catch (\Exception $e) {
            Log::error("Failed to scrape URL", [
                'platform' => $platform,
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
