<?php

namespace App\Services\Scrapers;

use App\Models\Keyword;
use App\Models\Product;
use App\Models\ProductRanking;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Meesho Ranking Scraper
 * 
 * Scrapes product rankings for given keywords on Meesho
 * Tracks organic position (excluding sponsored products)
 * 
 * URL Pattern:
 * - Search: https://www.meesho.com/search?q={query}
 * - Search with pagination: https://www.meesho.com/search?q={query}&page={page}
 */
class MeeshoRankingScraper
{
    protected Client $httpClient;
    protected string $platform = 'meesho';
    protected int $maxPages = 5;
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

    /**
     * Initialize HTTP client with appropriate headers
     */
    protected function initializeHttpClient(): void
    {
        $this->httpClient = new Client([
            'timeout' => 30,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
                'Accept-Encoding' => 'gzip, deflate',
                'DNT' => '1',
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
        $query = Keyword::where('platform', $this->platform)->where('status', true);
        
        if ($keywordIds) {
            $query->whereIn('id', $keywordIds);
        }
        
        $keywords = $query->get();

        Log::info("Starting Meesho ranking scraping", [
            'total_keywords' => $keywords->count(),
            'keyword_ids' => $keywordIds
        ]);

        if ($keywords->isEmpty()) {
            Log::warning("No active keywords found for Meesho");
            return $this->stats;
        }

        foreach ($keywords as $keyword) {
            try {
                $this->scrapeKeywordRankings($keyword);
                $this->stats['keywords_processed']++;
                $this->randomDelay(3, 6);
            } catch (\Exception $e) {
                Log::error("Failed to scrape Meesho rankings", [
                    'keyword' => $keyword->keyword,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                $this->stats['errors_count']++;
            }
        }

        Log::info("Completed Meesho ranking scraping", $this->stats);
        return $this->stats;
    }

    /**
     * Scrape rankings for a specific keyword
     */
    protected function scrapeKeywordRankings(Keyword $keyword): void
    {
        Log::info("Scraping Meesho rankings for keyword", [
            'keyword' => $keyword->keyword,
            'keyword_id' => $keyword->id
        ]);

        $organicPosition = 0; // Counter for organic products only

        for ($page = 1; $page <= $this->maxPages; $page++) {
            try {
                $url = $this->buildSearchUrl($keyword->keyword, $page);
                $html = $this->fetchPage($url);

                if (!$html) {
                    Log::warning("Failed to fetch Meesho search page", [
                        'keyword' => $keyword->keyword,
                        'page' => $page
                    ]);
                    break;
                }

                $crawler = new Crawler($html);
                $products = $this->extractProductsFromPage($crawler, $page, $organicPosition);

                if (empty($products)) {
                    Log::info("No products found on Meesho page", [
                        'keyword' => $keyword->keyword,
                        'page' => $page
                    ]);
                    break;
                }

                // Save rankings to database
                foreach ($products as $product) {
                    $this->saveRanking($keyword, $product);
                    $this->stats['rankings_recorded']++;
                }

                $this->randomDelay(2, 4);
            } catch (\Exception $e) {
                Log::error("Error scraping Meesho page", [
                    'keyword' => $keyword->keyword,
                    'page' => $page,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Build search URL for Meesho
     */
    protected function buildSearchUrl(string $keyword, int $page = 1): string
    {
        $query = urlencode($keyword);
        
        if ($page === 1) {
            return "https://www.meesho.com/search?q={$query}";
        }
        
        return "https://www.meesho.com/search?q={$query}&page={$page}";
    }

    /**
     * Fetch page content
     */
    protected function fetchPage(string $url): ?string
    {
        try {
            $response = $this->httpClient->get($url);
            
            if ($response->getStatusCode() === 200) {
                return (string)$response->getBody();
            }
            
            Log::warning("Non-200 response from Meesho", [
                'url' => $url,
                'status_code' => $response->getStatusCode()
            ]);
            
            return null;
        } catch (\Exception $e) {
            Log::error("Failed to fetch Meesho page", [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Extract products from search results page
     * Handles both organic and sponsored products
     */
    protected function extractProductsFromPage(Crawler $crawler, int $page, &$organicPosition): array
    {
        $products = [];

        try {
            // Meesho product selectors
            $selectors = [
                'div[data-testid="productCard"]',
                'div.productCard',
                'div[class*="productCard"]',
                'a[href*="/p/"]',
            ];

            $productElements = [];
            foreach ($selectors as $selector) {
                $elements = $crawler->filter($selector);
                if ($elements->count() > 0) {
                    $elements->each(function (Crawler $node) use (&$productElements) {
                        $productElements[] = $node;
                    });
                }
            }

            // Remove duplicates
            $productElements = array_unique($productElements, SORT_REGULAR);

            foreach ($productElements as $element) {
                try {
                    $product = $this->extractProductData($element, $page, $organicPosition);
                    
                    if ($product && !$product['is_sponsored']) {
                        $organicPosition++;
                        $product['organic_position'] = $organicPosition;
                        $products[] = $product;
                    } elseif ($product && $product['is_sponsored']) {
                        $this->stats['sponsored_skipped']++;
                    }
                } catch (\Exception $e) {
                    Log::debug("Error extracting product data: " . $e->getMessage());
                }
            }

            $this->stats['products_found'] += count($products);
            
            Log::debug("Extracted products from Meesho page", [
                'page' => $page,
                'organic_products' => count($products),
                'sponsored_skipped' => $this->stats['sponsored_skipped']
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to extract products from Meesho page", [
                'page' => $page,
                'error' => $e->getMessage()
            ]);
        }

        return $products;
    }

    /**
     * Extract product data from a product element
     */
    protected function extractProductData(Crawler $element, int $page, int $position): ?array
    {
        try {
            // Check if sponsored
            $isSponsored = $this->isSponsored($element);

            // Extract product URL
            $url = $this->extractProductUrl($element);
            if (!$url) {
                return null;
            }

            // Extract SKU from URL
            $sku = $this->extractSkuFromUrl($url);
            if (!$sku) {
                return null;
            }

            // Extract product title
            $title = $this->extractTitle($element);
            if (!$title) {
                return null;
            }

            // Extract price
            $price = $this->extractPrice($element);

            // Extract rating
            $rating = $this->extractRating($element);

            // Extract review count
            $reviewCount = $this->extractReviewCount($element);

            return [
                'sku' => $sku,
                'url' => $url,
                'title' => $title,
                'price' => $price,
                'rating' => $rating,
                'review_count' => $reviewCount,
                'page' => $page,
                'position' => $position,
                'is_sponsored' => $isSponsored,
            ];
        } catch (\Exception $e) {
            Log::debug("Error extracting product data: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if product is sponsored
     */
    protected function isSponsored(Crawler $element): bool
    {
        try {
            $sponsoredSelectors = [
                'span:contains("Sponsored")',
                'span:contains("Ad")',
                'span[class*="sponsored"]',
                'span[class*="ad"]',
                'div[class*="sponsored"]',
            ];

            foreach ($sponsoredSelectors as $selector) {
                if ($element->filter($selector)->count() > 0) {
                    return true;
                }
            }

            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Extract product URL
     */
    protected function extractProductUrl(Crawler $element): ?string
    {
        try {
            $selectors = [
                'a[href*="/p/"]',
                'a.productLink',
                'a[class*="product"]',
            ];

            foreach ($selectors as $selector) {
                $link = $element->filter($selector);
                if ($link->count() > 0) {
                    $href = $link->first()->attr('href');
                    if ($href) {
                        // Convert relative to absolute URL
                        if (strpos($href, 'http') !== 0) {
                            $href = 'https://www.meesho.com' . $href;
                        }
                        return $href;
                    }
                }
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Extract SKU from product URL
     */
    protected function extractSkuFromUrl(string $url): ?string
    {
        if (preg_match('/\/p\/([a-z0-9]+)/i', $url, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Extract product title
     */
    protected function extractTitle(Crawler $element): ?string
    {
        try {
            $selectors = [
                'h3',
                'h2',
                'a.productTitle',
                'span[class*="title"]',
                'div[class*="name"]',
            ];

            foreach ($selectors as $selector) {
                $titleElement = $element->filter($selector);
                if ($titleElement->count() > 0) {
                    $text = trim($titleElement->first()->text());
                    if ($text && strlen($text) > 3) {
                        return $text;
                    }
                }
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Extract price
     */
    protected function extractPrice(Crawler $element): ?float
    {
        try {
            $selectors = [
                'span[class*="price"]',
                'span.price',
                'div[class*="price"]',
            ];

            foreach ($selectors as $selector) {
                $priceElement = $element->filter($selector);
                if ($priceElement->count() > 0) {
                    $text = $priceElement->first()->text();
                    if (preg_match('/[\d,]+\.?\d*/', str_replace(',', '', $text), $matches)) {
                        return floatval($matches[0]);
                    }
                }
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Extract rating
     */
    protected function extractRating(Crawler $element): ?float
    {
        try {
            $selectors = [
                'span[class*="rating"]',
                'span.rating',
                'div[class*="star"]',
            ];

            foreach ($selectors as $selector) {
                $ratingElement = $element->filter($selector);
                if ($ratingElement->count() > 0) {
                    $text = $ratingElement->first()->text();
                    if (preg_match('/\d+\.?\d*/', $text, $matches)) {
                        $rating = floatval($matches[0]);
                        if ($rating <= 5) {
                            return $rating;
                        }
                    }
                }
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Extract review count
     */
    protected function extractReviewCount(Crawler $element): ?int
    {
        try {
            $selectors = [
                'span[class*="review"]',
                'span.reviews',
                'div[class*="review"]',
            ];

            foreach ($selectors as $selector) {
                $reviewElement = $element->filter($selector);
                if ($reviewElement->count() > 0) {
                    $text = $reviewElement->first()->text();
                    if (preg_match('/\d+/', str_replace(',', '', $text), $matches)) {
                        return intval($matches[0]);
                    }
                }
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Save ranking to database
     */
    protected function saveRanking(Keyword $keyword, array $product): void
    {
        try {
            // Find or create product
            $productModel = Product::where('sku', $product['sku'])
                ->where('platform', $this->platform)
                ->first();

            if (!$productModel) {
                $productModel = Product::create([
                    'sku' => $product['sku'],
                    'platform' => $this->platform,
                    'title' => $product['title'],
                    'product_url' => $product['url'],
                    'is_active' => true,
                ]);
            }

            // Create or update ranking
            ProductRanking::updateOrCreate(
                [
                    'keyword_id' => $keyword->id,
                    'product_id' => $productModel->id,
                    'platform' => $this->platform,
                ],
                [
                    'position' => $product['organic_position'],
                    'page' => $product['page'],
                    'price' => $product['price'],
                    'rating' => $product['rating'],
                    'review_count' => $product['review_count'],
                    'is_sponsored' => $product['is_sponsored'],
                    'scraped_at' => now(),
                ]
            );

            Log::debug("Saved ranking", [
                'keyword' => $keyword->keyword,
                'sku' => $product['sku'],
                'position' => $product['organic_position']
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to save ranking", [
                'keyword' => $keyword->keyword,
                'sku' => $product['sku'],
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Random delay between requests
     */
    protected function randomDelay(int $min = 1, int $max = 3): void
    {
        $delay = rand($min * 1000, $max * 1000);
        usleep($delay);
    }
}
