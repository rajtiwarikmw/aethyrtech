<?php

namespace App\Services\Scrapers;

use App\Models\Keyword;
use App\Models\ProductRanking;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;
use Spatie\Browsershot\Browsershot;

class CromaRankingScraper
{
    protected $stats = [
        'keywords_processed' => 0,
        'pages_scraped' => 0,
        'products_found' => 0,
        'rankings_saved' => 0,
        'errors' => 0,
    ];

    protected $maxPages = 5; // Maximum pages to scrape per keyword

    /**
     * Scrape rankings for all Croma keywords
     */
    public function scrapeAllRankings(?int $limit = null): array
    {
        Log::info("Starting Croma ranking scraping", ['limit' => $limit]);

        $query = Keyword::where('platform', 'croma')
            ->whereNotNull('keyword');

        if ($limit) {
            $query->limit($limit);
        }

        $keywords = $query->get();

        Log::info("Found Croma keywords to scrape rankings", ['count' => $keywords->count()]);

        foreach ($keywords as $keyword) {
            $this->scrapeKeywordRankings($keyword);
            
            // Add delay between keywords
            sleep(rand(5, 8));
        }

        return $this->stats;
    }

    /**
     * Scrape rankings for a single keyword
     */
    public function scrapeKeywordRankings(Keyword $keyword): void
    {
        try {
            Log::info("Scraping Croma rankings for keyword", [
                'keyword_id' => $keyword->id,
                'keyword' => $keyword->keyword
            ]);

            $this->stats['keywords_processed']++;

            // Scrape multiple pages
            for ($page = 1; $page <= $this->maxPages; $page++) {
                $searchUrl = $this->buildSearchUrl($keyword->keyword, $page);

                Log::debug("Scraping Croma search page", [
                    'keyword' => $keyword->keyword,
                    'page' => $page,
                    'url' => $searchUrl
                ]);

                // Fetch page
                $html = $this->fetchPageWithBrowsershot($searchUrl);

                if (!$html) {
                    Log::warning("Failed to fetch Croma search page", [
                        'keyword' => $keyword->keyword,
                        'page' => $page
                    ]);
                    $this->stats['errors']++;
                    break;
                }

                $this->stats['pages_scraped']++;

                // Parse HTML
                $crawler = new Crawler($html);

                // Extract products
                $products = $this->extractProductsFromPage($crawler, $keyword->id, $keyword->keyword, $page);

                if (empty($products)) {
                    Log::info("No more Croma products found on page {$page}", [
                        'keyword' => $keyword->keyword
                    ]);
                    break;
                }

                // Save rankings
                foreach ($products as $productData) {
                    $this->saveRanking($productData);
                }

                Log::info("Scraped Croma rankings from page {$page}", [
                    'keyword' => $keyword->keyword,
                    'products_count' => count($products)
                ]);

                // Add delay between pages
                sleep(rand(3, 6));
            }

        } catch (\Exception $e) {
            Log::error("Failed to scrape Croma rankings for keyword", [
                'keyword_id' => $keyword->id,
                'keyword' => $keyword->keyword,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->stats['errors']++;
        }
    }

    /**
     * Build search URL for Croma
     */
    protected function buildSearchUrl(string $keyword, int $page = 1): string
    {
        $encodedKeyword = urlencode($keyword);
        
        if ($page === 1) {
            return "https://www.croma.com/search?q={$encodedKeyword}";
        } else {
            return "https://www.croma.com/search?q={$encodedKeyword}&page={$page}";
        }
    }

    /**
     * Fetch page with JavaScript rendering
     */
    protected function fetchPageWithBrowsershot(string $url): ?string
    {
        try {
            Log::debug("Fetching Croma search page with JavaScript", ['url' => $url]);

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

            Log::debug("Croma search page response", [
                'url' => $url,
                'content_length' => $contentLength
            ]);

            if ($contentLength < 1000) {
                Log::warning("Croma returned suspiciously small response", [
                    'url' => $url,
                    'length' => $contentLength
                ]);
                return null;
            }

            return $html;

        } catch (\Exception $e) {
            Log::error("Failed to fetch Croma search page", [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Extract products from search results page
     */
    protected function extractProductsFromPage(Crawler $crawler, int $keywordId, string $keyword, int $page): array
    {
        $products = [];
        $position = ($page - 1) * 24; // Assuming 24 products per page

        try {
            // Croma product card selectors (updated for 2024)
            $productSelectors = [
                'div[data-testid="product-card"]',
                '.product-item',
                '.plp-product-tile',
                '.product-tile-wrapper',
                '.product-card',
                '.cp-product',
                'div[class*="ProductCard"]',
                'div[class*="product-card"]',
            ];

            $productNodes = null;
            $usedSelector = null;

            // Try each selector
            foreach ($productSelectors as $selector) {
                $nodes = $crawler->filter($selector);
                if ($nodes->count() > 0) {
                    $productNodes = $nodes;
                    $usedSelector = $selector;
                    Log::debug("Found Croma products using selector", [
                        'selector' => $selector,
                        'count' => $nodes->count()
                    ]);
                    break;
                }
            }

            if (!$productNodes || $productNodes->count() === 0) {
                Log::warning("No Croma products found with any selector", [
                    'keyword' => $keyword,
                    'page' => $page,
                    'tried_selectors' => $productSelectors
                ]);
                
                // Save HTML for debugging
                $debugFile = storage_path('logs/croma_ranking_debug_no_products_' . time() . '.html');
                file_put_contents($debugFile, $crawler->html());
                Log::debug("Saved HTML for debugging", ['file' => $debugFile]);
                
                return [];
            }

            // Extract each product
            $productNodes->each(function (Crawler $node) use (&$products, &$position, $keywordId, $keyword, $page) {
                try {
                    // Check if sponsored (skip sponsored products)
                    if ($this->isSponsored($node)) {
                        Log::debug("Skipping sponsored Croma product");
                        return;
                    }

                    $position++;

                    // Extract product URL and SKU
                    $productUrl = $this->extractProductUrl($node);
                    $sku = $this->extractSkuFromUrl($productUrl) ?: $this->extractSkuFromNode($node);

                    if (!$sku) {
                        Log::warning("Could not extract SKU from Croma product", [
                            'position' => $position
                        ]);
                        return;
                    }

                    $productData = [
                        'keyword_id' => $keywordId,
                        'sku' => $sku,
                        'platform' => 'croma',
                        'position' => $position,
                        'page' => $page,
                        'search_query' => $keyword,
                        'product_url' => $productUrl,
                        'scraped_at' => now(),
                    ];

                    $products[] = $productData;
                    $this->stats['products_found']++;

                } catch (\Exception $e) {
                    Log::warning("Failed to extract Croma product from search results", [
                        'error' => $e->getMessage()
                    ]);
                }
            });

        } catch (\Exception $e) {
            Log::error("Failed to extract Croma products from page", [
                'error' => $e->getMessage()
            ]);
        }

        return $products;
    }

    /**
     * Check if product is sponsored
     */
    protected function isSponsored(Crawler $node): bool
    {
        $sponsoredIndicators = [
            'data-sponsored',
            'data-ad',
            '.sponsored',
            '.ad-badge',
            'span:contains("Sponsored")',
            'span:contains("Ad")',
        ];

        foreach ($sponsoredIndicators as $indicator) {
            if (strpos($indicator, ':contains') !== false) {
                // Text-based check
                $text = strtolower($node->text());
                if (strpos($text, 'sponsored') !== false || strpos($text, 'ad') !== false) {
                    return true;
                }
            } else {
                // Selector-based check
                $element = $node->filter($indicator);
                if ($element->count() > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Extract product URL from node
     */
    protected function extractProductUrl(Crawler $node): ?string
    {
        $selectors = [
            'a[href*="/p/"]',
            'a[data-testid="product-link"]',
            'a',
        ];

        foreach ($selectors as $selector) {
            $link = $node->filter($selector)->first();
            if ($link->count() > 0) {
                $href = $link->attr('href');
                if ($href) {
                    // Convert relative to absolute
                    if (strpos($href, 'http') !== 0) {
                        $href = 'https://www.croma.com' . $href;
                    }
                    return $href;
                }
            }
        }

        return null;
    }

    /**
     * Extract SKU from URL
     */
    protected function extractSkuFromUrl(?string $url): ?string
    {
        if (!$url) {
            return null;
        }

        // Pattern: /product-name/p/299691
        if (preg_match('/\/p\/(\d+)/', $url, $matches)) {
            return $matches[1];
        }

        // Pattern: /product-name-sku
        if (preg_match('/\/([a-zA-Z0-9\-]+)$/', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Extract SKU from node
     */
    protected function extractSkuFromNode(Crawler $node): ?string
    {
        $selectors = [
            '[data-product-id]',
            '[data-sku]',
            '[data-id]',
        ];

        foreach ($selectors as $selector) {
            $element = $node->filter($selector)->first();
            if ($element->count() > 0) {
                $sku = $element->attr('data-product-id') 
                    ?: $element->attr('data-sku')
                    ?: $element->attr('data-id');
                if ($sku) {
                    return $sku;
                }
            }
        }

        return null;
    }

    /**
     * Save ranking to database
     */
    protected function saveRanking(array $rankingData): void
    {
        try {
            ProductRanking::updateOrCreate(
                [
                    'keyword_id' => $rankingData['keyword_id'],
                    'sku' => $rankingData['sku'],
                    'platform' => $rankingData['platform'],
                    'scraped_at' => $rankingData['scraped_at'],
                ],
                $rankingData
            );

            $this->stats['rankings_saved']++;

            Log::debug("Saved Croma ranking", [
                'sku' => $rankingData['sku'],
                'position' => $rankingData['position'],
                'keyword_id' => $rankingData['keyword_id']
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to save Croma ranking", [
                'sku' => $rankingData['sku'] ?? 'unknown',
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

    /**
     * Set maximum pages to scrape per keyword
     */
    public function setMaxPages(int $maxPages): self
    {
        $this->maxPages = $maxPages;
        return $this;
    }
}
