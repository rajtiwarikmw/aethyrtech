<?php

namespace App\Services\Scrapers;

use App\Models\Keyword;
use App\Models\ProductRanking;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;
use Spatie\Browsershot\Browsershot;

class BigBasketRankingScraper
{
    protected $stats = [
        'keywords_processed' => 0,
        'products_found' => 0,
        'rankings_recorded' => 0,
        'errors' => 0,
    ];

    protected $maxPages = 5;  // Maximum pages to scrape per keyword
    protected $productsPerPage = 24;  // Approximate products per page

    /**
     * Scrape rankings for all BigBasket keywords
     */
    public function scrapeAllKeywords(?int $limit = null): array
    {
        Log::info("Starting BigBasket ranking scraping", ['limit' => $limit]);

        $query = Keyword::where('platform', 'bigbasket')
            ->where('is_active', true);

        if ($limit) {
            $query->limit($limit);
        }

        $keywords = $query->get();

        Log::info("Found BigBasket keywords to scrape", ['count' => $keywords->count()]);

        foreach ($keywords as $keyword) {
            $this->scrapeKeywordRankings($keyword);
            
            // Add delay between keywords to avoid rate limiting
            sleep(rand(5, 10));
        }

        return $this->stats;
    }

    /**
     * Scrape rankings for a single keyword
     */
    public function scrapeKeywordRankings(Keyword $keyword): void
    {
        try {
            Log::info("Scraping BigBasket rankings for keyword", [
                'keyword' => $keyword->keyword,
                'keyword_id' => $keyword->id
            ]);

            $this->stats['keywords_processed']++;

            // Scrape multiple pages
            for ($page = 1; $page <= $this->maxPages; $page++) {
                $searchUrl = $this->buildSearchUrl($keyword->keyword, $page);
                
                Log::debug("Fetching BigBasket search page", [
                    'url' => $searchUrl,
                    'page' => $page
                ]);

                // Fetch page
                $html = $this->fetchPageWithBrowsershot($searchUrl);

                if (!$html) {
                    Log::warning("Failed to fetch BigBasket search page", [
                        'keyword' => $keyword->keyword,
                        'page' => $page
                    ]);
                    break;
                }

                // Parse HTML
                $crawler = new Crawler($html);

                // Extract products
                $startPosition = ($page - 1) * $this->productsPerPage;
                $products = $this->extractProductsFromPage($crawler, $page, $startPosition);

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

                Log::info("Scraped BigBasket rankings page", [
                    'keyword' => $keyword->keyword,
                    'page' => $page,
                    'products_found' => count($products)
                ]);

                // Add delay between pages
                if ($page < $this->maxPages) {
                    sleep(rand(3, 6));
                }
            }

        } catch (\Exception $e) {
            Log::error("Failed to scrape BigBasket rankings for keyword", [
                'keyword' => $keyword->keyword ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->stats['errors']++;
        }
    }

    /**
     * Build search URL for keyword
     */
    protected function buildSearchUrl(string $keyword, int $page = 1): string
    {
        $baseUrl = 'https://www.bigbasket.com/ps/';
        $encodedKeyword = urlencode($keyword);
        
        // BigBasket search URL pattern
        if ($page > 1) {
            return "{$baseUrl}?q={$encodedKeyword}&page={$page}";
        }
        
        return "{$baseUrl}?q={$encodedKeyword}";
    }

    /**
     * Fetch page with JavaScript rendering
     */
    protected function fetchPageWithBrowsershot(string $url): ?string
    {
        try {
            Log::debug("Fetching BigBasket page with JavaScript", ['url' => $url]);

            $html = Browsershot::url($url)
                ->userAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36')
                ->setExtraHttpHeaders([
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9',
                    'Accept-Encoding' => 'gzip, deflate, br',
                    'Connection' => 'keep-alive',
                ])
                ->waitUntilNetworkIdle()
                ->timeout(60)
                ->bodyHtml();

            $contentLength = strlen($html);

            Log::debug("BigBasket page response", [
                'status_code' => 200,
                'content_length' => $contentLength
            ]);

            if ($contentLength < 1000) {
                Log::warning("BigBasket returned suspiciously small response", [
                    'content_length' => $contentLength,
                    'url' => $url
                ]);
                return null;
            }

            // Check for access denied or error pages
            if (strpos($html, 'Access Denied') !== false ||
                strpos($html, 'permission to access') !== false ||
                strpos($html, 'Page not found') !== false) {
                Log::warning("BigBasket returned error page", ['url' => $url]);
                
                // Save HTML for debugging
                $debugFile = storage_path('logs/bigbasket_ranking_debug_' . time() . '.html');
                file_put_contents($debugFile, $html);
                Log::debug("Saved HTML for debugging", ['file' => $debugFile]);
                
                return null;
            }

            return $html;

        } catch (\Exception $e) {
            Log::error("Failed to fetch BigBasket page", [
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
        $positionOnPage = 0;

        try {
            // BigBasket product card selectors (multiple fallbacks)
            $selectors = [
                'div[data-testid="product-card"]',  // Test ID
                'div[qa="product-card"]',  // QA attribute
                'a[href*="/pd/"]',  // Product links
                'div.product-card',  // Class name
                'div[class*="ProductCard"]',  // Styled component
                'div[data-track="product"]',  // Tracking attribute
            ];

            $productNodes = null;
            $usedSelector = null;

            // Try each selector
            foreach ($selectors as $selector) {
                $nodes = $crawler->filter($selector);
                
                if ($nodes->count() > 0) {
                    $productNodes = $nodes;
                    $usedSelector = $selector;
                    
                    Log::debug("Found BigBasket products using selector", [
                        'selector' => $selector,
                        'count' => $nodes->count(),
                        'page' => $page
                    ]);
                    
                    break;
                }
            }

            if (!$productNodes || $productNodes->count() === 0) {
                Log::warning("No BigBasket products found with any selector", [
                    'tried_selectors' => $selectors,
                    'page' => $page,
                    'html_length' => $crawler->html() ? strlen($crawler->html()) : 0
                ]);
                
                // Save HTML for debugging
                $debugFile = storage_path("logs/bigbasket_ranking_debug_page_{$page}_" . time() . ".html");
                file_put_contents($debugFile, $crawler->html());
                Log::debug("Saved HTML for debugging", ['file' => $debugFile]);
                
                return [];
            }

            // Extract products from found nodes
            $productNodes->each(function (Crawler $node) use (&$products, $page, &$positionOnPage, $startPosition) {
                try {
                    $positionOnPage++;
                    $globalPosition = $startPosition + $positionOnPage;

                    // Method 1: Extract SKU from data attribute
                    $productId = $node->attr('data-product-id') ?: $node->attr('data-sku') ?: $node->attr('data-id');
                    
                    // Method 2: Extract from link href
                    if (!$productId) {
                        try {
                            $linkNode = $node->filter('a[href*="/pd/"]');
                            if ($linkNode->count() > 0) {
                                $href = $linkNode->first()->attr('href');
                                
                                // BigBasket URL pattern: /pd/{product_id}/{product-slug}/
                                if (preg_match('/\/pd\/(\d+)/', $href, $matches)) {
                                    $productId = $matches[1];
                                }
                            }
                        } catch (\Exception $e) {
                            // Ignore link extraction errors
                        }
                    }

                    // Method 3: Look for any link in the node
                    if (!$productId) {
                        try {
                            $allLinks = $node->filter('a');
                            foreach ($allLinks as $link) {
                                $href = $link->getAttribute('href');
                                if ($href && preg_match('/\/pd\/(\d+)/', $href, $matches)) {
                                    $productId = $matches[1];
                                    break;
                                }
                            }
                        } catch (\Exception $e) {
                            // Ignore
                        }
                    }

                    if (!$productId) {
                        Log::debug("Could not extract product ID from node", [
                            'position' => $positionOnPage,
                            'page' => $page
                        ]);
                        return;
                    }

                    // Check if it's a sponsored product (exclude from organic rankings)
                    $isSponsored = $this->isSponsored($node);
                    
                    if ($isSponsored) {
                        Log::debug("Skipping sponsored BigBasket product", [
                            'sku' => $productId,
                            'position' => $positionOnPage
                        ]);
                        return;
                    }

                    $products[] = [
                        'sku' => $productId,
                        'position' => $globalPosition,
                        'page' => $page,
                    ];

                    Log::debug("Found BigBasket product", [
                        'sku' => $productId,
                        'position' => $globalPosition,
                        'page' => $page
                    ]);

                    $this->stats['products_found']++;

                } catch (\Exception $e) {
                    Log::warning("Error extracting BigBasket product", [
                        'position' => $positionOnPage,
                        'page' => $page,
                        'error' => $e->getMessage()
                    ]);
                }
            });

            Log::info("Page extraction summary", [
                'page' => $page,
                'products_found' => count($products),
                'selector_used' => $usedSelector
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to extract BigBasket products from page", [
                'page' => $page,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }

        return $products;
    }

    /**
     * Check if product is sponsored/promoted
     */
    protected function isSponsored(Crawler $node): bool
    {
        try {
            // Check for sponsored indicators
            $sponsoredIndicators = [
                'data-sponsored',
                'data-promoted',
                'data-ad',
            ];

            foreach ($sponsoredIndicators as $attr) {
                if ($node->attr($attr)) {
                    return true;
                }
            }

            // Check for sponsored text/badges
            $sponsoredSelectors = [
                '.sponsored',
                '.promoted',
                '.ad-badge',
                '[data-testid="sponsored"]',
            ];

            foreach ($sponsoredSelectors as $selector) {
                if ($node->filter($selector)->count() > 0) {
                    return true;
                }
            }

            // Check text content for "Sponsored" or "Ad"
            $text = strtolower($node->text());
            if (strpos($text, 'sponsored') !== false || strpos($text, ' ad ') !== false) {
                return true;
            }

        } catch (\Exception $e) {
            // If we can't determine, assume it's organic
        }

        return false;
    }

    /**
     * Save ranking to database
     */
    protected function saveRanking(Keyword $keyword, array $productData): void
    {
        try {
            ProductRanking::create([
                'keyword_id' => $keyword->id,
                'sku' => $productData['sku'],
                'platform' => 'bigbasket',
                'position' => $productData['position'],
                'page' => $productData['page'],
                'search_query' => $keyword->keyword,
            ]);

            $this->stats['rankings_recorded']++;

            Log::debug("Saved BigBasket ranking", [
                'keyword' => $keyword->keyword,
                'sku' => $productData['sku'],
                'position' => $productData['position']
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to save BigBasket ranking", [
                'keyword' => $keyword->keyword ?? 'unknown',
                'sku' => $productData['sku'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            $this->stats['errors']++;
        }
    }

    /**
     * Get scraping statistics
     */
    public function getStats(): array
    {
        return $this->stats;
    }
}
