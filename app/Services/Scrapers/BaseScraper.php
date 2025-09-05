<?php

namespace App\Services\Scrapers;

use App\Models\Product;
use App\Models\ScrapingLog;
use App\Services\BrowserService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

abstract class BaseScraper
{
    protected Client $httpClient;
    protected BrowserService $browserService;
    protected ScrapingLog $scrapingLog;
    protected string $platform;
    protected bool $useJavaScript = false;
    protected array $paginationConfig = [];
    protected array $stats = [
        'products_found' => 0,
        'products_updated' => 0,
        'products_added' => 0,
        'products_deactivated' => 0,
        'errors_count' => 0
    ];

    protected float $startTime;
    protected int $maxExecutionTime = 3600; // 1 hour default limit
    protected ?\App\Services\ProxyRotator $proxyRotator = null;

    public function __construct(string $platform)
    {
        $this->platform = $platform;
        $this->startTime = microtime(true);
        $this->maxExecutionTime = config('scraper.schedule.max_execution_time', 3600); // Use config value

        // Set PHP execution time limit
        set_time_limit($this->maxExecutionTime + 300); // Add 5 minutes buffer

        // Initialize proxy rotator if proxies are configured
        $this->proxyRotator = new \App\Services\ProxyRotator();
        if (!$this->proxyRotator->hasProxies()) {
            $this->proxyRotator = null;
        }

        $this->browserService = new BrowserService();
        $this->initializeHttpClient();
        $this->setupPlatformConfig();
    }

    /**
     * Check if execution time limit is reached
     */
    protected function isExecutionTimeLimitReached(): bool
    {
        $currentTime = microtime(true);
        $elapsedTime = $currentTime - $this->startTime;

        if ($elapsedTime > $this->maxExecutionTime) {
            Log::warning("Execution time limit reached", [
                'platform' => $this->platform,
                'elapsed_time' => round($elapsedTime, 2),
                'max_time' => $this->maxExecutionTime
            ]);
            return true;
        }

        return false;
    }

    /**
     * Initialize HTTP client with proper configuration
     */
    protected function initializeHttpClient(): void
    {
        $this->httpClient = new Client([
            'timeout' => config('scraper.timeout', 30),
            'headers' => [
                'User-Agent' => config('scraper.user_agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'),
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
                'Accept-Encoding' => 'gzip, deflate',
                'Connection' => 'keep-alive',
                'Upgrade-Insecure-Requests' => '1',
            ],
            'verify' => false,
            'allow_redirects' => true,
            'http_errors' => false
        ]);
    }

