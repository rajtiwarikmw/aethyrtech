<?php

namespace App\Services\Scrapers;

use App\Models\Keyword;
use App\Models\Product;
use App\Models\ProductRanking;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class VijaySalesRankingScraper
{
    protected Client $httpClient;
    protected string $platform = 'vijaysales';
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

        foreach ($keywords as $keyword) {
            try {
                $this->scrapeKeywordRankings($keyword);
                $this->stats['keywords_processed']++;
                $this->randomDelay(3, 6);
            } catch (\Exception $e) {
                Log::error("Failed to scrape VijaySales rankings", [
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
                Log::error("Error scraping VijaySales search page", [
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
        $baseUrl = 'https://www.vijaysales.com/search';
        $params = [
            'q' => $keyword,
            'p' => $page,
        ];
        return $baseUrl . '?' . http_build_query($params);
    }

    protected function fetchPage(string $url): ?string
    {
        try {
            $response = $this->httpClient->get($url);
            if ($response->getStatusCode() === 200) {
                return $response->getBody()->getContents();
            }
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function extractProductsFromPage(Crawler $crawler, int $page, int $startPosition): array
    {
        $products = [];
        $positionOnPage = 0;

        try {
            $crawler->filter('.product-item, .product-card')->each(function (Crawler $node) use (&$products, $page, &$positionOnPage, $startPosition) {
                try {
                    $positionOnPage++;
                    $globalPosition = $startPosition + $positionOnPage;

                    // Extract product SKU from data attribute or URL
                    $sku = $node->attr('data-product-id');
                    
                    if (!$sku) {
                        $linkNode = $node->filter('a');
                        if ($linkNode->count() > 0) {
                            $href = $linkNode->attr('href');
                            if (preg_match('/\/([a-zA-Z0-9\-]+)\.html/', $href, $matches)) {
                                $sku = $matches[1];
                            }
                        }
                    }

                    if (!$sku) {
                        return;
                    }

                    $products[] = [
                        'sku' => $sku,
                        'position' => $globalPosition,
                        'page' => $page,
                    ];

                    $this->stats['products_found']++;
                } catch (\Exception $e) {
                    // Skip this product
                }
            });
        } catch (\Exception $e) {
            Log::error("Failed to extract VijaySales products", ['error' => $e->getMessage()]);
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
                'position' => $productData['position'],
                'page' => $productData['page'],
            ]);

            $this->stats['rankings_recorded']++;
        } catch (\Exception $e) {
            Log::error("Failed to save VijaySales ranking", ['error' => $e->getMessage()]);
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
