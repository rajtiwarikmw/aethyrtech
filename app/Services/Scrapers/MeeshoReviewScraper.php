<?php

namespace App\Services\Scrapers;

use App\Models\Product;
use App\Models\Review;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Meesho Review Scraper
 * 
 * Scrapes customer reviews from Meesho product pages
 * Extracts reviewer name, rating, review text, and helpful count
 * 
 * URL Pattern:
 * - Product: https://www.meesho.com/p/{product-id}
 * - Reviews: https://www.meesho.com/p/{product-id}?reviews=true
 */
class MeeshoReviewScraper
{
    protected Client $httpClient;
    protected ?string $currentProductSku = null;
    protected string $platform = 'meesho';
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
    }

    /**
     * Initialize HTTP client with appropriate headers
     */
    protected function initializeHttpClient(): void
    {
        $this->httpClient = new Client([
            'timeout' => config('scraper.timeout', 30),
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
     * Scrape reviews for products
     */
    public function scrapeReviews(?array $productIds = null, ?int $limit = null): array
    {
        Log::info("Starting Meesho review scraping");

        $query = Product::where('platform', $this->platform)
            ->where('is_active', true)
            ->whereNotNull('product_url');

        if ($productIds) {
            $query->whereIn('id', $productIds);
        }

        if ($limit) {
            $query->limit($limit);
        }

        $products = $query->get();

        Log::info("Found products for review scraping", [
            'total_products' => $products->count()
        ]);

        foreach ($products as $product) {
            try {
                $this->scrapeProductReviews($product);
                $this->stats['products_processed']++;
                $this->randomDelay(2, 4);
            } catch (\Exception $e) {
                Log::error("Failed to scrape Meesho reviews", [
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                $this->stats['errors_count']++;
            }
        }

        Log::info("Completed Meesho review scraping", $this->stats);
        return $this->stats;
    }

    /**
     * Scrape reviews for a specific product
     */
    protected function scrapeProductReviews(Product $product): void
    {
        $this->currentProductSku = $product->sku;

        Log::info("Scraping Meesho reviews for product", [
            'product_id' => $product->id,
            'sku' => $product->sku,
            'url' => $product->product_url
        ]);

        // Meesho reviews are typically on the product page itself
        // We'll scrape multiple pages if available
        for ($page = 1; $page <= 5; $page++) {
            try {
                $reviewsUrl = $this->getReviewsUrl($product->product_url, $page);
                $html = $this->fetchPage($reviewsUrl);

                if (!$html) {
                    Log::debug("Failed to fetch Meesho reviews page", [
                        'sku' => $product->sku,
                        'page' => $page
                    ]);
                    break;
                }

                $crawler = new Crawler($html);
                $reviews = $this->extractReviewsFromPage($crawler, $product);

                if (empty($reviews)) {
                    Log::debug("No reviews found on Meesho page", [
                        'sku' => $product->sku,
                        'page' => $page
                    ]);
                    break;
                }

                // Save reviews
                foreach ($reviews as $review) {
                    $this->saveReview($product, $review);
                }

                $this->randomDelay(1, 3);
            } catch (\Exception $e) {
                Log::error("Error scraping Meesho reviews page", [
                    'sku' => $product->sku,
                    'page' => $page,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Get reviews URL for product
     */
    protected function getReviewsUrl(string $productUrl, int $page = 1): string
    {
        // Remove any existing query parameters
        $baseUrl = strtok($productUrl, '?');
        
        if ($page === 1) {
            return $baseUrl . '?reviews=true';
        }
        
        return $baseUrl . '?reviews=true&page=' . $page;
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
     * Extract reviews from page
     */
    protected function extractReviewsFromPage(Crawler $crawler, Product $product): array
    {
        $reviews = [];

        try {
            // Meesho review selectors
            $selectors = [
                'div[data-testid="reviewCard"]',
                'div.reviewCard',
                'div[class*="review"]',
                'div[class*="Review"]',
            ];

            $reviewElements = [];
            foreach ($selectors as $selector) {
                $elements = $crawler->filter($selector);
                if ($elements->count() > 0) {
                    $elements->each(function (Crawler $node) use (&$reviewElements) {
                        $reviewElements[] = $node;
                    });
                }
            }

            // Remove duplicates
            $reviewElements = array_unique($reviewElements, SORT_REGULAR);

            foreach ($reviewElements as $element) {
                try {
                    $review = $this->extractReviewData($element);
                    
                    if ($review) {
                        $reviews[] = $review;
                        $this->stats['reviews_found']++;
                    }
                } catch (\Exception $e) {
                    Log::debug("Error extracting review data: " . $e->getMessage());
                }
            }

            Log::debug("Extracted reviews from Meesho page", [
                'sku' => $product->sku,
                'reviews_count' => count($reviews)
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to extract reviews from Meesho page", [
                'sku' => $product->sku,
                'error' => $e->getMessage()
            ]);
        }

        return $reviews;
    }

    /**
     * Extract review data from a review element
     */
    protected function extractReviewData(Crawler $element): ?array
    {
        try {
            // Extract reviewer name
            $reviewerName = $this->extractReviewerName($element);
            if (!$reviewerName) {
                return null;
            }

            // Extract rating
            $rating = $this->extractRating($element);
            if (!$rating) {
                return null;
            }

            // Extract review text
            $reviewText = $this->extractReviewText($element);
            if (!$reviewText) {
                return null;
            }

            // Extract review title
            $reviewTitle = $this->extractReviewTitle($element);

            // Extract helpful count
            $helpfulCount = $this->extractHelpfulCount($element);

            // Extract review date
            $reviewDate = $this->extractReviewDate($element);

            // Extract verified purchase
            $isVerified = $this->isVerifiedPurchase($element);

            return [
                'reviewer_name' => $reviewerName,
                'rating' => $rating,
                'review_title' => $reviewTitle,
                'review_text' => $reviewText,
                'helpful_count' => $helpfulCount,
                'review_date' => $reviewDate,
                'is_verified' => $isVerified,
            ];
        } catch (\Exception $e) {
            Log::debug("Error extracting review data: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Extract reviewer name
     */
    protected function extractReviewerName(Crawler $element): ?string
    {
        try {
            $selectors = [
                'span[class*="reviewer"]',
                'span[class*="name"]',
                'div[class*="reviewer"]',
                'p[class*="reviewer"]',
            ];

            foreach ($selectors as $selector) {
                $nameElement = $element->filter($selector);
                if ($nameElement->count() > 0) {
                    $text = trim($nameElement->first()->text());
                    if ($text && strlen($text) > 1) {
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
     * Extract rating
     */
    protected function extractRating(Crawler $element): ?int
    {
        try {
            $selectors = [
                'span[class*="rating"]',
                'span[class*="star"]',
                'div[class*="rating"]',
                'div[class*="star"]',
            ];

            foreach ($selectors as $selector) {
                $ratingElement = $element->filter($selector);
                if ($ratingElement->count() > 0) {
                    $text = $ratingElement->first()->text();
                    if (preg_match('/\d+/', $text, $matches)) {
                        $rating = intval($matches[0]);
                        if ($rating >= 1 && $rating <= 5) {
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
     * Extract review title
     */
    protected function extractReviewTitle(Crawler $element): ?string
    {
        try {
            $selectors = [
                'h3',
                'h4',
                'span[class*="title"]',
                'p[class*="title"]',
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
     * Extract review text
     */
    protected function extractReviewText(Crawler $element): ?string
    {
        try {
            $selectors = [
                'p[class*="review"]',
                'div[class*="review"]',
                'span[class*="text"]',
                'p',
            ];

            foreach ($selectors as $selector) {
                $textElement = $element->filter($selector);
                if ($textElement->count() > 0) {
                    $text = trim($textElement->first()->text());
                    if ($text && strlen($text) > 10) {
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
     * Extract helpful count
     */
    protected function extractHelpfulCount(Crawler $element): ?int
    {
        try {
            $selectors = [
                'span[class*="helpful"]',
                'span[class*="like"]',
                'div[class*="helpful"]',
            ];

            foreach ($selectors as $selector) {
                $helpfulElement = $element->filter($selector);
                if ($helpfulElement->count() > 0) {
                    $text = $helpfulElement->first()->text();
                    if (preg_match('/\d+/', str_replace(',', '', $text), $matches)) {
                        return intval($matches[0]);
                    }
                }
            }

            return 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Extract review date
     */
    protected function extractReviewDate(Crawler $element): ?string
    {
        try {
            $selectors = [
                'span[class*="date"]',
                'span[class*="time"]',
                'div[class*="date"]',
                'p[class*="date"]',
            ];

            foreach ($selectors as $selector) {
                $dateElement = $element->filter($selector);
                if ($dateElement->count() > 0) {
                    $text = trim($dateElement->first()->text());
                    if ($text && strlen($text) > 2) {
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
     * Check if verified purchase
     */
    protected function isVerifiedPurchase(Crawler $element): bool
    {
        try {
            $selectors = [
                'span:contains("Verified")',
                'span:contains("verified")',
                'span[class*="verified"]',
                'div[class*="verified"]',
            ];

            foreach ($selectors as $selector) {
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
     * Save review to database
     */
    protected function saveReview(Product $product, array $reviewData): void
    {
        try {
            // Create unique identifier for review
            $reviewHash = md5(
                $product->id . 
                $reviewData['reviewer_name'] . 
                $reviewData['rating'] . 
                substr($reviewData['review_text'], 0, 50)
            );

            // Check if review already exists
            $existingReview = Review::where('product_id', $product->id)
                ->where('platform', $this->platform)
                ->where('review_hash', $reviewHash)
                ->first();

            if ($existingReview) {
                // Update existing review
                $existingReview->update([
                    'helpful_count' => $reviewData['helpful_count'],
                    'updated_at' => now(),
                ]);
                $this->stats['reviews_updated']++;
            } else {
                // Create new review
                Review::create([
                    'product_id' => $product->id,
                    'platform' => $this->platform,
                    'reviewer_name' => $reviewData['reviewer_name'],
                    'rating' => $reviewData['rating'],
                    'review_title' => $reviewData['review_title'],
                    'review_text' => $reviewData['review_text'],
                    'helpful_count' => $reviewData['helpful_count'],
                    'review_date' => $reviewData['review_date'],
                    'is_verified' => $reviewData['is_verified'],
                    'review_hash' => $reviewHash,
                ]);
                $this->stats['reviews_added']++;
            }

            Log::debug("Saved review", [
                'product_id' => $product->id,
                'reviewer' => $reviewData['reviewer_name'],
                'rating' => $reviewData['rating']
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to save review", [
                'product_id' => $product->id,
                'reviewer' => $reviewData['reviewer_name'],
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
