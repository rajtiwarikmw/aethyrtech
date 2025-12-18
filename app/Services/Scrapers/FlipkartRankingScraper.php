<?php

namespace App\Services\Scrapers;

use App\Models\Keyword;
use App\Models\Product;
use App\Models\ProductRanking;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class FlipkartRankingScraper
{
    protected Client $httpClient;
    protected string $platform = 'flipkart';
    protected int $maxPages = 2;
    protected array $stats = [
        'keywords_processed' => 0,
        'products_found' => 0,
        'sponsored_skipped' => 0,
        'rankings_recorded' => 0,
        'errors_count' => 0,
    ];

    public function __construct()
    {
        $this->initializeHttpClient();
    }

    protected function initializeHttpClient(): void
    {
        $this->httpClient = new Client([
            'timeout' => 30,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ],
            'verify' => false,
            'allow_redirects' => true,
            'http_errors' => false
        ]);
    }

    public function scrapeRankings(?array $keywordIds = null): array
    {
        $query = Keyword::where('platform', $this->platform)->where('status', true);
        if ($keywordIds) {
            $query->whereIn('id', $keywordIds);
        }
        $keywords = $query->get();

        Log::info("Starting Flipkart ranking scraping", [
            'total_keywords' => $keywords->count(),
            'keyword_ids' => $keywordIds
        ]);

        if ($keywords->isEmpty()) {
            Log::warning("No active keywords found for Flipkart");
            return $this->stats;
        }

        foreach ($keywords as $keyword) {
            try {
                $this->scrapeKeywordRankings($keyword);
                $this->stats['keywords_processed']++;
                $this->randomDelay(3, 6);
            } catch (\Exception $e) {
                Log::error("Failed to scrape Flipkart rankings", [
                    'keyword' => $keyword->keyword,
                    'error' => $e->getMessage()
                ]);
                $this->stats['errors_count']++;
            }
        }

        return $this->stats;
    }

    protected function scrapeKeywordRankings(Keyword $keyword): void
    {
        Log::info("Scraping Flipkart rankings for keyword", [
            'keyword' => $keyword->keyword,
            'keyword_id' => $keyword->id
        ]);

        $organicPosition = 0; // Position counter for organic products only

        for ($page = 1; $page <= $this->maxPages; $page++) {
            try {
                $url = $this->buildSearchUrl($keyword->keyword, $page);
                $html = $this->fetchPage($url);

                if (!$html) {
                    break;
                }

                $crawler = new Crawler($html);
                $products = $this->extractProductsFromPage($crawler, $page, $organicPosition);

                if (empty($products)) {
                    break;
                }

                foreach ($products as $productData) {
                    $this->saveRanking($keyword, $productData);
                }

                $this->randomDelay(2, 4);
            } catch (\Exception $e) {
                Log::error("Error scraping Flipkart rankings page", [
                    'keyword' => $keyword->keyword,
                    'page' => $page,
                    'error' => $e->getMessage()
                ]);
                break;
            }
        }
    }

    protected function buildSearchUrl(string $keyword, int $page = 1): string
    {
        $baseUrl = 'https://www.flipkart.com/search';
        $params = [
            'q' => $keyword,
            'page' => $page,
        ];
        return $baseUrl . '?' . http_build_query($params);
    }

    protected function fetchPage(string $url): ?string
    {
        try {
            Log::debug("Fetching Flipkart page", ['url' => $url]);
            $response = $this->httpClient->get($url);
            $statusCode = $response->getStatusCode();
            
            if ($statusCode === 200) {
                // Get body content once and reuse it
                $html = $response->getBody()->getContents();
                
                Log::debug("Flipkart page response", [
                    'status_code' => $statusCode,
                    'content_length' => strlen($html),
                    'has_products' => substr_count($html, '/p/itm') > 0
                ]);
                
                return $html;
            }
            
            Log::warning("Flipkart returned non-200 status", [
                'status_code' => $statusCode,
                'url' => $url
            ]);
            
            return null;
        } catch (\Exception $e) {
            Log::error("Failed to fetch Flipkart page", [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    protected function extractProductsFromPage(Crawler $crawler, int $page, int &$organicPosition): array
    {
        $products = [];

        try {
            // Use div[data-id] as primary selector (contains PID)
            $crawler->filter('div[data-id]')->each(function (Crawler $node) use (&$products, $page, &$organicPosition) {
                try {
                    // Extract product link to get SKU
                    $linkNode = $node->filter('a[href*="/p/"]');
                    if ($linkNode->count() === 0) {
                        return; // Skip if no product link
                    }
                    
                    $href = $linkNode->attr('href');
                    
                    // Extract SKU from URL pattern: /p/itmXXXXXXXXXXXX
                    // Example: /canon-pixma-megatank-ink-efficient-g2730-multi-function-color-ink-tank-printer-black-70-ml-40/p/itm93ff30817a43a
                    $sku = null;
                    if (preg_match('/\/p\/(itm[a-zA-Z0-9]+)/', $href, $matches)) {
                        $sku = $matches[1];
                    }
                    
                    if (!$sku) {
                        Log::debug("Could not extract SKU from URL", ['href' => $href]);
                        return; // Skip if no SKU
                    }
                    
                    // Check if product is sponsored
                    $isSponsored = $this->isSponsored($href, $node);
                    
                    if ($isSponsored) {
                        Log::debug("Skipping sponsored product", ['sku' => $sku]);
                        $this->stats['sponsored_skipped']++;
                        return; // Skip sponsored products
                    }
                    
                    // Increment organic position only for non-sponsored products
                    $organicPosition++;
                    
                    $products[] = [
                        'sku' => $sku,
                        'position' => $organicPosition,
                        'page' => $page,
                        'is_sponsored' => false,
                    ];

                    Log::debug("Found organic Flipkart product", [
                        'sku' => $sku,
                        'position' => $organicPosition,
                        'page' => $page
                    ]);

                    $this->stats['products_found']++;
                } catch (\Exception $e) {
                    Log::warning("Error extracting product", ['error' => $e->getMessage()]);
                }
            });
        } catch (\Exception $e) {
            Log::error("Error extracting products from page", ['error' => $e->getMessage()]);
        }

        return $products;
    }

    protected function isSponsored(string $href, Crawler $node): bool
    {
        try {
            // PRIMARY METHOD: Check for sponsored badge div.IxWX8O
            // This div contains the "Sponsored" SVG badge
            if ($node->filter('div.IxWX8O')->count() > 0) {
                Log::debug("Sponsored product detected (div.IxWX8O badge found)");
                return true;
            }
            
            // Fallback Method 1: Check URL parameters
            // Organic products have: fm=organic
            // Sponsored products have: fm=search or other values
            if (strpos($href, 'fm=organic') !== false) {
                return false; // Organic product
            }
            
            // If fm parameter exists but is not organic, it's likely sponsored
            if (preg_match('/[?&]fm=([^&]+)/', $href, $matches)) {
                $fmValue = $matches[1];
                if ($fmValue !== 'organic') {
                    Log::debug("Sponsored product detected", ['fm' => $fmValue]);
                    return true;
                }
            }
            
            // Fallback Method 2: Check for "Sponsored" text in SVG or HTML
            $html = $node->html();
            if (stripos($html, 'Sponsored') !== false) {
                return true;
            }
            
            return false; // Default to organic if no sponsored indicators found
        } catch (\Exception $e) {
            return false; // Default to organic on error
        }
    }

    protected function saveRanking(Keyword $keyword, array $productData): void
    {
        try {
            $product = Product::where('platform', $this->platform)
                ->where('sku', $productData['sku'])
                ->first();

            // Save ranking
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

            Log::debug("Saved Flipkart ranking", [
                'keyword' => $keyword->keyword,
                'sku' => $productData['sku'],
                'position' => $productData['position']
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to save Flipkart ranking", [
                'sku' => $productData['sku'],
                'error' => $e->getMessage()
            ]);
            $this->stats['errors_count']++;
        }
    }

    protected function randomDelay(int $min = 2, int $max = 5): void
    {
        usleep(rand($min * 1000000, $max * 1000000));
    }

    public function getStats(): array
    {
        return $this->stats;
    }
}
