<?php

namespace App\Services\Scrapers;

use App\Models\Product;
use App\Models\Review;
use App\Services\BrowserService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class FlipkartReviewScraper
{
    protected Client $httpClient;
    protected BrowserService $browserService;
    protected ?string $currentProductSku = null;
    protected array $stats = [
        'products_processed' => 0,
        'reviews_found' => 0,
        'reviews_added' => 0,
        'reviews_updated' => 0,
        'errors_count' => 0,
    ];

    public function __construct()
    {
        $this->initializeHttpClient();
        $this->browserService = new BrowserService();
    }

    protected function initializeHttpClient(): void
    {
        $this->httpClient = new Client([
            'timeout' => config('scraper.timeout', 30),
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
            ],
            'verify' => false,
            'allow_redirects' => true,
            'http_errors' => false
        ]);
    }

    public function scrapeReviews(?array $productIds = null, ?int $limit = null): array
    {
        Log::info("Starting Flipkart review scraping");

        $query = Product::where('platform', 'flipkart')
            ->where('is_active', true)
            ->whereNotNull('product_url');

        if ($productIds) {
            $query->whereIn('id', $productIds);
        }

        if ($limit) {
            $query->limit($limit);
        }

        $products = $query->get();

        foreach ($products as $product) {
            try {
                $this->scrapeProductReviews($product);
                $this->stats['products_processed']++;
                $this->randomDelay(2, 4);
            } catch (\Exception $e) {
                Log::error("Failed to scrape Flipkart reviews", [
                    'product_id' => $product->id,
                    'error' => $e->getMessage()
                ]);
                $this->stats['errors_count']++;
            }
        }

        return $this->stats;
    }

    protected function scrapeProductReviews(Product $product): void
    {
        // Store product SKU for use in review data
        $this->currentProductSku = $product->sku;
        
        // Flipkart reviews URL format: https://www.flipkart.com/product/product-reviews/{sku}
        $reviewsUrl = $this->getReviewsUrl($product->sku);

        if (!$reviewsUrl) {
            return;
        }

        for ($page = 1; $page <= 5; $page++) {
            try {
                $pageUrl = $page === 1 ? $reviewsUrl : $reviewsUrl . "&page={$page}";
                $html = $this->fetchPage($pageUrl);

                if (!$html) {
                    break;
                }

                $crawler = new Crawler($html);
                $reviews = $this->extractReviews($crawler, $product->id);

                if (empty($reviews)) {
                    break;
                }

                foreach ($reviews as $reviewData) {
                    $this->saveReview($reviewData);
                }

                $this->randomDelay(3, 5);
            } catch (\Exception $e) {
                Log::error("Error scraping Flipkart reviews page", [
                    'product_id' => $product->id,
                    'page' => $page,
                    'error' => $e->getMessage()
                ]);
                break;
            }
        }
    }

    protected function getReviewsUrl(string $sku): ?string
    {
        // Flipkart review page format: https://www.flipkart.com/product/product-reviews/{sku}
        // Example: https://www.flipkart.com/product/product-reviews/itmd0ee2f16df471
        if (!$sku) {
            Log::warning("Cannot generate Flipkart review URL without SKU");
            return null;
        }
        
        return "https://www.flipkart.com/product/product-reviews/{$sku}";
    }

    protected function fetchPage(string $url): ?string
    {
        try {
            Log::debug("Fetching Flipkart review page with BrowserService", ['url' => $url]);
            
            $html = $this->browserService->getPageContent($url, 3, 120);
            
            if ($html && strlen($html) > 500) {
                return $html;
            }
            
            Log::warning("Flipkart review page fetch failed or returned empty content", ['url' => $url]);
            return null;
        } catch (\Exception $e) {
            Log::error("Failed to fetch Flipkart review page", ['url' => $url, 'error' => $e->getMessage()]);
            return null;
        }
    }

    protected function extractReviews(Crawler $crawler, int $productId): array
    {
        $reviews = [];

        try {
            // Updated selector for 2024 Flipkart structure: div.col.x_CUu6.QccLnz
            $crawler->filter('div.col.x_CUu6.QccLnz, div._1AtVbE, div.col-12-12')->each(function (Crawler $reviewNode) use (&$reviews, $productId) {
                try {
                    $reviewData = [
                        'product_id' => $productId,
                        'platform' => "flipkart",
                        'sku' => $this->currentProductSku,
                        'review_id' => $this->extractReviewId($reviewNode),
                        'reviewer_name' => $this->extractReviewerName($reviewNode),
                        'rating' => $this->extractRating($reviewNode),
                        'review_title' => $this->extractReviewTitle($reviewNode),
                        'review_text' => $this->extractReviewText($reviewNode),
                        'review_date' => $this->extractReviewDate($reviewNode),
                        'verified_purchase' => $this->extractVerifiedPurchase($reviewNode),
                        'helpful_count' => $this->extractHelpfulCount($reviewNode),
                    ];

                    if ($reviewData['review_id'] || $reviewData['review_text']) {
                        $reviews[] = $reviewData;
                        $this->stats['reviews_found']++;
                    }
                } catch (\Exception $e) {
                    Log::warning("Failed to extract Flipkart review", ['error' => $e->getMessage()]);
                }
            });
        } catch (\Exception $e) {
            Log::error("Failed to extract Flipkart reviews from page", ['error' => $e->getMessage()]);
        }

        return $reviews;
    }

    protected function extractReviewId(Crawler $reviewNode): ?string
    {
        // Extract actual review ID from the id attribute
        // Example: id="review-69c871e6-a4cb-4611-b35f-9a6d8713aae0"
        try {
            // Try to find element with id starting with "review-"
            $html = $reviewNode->html();
            if (preg_match('/id="review-([a-f0-9-]+)"/', $html, $matches)) {
                return $matches[1]; // Return the UUID part
            }
            
            // Fallback: Try to find from permalink
            $permalink = $reviewNode->filter('a[href*="reviewId="]');
            if ($permalink->count() > 0) {
                $href = $permalink->attr('href');
                if (preg_match('/reviewId=([a-f0-9-]+)/', $href, $matches)) {
                    return $matches[1];
                }
            }
            
            // Last fallback: Generate from content hash
            $text = $reviewNode->text();
            return 'FK_' . substr(md5($text), 0, 16);
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function extractReviewerName(Crawler $reviewNode): ?string
    {
        try {
            // 2024 selector: p.zJ1ZGa.ZDi3w2
            $selectors = ['p.zJ1ZGa.ZDi3w2', 'p._2sc7ZR._2V5EHH', 'p._2NsDsF'];
            foreach ($selectors as $selector) {
                $element = $reviewNode->filter($selector);
                if ($element->count() > 0) {
                    $name = trim($element->first()->text());
                    // Remove extra spaces
                    $name = preg_replace('/\s+/', ' ', $name);
                    return $name;
                }
            }
        } catch (\Exception $e) {
            // Ignore
        }
        return null;
    }

    protected function extractRating(Crawler $reviewNode): ?float
    {
        try {
            // 2024 selector: div.MKiFS6.ojKpP6 (contains rating number)
            $selectors = ['div.MKiFS6.ojKpP6', 'div._3LWZlK', 'div.hGSR34'];
            foreach ($selectors as $selector) {
                $element = $reviewNode->filter($selector);
                if ($element->count() > 0) {
                    $ratingText = $element->first()->text();
                    if (preg_match('/(\d+)/', $ratingText, $matches)) {
                        return (float) $matches[1];
                    }
                }
            }
        } catch (\Exception $e) {
            // Ignore
        }
        return null;
    }

    protected function extractReviewTitle(Crawler $reviewNode): ?string
    {
        try {
            // 2024 selector: p.qW2QI1
            $selectors = ['p.qW2QI1', 'p._2-N8zT'];
            foreach ($selectors as $selector) {
                $element = $reviewNode->filter($selector);
                if ($element->count() > 0) {
                    return trim($element->first()->text());
                }
            }
        } catch (\Exception $e) {
            // Ignore
        }
        return null;
    }

    protected function extractReviewText(Crawler $reviewNode): ?string
    {
        try {
            // 2024 selector: div.G4PxIA div (contains review text)
            $selectors = ['div.G4PxIA div div', 'div.t-ZTKy', 'div._1AtVbE div div'];
            foreach ($selectors as $selector) {
                $element = $reviewNode->filter($selector);
                if ($element->count() > 0) {
                    $text = trim($element->first()->text());
                    // Remove "READ MORE" text if present
                    $text = str_replace('READ MORE', '', $text);
                    $text = trim($text);
                    return $text;
                }
            }
        } catch (\Exception $e) {
            // Ignore
        }
        return null;
    }

    protected function extractReviewDate(Crawler $reviewNode): ?string
    {
        try {
            // 2024 selector: p.zJ1ZGa (last occurrence contains date like "11 months ago")
            $elements = $reviewNode->filter('p.zJ1ZGa');
            if ($elements->count() > 0) {
                // Get the last p.zJ1ZGa which usually contains the date
                $dateText = $elements->last()->text();
                
                // Handle relative dates like "11 months ago", "2 days ago"
                if (preg_match('/(\d+)\s+(day|week|month|year)s?\s+ago/i', $dateText, $matches)) {
                    $amount = (int)$matches[1];
                    $unit = strtolower($matches[2]);
                    
                    $date = new \DateTime();
                    switch ($unit) {
                        case 'day':
                            $date->modify("-{$amount} days");
                            break;
                        case 'week':
                            $date->modify("-{$amount} weeks");
                            break;
                        case 'month':
                            $date->modify("-{$amount} months");
                            break;
                        case 'year':
                            $date->modify("-{$amount} years");
                            break;
                    }
                    return $date->format('Y-m-d');
                }
                
                // Handle absolute dates like "Jun, 2023"
                if (preg_match('/(\w+),?\s+(\d{4})/', $dateText, $matches)) {
                    $date = \DateTime::createFromFormat('M Y', "{$matches[1]} {$matches[2]}");
                    if ($date) {
                        return $date->format('Y-m-d');
                    }
                }
            }
        } catch (\Exception $e) {
            // Ignore
        }
        return null;
    }

    protected function extractVerifiedPurchase(Crawler $reviewNode): bool
    {
        try {
            // 2024 selector: p.Zhmv6U (contains "Certified Buyer")
            $selectors = ['p.Zhmv6U', 'p._2mcZGG'];
            foreach ($selectors as $selector) {
                $element = $reviewNode->filter($selector);
                if ($element->count() > 0) {
                    $text = $element->text();
                    return strpos($text, 'Certified Buyer') !== false;
                }
            }
        } catch (\Exception $e) {
            return false;
        }
        return false;
    }

    protected function extractHelpfulCount(Crawler $reviewNode): int
    {
        try {
            // 2024 selector: span.Fp3hrV (contains helpful count like "109")
            $selectors = ['span.Fp3hrV', 'span._3c3Px5'];
            foreach ($selectors as $selector) {
                $elements = $reviewNode->filter($selector);
                if ($elements->count() > 0) {
                    // Get the first one (thumbs up count)
                    $text = $elements->first()->text();
                    if (preg_match('/(\d+)/', $text, $matches)) {
                        return (int) $matches[1];
                    }
                }
            }
        } catch (\Exception $e) {
            // Ignore
        }
        return 0;
    }

    protected function saveReview(array $reviewData): void
    {
        try {
            $existingReview = Review::findByProductAndReviewId(
                $reviewData['product_id'],
                $reviewData['review_id']
            );

            if ($existingReview) {
                if ($existingReview->updateIfChanged($reviewData)) {
                    $this->stats['reviews_updated']++;
                }
            } else {
                Review::create($reviewData);
                $this->stats['reviews_added']++;
            }
        } catch (\Exception $e) {
            Log::error("Failed to save Flipkart review", [
                'review_id' => $reviewData['review_id'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            $this->stats['errors_count']++;
        }
    }

    protected function randomDelay(int $min = 3, int $max = 8): void
    {
        sleep(rand($min, $max));
    }

    public function getStats(): array
    {
        return $this->stats;
    }
}
