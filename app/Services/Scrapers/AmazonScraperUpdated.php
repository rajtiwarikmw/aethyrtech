<?php

namespace App\Services\Scrapers;

use App\Models\Product;
use App\Models\ScrapingUrl;
use Illuminate\Support\Facades\Log;

/**
 * This trait adds weekly scraping logic to scrapers
 * If SKU exists and was scraped within 7 days, update it
 * If SKU exists but last scrape > 7 days, create new entry
 * If SKU doesn't exist, create new entry
 */
trait WeeklyScrapingLogic
{
    /**
     * Save or update product with weekly logic
     */
    protected function saveProductWithWeeklyLogic(array $productData, string $platform): void
    {
        try {
            if (empty($productData['sku'])) {
                Log::warning("Product data missing SKU, skipping save");
                return;
            }

            // Find existing product
            $existingProduct = Product::where('platform', $platform)
                ->where('sku', $productData['sku'])
                ->first();

            if (!$existingProduct) {
                // Product doesn't exist, create new
                $productData['platform'] = $platform;
                $productData['scraped_date'] = now();
                Product::create($productData);
                
                Log::info("Created new product", [
                    'platform' => $platform,
                    'sku' => $productData['sku']
                ]);
            } else {
                // Product exists, check last scrape date
                $lastScrapedDate = $existingProduct->scraped_date;
                $daysSinceLastScrape = $lastScrapedDate ? now()->diffInDays($lastScrapedDate) : 999;

                if ($daysSinceLastScrape < 7) {
                    // Scraped within last 7 days, update existing record
                    $productData['scraped_date'] = now();
                    $existingProduct->update($productData);
                    
                    Log::info("Updated existing product (within 7 days)", [
                        'platform' => $platform,
                        'sku' => $productData['sku'],
                        'days_since_last_scrape' => $daysSinceLastScrape
                    ]);
                } else {
                    // Last scrape > 7 days ago, create new entry
                    // First, mark old product as inactive
                    $existingProduct->update(['is_active' => false]);
                    
                    // Create new product entry
                    $productData['platform'] = $platform;
                    $productData['scraped_date'] = now();
                    $productData['is_active'] = true;
                    Product::create($productData);
                    
                    Log::info("Created new product entry (old data > 7 days)", [
                        'platform' => $platform,
                        'sku' => $productData['sku'],
                        'days_since_last_scrape' => $daysSinceLastScrape
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error("Failed to save product with weekly logic", [
                'platform' => $platform,
                'sku' => $productData['sku'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Process URLs from scraping_urls table
     */
    protected function processScrapingUrls(string $platform, int $limit = null): array
    {
        $stats = [
            'urls_processed' => 0,
            'products_created' => 0,
            'products_updated' => 0,
            'errors' => 0,
        ];

        $urls = ScrapingUrl::getPendingUrls($platform, $limit);

        Log::info("Processing scraping URLs", [
            'platform' => $platform,
            'count' => $urls->count()
        ]);

        foreach ($urls as $scrapingUrl) {
            try {
                $scrapingUrl->markAsProcessing();

                // Scrape the URL (this method should be implemented in the scraper)
                $productData = $this->scrapeProductUrl($scrapingUrl->url);

                if ($productData && !empty($productData)) {
                    $this->saveProductWithWeeklyLogic($productData, $platform);
                    $scrapingUrl->markAsCompleted();
                    $stats['urls_processed']++;
                } else {
                    $scrapingUrl->markAsFailed('No product data extracted');
                    $stats['errors']++;
                }

                // Add delay
                $this->randomDelay(2, 4);
            } catch (\Exception $e) {
                $scrapingUrl->markAsFailed($e->getMessage());
                $stats['errors']++;
                
                Log::error("Failed to process scraping URL", [
                    'url_id' => $scrapingUrl->id,
                    'url' => $scrapingUrl->url,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $stats;
    }

    /**
     * Scrape a single product URL
     * This should be implemented by the specific scraper
     */
    abstract protected function scrapeProductUrl(string $url): ?array;

    /**
     * Random delay (should be implemented in scraper or use this default)
     */
    protected function randomDelay(int $min = 2, int $max = 5): void
    {
        if (method_exists($this, 'randomDelay')) {
            return; // Use scraper's own implementation
        }
        
        $delay = rand($min * 1000000, $max * 1000000);
        usleep($delay);
    }
}
