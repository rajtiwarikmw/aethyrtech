<?php

namespace App\Services\Scrapers;

use App\Models\Keyword;
use App\Models\Product;
use App\Models\ProductRanking;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class AmazonRankingScraper
{
    protected Client $httpClient;
    protected string $platform = 'amazon';
    protected int $maxPages = 2;
    protected array $stats = [
        'keywords_processed' => 0,
        'products_found' => 0,
        'rankings_recorded' => 0,
        'errors_count' => 0,
    ];

    public function __construct()
    {
        $this->initializeHttpClient();
    }

    /**
     * Initialize HTTP client
     */
    protected function initializeHttpClient(): void
    {
        $this->httpClient = new Client([
            'timeout' => config('scraper.timeout', 30),
            'headers' => [
                'User-Agent' => config('scraper.user_agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'),
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
     * Scrape rankings for all active keywords
     */
    public function scrapeRankings(?array $keywordIds = null): array
    {
        Log::info("Starting Amazon ranking scraping", [
            'keyword_ids' => $keywordIds,
            'max_pages' => $this->maxPages
        ]);

        // Get keywords to process
        $query = Keyword::where('platform', $this->platform)
            ->where('status', true)
            ->where('category', 'printer');

        if ($keywordIds) {
            $query->whereIn('id', $keywordIds);
        }

        $keywords = $query->get();

        Log::info("Found {$keywords->count()} keywords to process");

        foreach ($keywords as $keyword) {
            try {
                $this->scrapeKeywordRankings($keyword);
                $this->stats['keywords_processed']++;

                // Add delay between keywords
                $this->randomDelay(8, 15);
            } catch (\Exception $e) {
                Log::error("Failed to scrape rankings for keyword", [
                    'keyword_id' => $keyword->id,
                    'keyword' => $keyword->keyword,
                    'error' => $e->getMessage()
                ]);
                $this->stats['errors_count']++;
            }
        }

        Log::info("Amazon ranking scraping completed", $this->stats);

        return $this->stats;
    }

    /**
     * Scrape rankings for a single keyword
     */
    protected function scrapeKeywordRankings(Keyword $keyword): void
    {
        Log::info("Scraping rankings for keyword", [
            'keyword_id' => $keyword->id,
            'keyword' => $keyword->keyword
        ]);

        $organicPositionCounter = 0; // Track organic position counter across all pages

        for ($page = 1; $page <= $this->maxPages; $page++) {
            try {
                $url = $this->buildSearchUrl($keyword->keyword, $page);
                
                Log::info("Fetching search results page", [
                    'keyword' => $keyword->keyword,
                    'page' => $page,
                    'url' => $url,
                    'current_organic_position' => $organicPositionCounter
                ]);

                $html = $this->fetchPage($url);
                
                if (!$html) {
                    Log::warning("Failed to fetch search results page", [
                        'keyword' => $keyword->keyword,
                        'page' => $page
                    ]);
                    break;
                }

                $crawler = new Crawler($html);
                $result = $this->extractProductsFromPage($crawler, $page, $organicPositionCounter);
                $products = $result['products'];
                $organicCount = $result['organic_count'];

                if (empty($products)) {
                    Log::info("No more products found, stopping pagination", [
                        'keyword' => $keyword->keyword,
                        'page' => $page
                    ]);
                    break;
                }

                // Save rankings
                foreach ($products as $productData) {
                    $this->saveRanking($keyword, $productData);
                }

                // Update organic position counter with actual organic products found
                $organicPositionCounter += $organicCount;
                
                Log::info("Completed page scraping", [
                    'keyword' => $keyword->keyword,
                    'page' => $page,
                    'organic_products_on_page' => $organicCount,
                    'total_organic_position' => $organicPositionCounter
                ]);

                // Add delay between pages
                $this->randomDelay(8, 15);
            } catch (\Exception $e) {
                Log::error("Error scraping search results page", [
                    'keyword' => $keyword->keyword,
                    'page' => $page,
                    'error' => $e->getMessage()
                ]);
                break;
            }
        }
    }

    /**
     * Build Amazon search URL
     */
    protected function buildSearchUrl(string $keyword, int $page): string
    {
        $baseUrl = 'https://www.amazon.in/s';
        $params = [
            'k' => $keyword,
            'page' => $page,
        ];

        return $baseUrl . '?' . http_build_query($params);
    }

    /**
     * Fetch page content
     */
    protected function fetchPage(string $url): ?string
    {
        try {
            $response = $this->httpClient->get($url);
            $statusCode = $response->getStatusCode();

            if ($statusCode === 200) {
                return $response->getBody()->getContents();
            }

            Log::warning("Non-200 status code received", [
                'url' => $url,
                'status_code' => $statusCode
            ]);

            return null;
        } catch (RequestException $e) {
            Log::error("HTTP request failed", [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Extract products from search results page
     */
    protected function extractProductsFromPage(Crawler $crawler, int $page, int $startPosition): array
    {
        $products = [];
        $organicPosition = 0; // Track only organic products on this page
        $totalProducts = 0;   // Track total products (including sponsored)
        $sponsoredCount = 0;  // Track sponsored products

        try {
            // Amazon product container selectors
            $crawler->filter('div[data-component-type="s-search-result"]')->each(function (Crawler $node) use (&$products, $page, &$organicPosition, &$totalProducts, &$sponsoredCount, $startPosition) {
                try {
                    $totalProducts++;
                    
                    // Extract ASIN/SKU
                    $asin = $node->attr('data-asin');
                    
                    if (!$asin || empty($asin)) {
                        return; // Skip if no ASIN
                    }

                    // Check if product is sponsored
                    $isSponsored = $this->isSponsored($node);
                    
                    if ($isSponsored) {
                        $sponsoredCount++;
                        Log::debug("Skipping sponsored product", [
                            'asin' => $asin,
                            'page' => $page,
                            'position_on_page' => $totalProducts
                        ]);
                        return; // Skip sponsored products
                    }

                    // Increment organic position only for non-sponsored products
                    $organicPosition++;
                    $globalPosition = $startPosition + $organicPosition;

                    // Extract product title (optional, for logging)
                    $title = null;
                    $titleNode = $node->filter('h2 a span');
                    if ($titleNode->count() > 0) {
                        $title = trim($titleNode->first()->text());
                    }

                    $products[] = [
                        'sku' => $asin,
                        'position' => $globalPosition,
                        'page' => $page,
                        'title' => $title,
                    ];

                    $this->stats['products_found']++;
                    
                    Log::debug("Found organic product", [
                        'asin' => $asin,
                        'organic_position' => $globalPosition,
                        'page' => $page,
                        'position_on_page' => $totalProducts
                    ]);
                } catch (\Exception $e) {
                    Log::warning("Failed to extract product from search result", [
                        'error' => $e->getMessage()
                    ]);
                }
            });
        } catch (\Exception $e) {
            Log::error("Failed to extract products from page", [
                'error' => $e->getMessage()
            ]);
        }

        Log::info("Page extraction summary", [
            'page' => $page,
            'total_products' => $totalProducts,
            'sponsored_products' => $sponsoredCount,
            'organic_products' => $organicPosition
        ]);

        return [
            'products' => $products,
            'organic_count' => $organicPosition
        ];
    }

    /**
     * Check if a product is sponsored
     */
    protected function isSponsored(Crawler $node): bool
    {
        try {
            // Method 1: Check for sponsored badge text
            $sponsoredBadge = $node->filter('span.puis-label-popover-default, span[data-component-type="s-sponsored-label"]');
            if ($sponsoredBadge->count() > 0) {
                $badgeText = strtolower($sponsoredBadge->text());
                if (strpos($badgeText, 'sponsored') !== false) {
                    return true;
                }
            }

            // Method 2: Check for sponsored class in parent
            $html = $node->html();
            if (stripos($html, 'sponsored') !== false || 
                stripos($html, 'AdHolder') !== false ||
                stripos($html, 's-sponsored') !== false) {
                return true;
            }

            // Method 3: Check data attributes
            if ($node->attr('data-is-sponsored') === 'true') {
                return true;
            }

            // Method 4: Check for specific sponsored container classes
            if ($node->filter('.AdHolder, .s-sponsored-list-item, [data-component-type="sp-sponsored-result"]')->count() > 0) {
                return true;
            }

        } catch (\Exception $e) {
            // If we can't determine, assume it's organic
            Log::debug("Could not determine if product is sponsored", [
                'error' => $e->getMessage()
            ]);
        }

        return false; // Default to organic
    }

    /**
     * Save ranking to database
     */
    protected function saveRanking(Keyword $keyword, array $productData): void
    {
        try {
            // Try to find the product in our database
            $product = Product::where('platform', $this->platform)
                ->where('sku', $productData['sku'])
                ->first();

            $rankingData = [
                'product_id' => $product ? $product->id : null,
                'scraper_id' =>"2",
                'sku' => $productData['sku'],
                'keyword_id' => $keyword->id,
                'platform' => "$this->platform",
                'position' => $productData['position'],
                'page' => $productData['page'],
            ];

            ProductRanking::create($rankingData);
            $this->stats['rankings_recorded']++;

            Log::debug("Recorded ranking", [
                'keyword' => $keyword->keyword,
                'sku' => $productData['sku'],
                'position' => $productData['position'],
                'page' => $productData['page'],
                'title' => $productData['title'] ?? 'N/A'
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to save ranking", [
                'keyword_id' => $keyword->id,
                'sku' => $productData['sku'],
                'error' => $e->getMessage()
            ]);
            $this->stats['errors_count']++;
        }
    }

    /**
     * Random delay to avoid rate limiting
     */
    protected function randomDelay(int $min = 5, int $max = 15): void
    {
        $delay = rand($min * 1000000, $max * 1000000);
        usleep($delay);
    }

    /**
     * Get scraping statistics
     */
    public function getStats(): array
    {
        return $this->stats;
    }
}
