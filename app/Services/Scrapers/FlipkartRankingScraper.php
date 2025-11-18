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
    protected int $maxPages = 5;
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

        $globalPosition = 0;

        for ($page = 1; $page <= $this->maxPages; $page++) {
            try {
                $url = $this->buildSearchUrl($keyword->keyword, $page);
                $html = $this->fetchPage($url);

                if (!$html) {
                    break;
                }

                $crawler = new Crawler($html);
                $products = $this->extractProductsFromPage($crawler, $page, $globalPosition);

                if (empty($products)) {
                    break;
                }

                foreach ($products as $productData) {
                    $this->saveRanking($keyword, $productData);
                }

                $globalPosition += count($products);
                $this->randomDelay(2, 4);
            } catch (\Exception $e) {
                Log::error("Error scraping Flipkart search page", [
                    'keyword' => $keyword->keyword,
                    'page' => $page,
                    'error' => $e->getMessage()
                ]);
                break;
            }
        }
    }

    protected function buildSearchUrl(string $keyword, int $page): string
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
            
            Log::debug("Flipkart page response", [
                'status_code' => $statusCode,
                'content_length' => strlen($response->getBody()->getContents())
            ]);
            
            if ($statusCode === 200) {
                return $response->getBody()->getContents();
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

    protected function extractProductsFromPage(Crawler $crawler, int $page, int $startPosition): array
    {
        $products = [];
        $positionOnPage = 0;

        try {
            // Try multiple Flipkart product selectors
            $selectors = [
                'div[data-id]',
                'div._1AtVbE',
                'div._13oc-S',
                'div._1YokD2',
                'div[data-tkid]',
                'a[href*="/p/"]',
            ];

            $foundProducts = false;
            
            foreach ($selectors as $selector) {
                $nodes = $crawler->filter($selector);
                
                if ($nodes->count() > 0) {
                    Log::debug("Found Flipkart products using selector", [
                        'selector' => $selector,
                        'count' => $nodes->count()
                    ]);
                    $foundProducts = true;
                    break;
                }
            }

            if (!$foundProducts) {
                Log::warning("No Flipkart products found with any selector", [
                    'tried_selectors' => $selectors,
                    'page' => $page
                ]);
                return [];
            }

            // Flipkart product selectors
            $crawler->filter('div[data-id], div._1AtVbE, div._13oc-S, div._1YokD2, div[data-tkid]')->each(function (Crawler $node) use (&$products, $page, &$positionOnPage, $startPosition) {
                try {
                    $positionOnPage++;
                    $globalPosition = $startPosition + $positionOnPage;

                    // Extract product ID from data-id attribute or URL
                    $productId = $node->attr('data-id');
                    
                    if (!$productId) {
                        // Try to extract from link
                        $linkNode = $node->filter('a');
                        if ($linkNode->count() > 0) {
                            $href = $linkNode->attr('href');
                            // Extract product ID from Flipkart URL pattern
                            if (preg_match('/\/p\/([a-zA-Z0-9]+)/', $href, $matches)) {
                                $productId = $matches[1];
                            }
                        }
                    }

                    if (!$productId) {
                        return;
                    }

                    $products[] = [
                        'sku' => $productId,
                        'position' => $globalPosition,
                        'page' => $page,
                    ];

                    Log::debug("Found Flipkart product", [
                        'sku' => $productId,
                        'position' => $globalPosition,
                        'page' => $page
                    ]);

                    $this->stats['products_found']++;
                } catch (\Exception $e) {
                    // Skip this product
                }
            });
        } catch (\Exception $e) {
            Log::error("Failed to extract Flipkart products", ['error' => $e->getMessage()]);
        }

        return $products;
    }

    protected function saveRanking(Keyword $keyword, array $productData): void
    {
        try {
            $product = Product::where('platform', $this->platform)
                ->where('sku', $productData['sku'])
                ->first();

            ProductRanking::create([
                'product_id' => $product ? $product->id : null,
                'sku' => $productData['sku'],
                'keyword_id' => $keyword->id,
                'platform' => $this->platform,
                'position' => $productData['position'],
                'page' => $productData['page'],
            ]);

            $this->stats['rankings_recorded']++;
        } catch (\Exception $e) {
            Log::error("Failed to save Flipkart ranking", ['error' => $e->getMessage()]);
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