    /**
     * Main scraping method with pagination support
     */
    public function scrape(array $categoryUrls): void
    {
        $this->scrapingLog = ScrapingLog::startSession($this->platform);

        try {
            Log::info("Starting scraping for platform: {$this->platform}");

            foreach ($categoryUrls as $categoryUrl) {
                if ($this->useJavaScript) {
                    $this->scrapeCategoryWithBrowser($categoryUrl);
                } else {
                    $this->scrapeCategoryWithPagination($categoryUrl);
                }
            }

            $this->deactivateOldProducts();
            $this->scrapingLog->complete($this->stats);

            Log::info("Completed scraping for platform: {$this->platform}", $this->stats);
        } catch (\Exception $e) {
            $this->scrapingLog->fail($e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ], $this->stats);

            Log::error("Scraping failed for platform: {$this->platform}", [
                'error' => $e->getMessage(),
                'stats' => $this->stats
            ]);

            throw $e;
        }
    }

    /**
     * Scrape category with pagination support
     */
    protected function scrapeCategoryWithPagination(string $categoryUrl): void
    {
        $currentPage = 1;
        $maxPages = $this->paginationConfig['max_pages'] ?? 50;
        $noProductsCount = 0;
        $maxNoProductsPages = 3;
        $consecutiveErrors = 0;
        $maxConsecutiveErrors = $this->paginationConfig['max_consecutive_errors'] ?? 3;
        $failedPages = [];

        while ($currentPage <= $maxPages && $noProductsCount < $maxNoProductsPages && $consecutiveErrors < $maxConsecutiveErrors) {
            // Check execution time limit
            if ($this->isExecutionTimeLimitReached()) {
                Log::warning("Stopping pagination due to execution time limit");
                break;
            }

            $pageUrl = $this->buildPageUrl($categoryUrl, $currentPage);

            Log::info("Scraping page {$currentPage} for {$this->platform}", [
                'url' => $pageUrl,
                'page' => $currentPage,
                'consecutive_errors' => $consecutiveErrors
            ]);

            try {
                $pageProductCount = $this->scrapeCategoryPage($pageUrl);

                if ($pageProductCount === 0) {
                    $noProductsCount++;
                    Log::info("No products found on page {$currentPage}, count: {$noProductsCount}");
                } else {
                    $noProductsCount = 0; // Reset counter
                    $consecutiveErrors = 0; // Reset error counter on success
                }

                // Check if we should continue
                if (!$this->shouldContinuePagination($pageProductCount, $currentPage)) {
                    Log::info("Stopping pagination at page {$currentPage}");
                    break;
                }
            } catch (\Exception $e) {
                $consecutiveErrors++;
                $failedPages[] = $currentPage;

                Log::warning("Error on page {$currentPage}: {$e->getMessage()}", [
                    'page' => $currentPage,
                    'consecutive_errors' => $consecutiveErrors,
                    'error' => $e->getMessage()
                ]);

                // If we have retry enabled and haven't exceeded max errors, continue
                if ($consecutiveErrors >= $maxConsecutiveErrors) {
                    Log::error("Too many consecutive errors ({$consecutiveErrors}), stopping pagination");
                    break;
                }
            }

            $currentPage++;

            // Use configured delay between pages
            $delayRange = $this->paginationConfig['delay_between_pages'] ?? [2, 5];
            $delay = is_array($delayRange) ? rand($delayRange[0], $delayRange[1]) : $delayRange;
            sleep($delay);
        }

        // Retry failed pages if configured
        if (!empty($failedPages) && ($this->paginationConfig['retry_failed_pages'] ?? false)) {
            $this->retryFailedPages($categoryUrl, $failedPages);
        }

        Log::info("Completed pagination for category", [
            'platform' => $this->platform,
            'total_pages' => $currentPage - 1,
            'failed_pages' => count($failedPages),
            'consecutive_errors' => $consecutiveErrors,
            'category_url' => $categoryUrl
        ]);
    }

    /**
     * Retry failed pages
     */
    protected function retryFailedPages(string $categoryUrl, array $failedPages): void
    {
        $maxRetries = $this->paginationConfig['max_retries_per_page'] ?? 2;

        Log::info("Retrying {count} failed pages", [
            'count' => count($failedPages),
            'platform' => $this->platform,
            'pages' => $failedPages
        ]);

        foreach ($failedPages as $pageNumber) {
            $pageUrl = $this->buildPageUrl($categoryUrl, $pageNumber);

            for ($retry = 1; $retry <= $maxRetries; $retry++) {
                try {
                    Log::info("Retrying page {$pageNumber}, attempt {$retry}");

                    $pageProductCount = $this->scrapeCategoryPage($pageUrl);

                    if ($pageProductCount > 0) {
                        Log::info("Successfully retried page {$pageNumber}, found {$pageProductCount} products");
                        break; // Success, move to next page
                    }
                } catch (\Exception $e) {
                    Log::warning("Retry {$retry} failed for page {$pageNumber}: {$e->getMessage()}");

                    if ($retry < $maxRetries) {
                        // Wait longer between retries
                        sleep(rand(5, 10));
                    }
                }
            }
        }
    }

    /**
     * Scrape category with browser automation for JS-rendered content
     */
    protected function scrapeCategoryWithBrowser(string $categoryUrl): void
    {
        try {
            if ($this->paginationConfig['type'] === 'infinite_scroll') {
                // Handle infinite scroll pages
                $content = $this->browserService->getInfiniteScrollContent(
                    $categoryUrl,
                    $this->paginationConfig['scroll_count'] ?? 10
                );

                if ($content) {
                    $this->processPageContent($content, $categoryUrl);
                }
            } else {
                // Handle regular pagination with JS
                $allPages = $this->browserService->getAllPagesContent($categoryUrl, $this->paginationConfig);

                foreach ($allPages as $pageData) {
                    $this->processPageContent($pageData['content'], $pageData['url']);
                }
            }
        } catch (\Exception $e) {
            $this->handleError("Failed to scrape with browser: {$categoryUrl}", $e);
        }
    }

    /**
     * Process page content and extract products
     */
    protected function processPageContent(string $html, string $pageUrl): int
    {
        try {
            $crawler = new Crawler($html);
            $productUrls = $this->extractProductUrls($crawler, $pageUrl);

            Log::info("Found {count} products on page", [
                'count' => count($productUrls),
                'platform' => $this->platform,
                'url' => $pageUrl
            ]);

            foreach ($productUrls as $productUrl) {
                $this->scrapeProductPage($productUrl);
                $this->randomDelay();
            }

            return count($productUrls);
        } catch (\Exception $e) {
            $this->handleError("Failed to process page content: {$pageUrl}", $e);
            return 0;
        }
    }

    /**
     * Scrape a category page to find product URLs
     */
    protected function scrapeCategoryPage(string $categoryUrl): int
    {
        try {
            $html = $this->fetchPage($categoryUrl);
            if (!$html) {
                return 0;
            }

            return $this->processPageContent($html, $categoryUrl);
        } catch (\Exception $e) {
            $this->handleError("Failed to scrape category page: {$categoryUrl}", $e);
            return 0;
        }
    }

    /**
     * Scrape individual product page
     */
    protected function scrapeProductPage(string $productUrl): void
    {
        try {
            $html = $this->useJavaScript ?
                $this->browserService->getPageContent($productUrl, 3) :
                $this->fetchPage($productUrl);

            if (!$html) {
                return;
            }

            $crawler = new Crawler($html);
            $productData = $this->extractProductData($crawler, $productUrl);

            if (!$productData || !isset($productData['sku'])) {
                Log::warning("No valid product data found", [
                    'platform' => $this->platform,
                    'url' => $productUrl
                ]);
                return;
            }

            $this->saveProductData($productData);
            $this->stats['products_found']++;
        } catch (\Exception $e) {
            $this->handleError("Failed to scrape product page: {$productUrl}", $e);
        }
    }

    /**
     * Build URL for specific page
     */
    protected function buildPageUrl(string $baseUrl, int $page): string
    {
        if ($page === 1) {
            return $baseUrl;
        }

        $pageParam = $this->paginationConfig['page_param'] ?? 'page';
        $separator = strpos($baseUrl, '?') !== false ? '&' : '?';

        return $baseUrl . $separator . $pageParam . '=' . $page;
    }

    /**
     * Check if should continue pagination
     */
    protected function shouldContinuePagination(int $productCount, int $currentPage): bool
    {
        // Stop if no products found
        if ($productCount === 0) {
            return false;
        }

        // Stop if too many errors
        if ($this->stats['errors_count'] > 10) {
            Log::warning("Too many errors, stopping pagination");
            return false;
        }

        // Platform-specific logic can override this
        return true;
    }

    /**
     * Fetch page content with retries
     */
    protected function fetchPage(string $url, int $retries = 3): ?string
    {
        for ($attempt = 1; $attempt <= $retries; $attempt++) {
            try {
                $options = [];

                // Add platform-specific headers if available, otherwise use randomized headers
                if (!empty($this->defaultHeaders)) {
                    $options['headers'] = $this->defaultHeaders;
                } else {
                    // Use randomized headers for better anti-blocking
                    $userAgentRotator = new \App\Services\UserAgentRotator();
                    $options['headers'] = $userAgentRotator->getRandomizedHeaders();
                }

                // Add proxy if available
                $currentProxy = null;
                if ($this->proxyRotator) {
                    $currentProxy = $this->proxyRotator->getNextProxy();
                    if ($currentProxy) {
                        $options['proxy'] = $currentProxy;
                        Log::debug("Using proxy for request", ['url' => $url, 'attempt' => $attempt]);
                    }
                }

                // Add timeout
                $options['timeout'] = 30;
                $options['connect_timeout'] = 10;

                $response = $this->httpClient->get($url, $options);

                if ($response->getStatusCode() === 200) {
                    return $response->getBody()->getContents();
                }

                Log::warning("HTTP error {status} for URL: {url}", [
                    'status' => $response->getStatusCode(),
                    'url' => $url,
                    'attempt' => $attempt,
                    'using_proxy' => $currentProxy ? 'yes' : 'no'
                ]);

                // Mark proxy as failed if we got a bad status code
                if ($currentProxy && $response->getStatusCode() >= 400) {
                    $this->proxyRotator->markProxyAsFailed($currentProxy);
                }
            } catch (RequestException $e) {
                Log::warning("Request failed for URL: {url} (Attempt {attempt})", [
                    'url' => $url,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                    'using_proxy' => $currentProxy ? 'yes' : 'no'
                ]);

                // Mark proxy as failed if the request failed
                if ($currentProxy) {
                    $this->proxyRotator->markProxyAsFailed($currentProxy);
                }
            }

            if ($attempt < $retries) {
                // Exponential backoff with randomization
                $delay = pow(2, $attempt) + rand(1, 3);
                sleep($delay);
            }
        }

        return null;
    }

    /**
     * Save product data to database
     */
    protected function saveProductData(array $productData): void
    {
        $productData['platform'] = $this->platform;
        $productData['scraped_date'] = now();

        $existingProduct = Product::findByPlatformAndSku($this->platform, $productData['sku']);

        if ($existingProduct) {
            if ($existingProduct->updateIfChanged($productData)) {
                $this->stats['products_updated']++;
                Log::debug("Updated product: {sku}", ['sku' => $productData['sku']]);
            }
        } else {
            Product::create($productData);
            $this->stats['products_added']++;
            Log::debug("Added new product: {sku}", ['sku' => $productData['sku']]);
        }
    }

    /**
     * Deactivate products that weren't found in current scraping session
     */
    protected function deactivateOldProducts(): void
    {
        $cutoffTime = $this->scrapingLog->started_at->subHours(1);

        $deactivatedCount = Product::where('platform', $this->platform)
            ->where('is_active', true)
            ->where('scraped_date', '<', $cutoffTime)
            ->update(['is_active' => false]);

        $this->stats['products_deactivated'] = $deactivatedCount;

        if ($deactivatedCount > 0) {
            Log::info("Deactivated {count} old products", [
                'count' => $deactivatedCount,
                'platform' => $this->platform
            ]);
        }
    }

    /**
     * Handle errors and update statistics
     */
    protected function handleError(string $message, \Exception $e): void
    {
        $this->stats['errors_count']++;
        $this->scrapingLog->addError($message, [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);

        Log::error($message, [
            'platform' => $this->platform,
            'error' => $e->getMessage()
        ]);
    }

    /**
     * Random delay between requests
     */
    protected function randomDelay(): void
    {
        $min = config('scraper.delay_min', 2);
        $max = config('scraper.delay_max', 5);
        $delay = rand($min, $max);
        sleep($delay);
    }

    /**
     * Clean and normalize text
     */
    protected function cleanText(?string $text): ?string
    {
        if (!$text) {
            return null;
        }

        return trim(preg_replace('/\s+/', ' ', strip_tags($text)));
    }

    /**
     * Extract price from text
     */
    protected function extractPrice(?string $priceText): ?float
    {
        if (!$priceText) {
            return null;
        }

        // Remove currency symbols and extract numeric value
        $price = preg_replace('/[^\d.,]/', '', $priceText);
        $price = str_replace(',', '', $price);

        return is_numeric($price) ? (float) $price : null;
    }

    /**
     * Extract rating from text
     */
    protected function extractRating(?string $ratingText): ?float
    {
        if (!$ratingText) {
            return null;
        }

        preg_match('/(\d+\.?\d*)/', $ratingText, $matches);
        return isset($matches[1]) ? (float) $matches[1] : null;
    }

    /**
     * Extract review count from text
     */
    protected function extractReviewCount(?string $reviewText): int
    {
        if (!$reviewText) {
            return 0;
        }

        $reviewText = str_replace(',', '', $reviewText);
        preg_match('/(\d+)/', $reviewText, $matches);
        return isset($matches[1]) ? (int) $matches[1] : 0;
    }

    // Abstract methods to be implemented by platform-specific scrapers
    abstract protected function setupPlatformConfig(): void;
    abstract protected function extractProductUrls(Crawler $crawler, string $categoryUrl): array;
    abstract protected function extractProductData(Crawler $crawler, string $productUrl): array;
}
