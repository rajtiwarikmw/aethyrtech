<?php

namespace App\Services\Scrapers;

use App\Models\Product;
use App\Models\Review;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class AmazonReviewScraper
{
    protected Client $httpClient;
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
     * Initialize HTTP client with proper configuration
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
     * Scrape reviews for all Amazon products or specific product IDs
     */
    public function scrapeReviews(?array $productIds = null, ?int $limit = null): array
    {
        Log::info("Starting Amazon review scraping", [
            'product_ids' => $productIds,
            'limit' => $limit
        ]);

        // Get products to scrape
        $query = Product::where('platform', 'amazon')
            ->where('is_active', true)
            ->whereNotNull('product_url');

        if ($productIds) {
            $query->whereIn('id', $productIds);
        }

        if ($limit) {
            $query->limit($limit);
        }

        $products = $query->get();

        Log::info("Found {$products->count()} products to scrape reviews for");

        foreach ($products as $product) {
            try {
                $this->scrapeProductReviews($product);
                $this->stats['products_processed']++;

                // Add delay between products to avoid rate limiting
                $this->randomDelay(2, 4);
            } catch (\Exception $e) {
                Log::error("Failed to scrape reviews for product", [
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'error' => $e->getMessage()
                ]);
                $this->stats['errors_count']++;
            }
        }

        Log::info("Amazon review scraping completed", $this->stats);

        return $this->stats;
    }

    /**
     * Scrape reviews for a single product
     */
    protected function scrapeProductReviews(Product $product): void
    {
        Log::info("Scraping reviews for product", [
            'product_id' => $product->id,
            'sku' => $product->sku,
            'title' => $product->title
        ]);

        // Get the reviews URL from the product URL
        $reviewsUrl = $this->getReviewsUrl($product->product_url, $product->sku);

        if (!$reviewsUrl) {
            Log::warning("Could not generate reviews URL for product", [
                'product_id' => $product->id,
                'product_url' => $product->product_url
            ]);
            return;
        }

        // Scrape multiple pages of reviews
        $maxPages = 5; // Scrape up to 5 pages of reviews per product
        for ($page = 1; $page <= $maxPages; $page++) {
            try {
                $pageUrl = $page === 1 ? $reviewsUrl : $reviewsUrl . "&pageNumber={$page}";
                
                Log::info("Fetching reviews page", [
                    'product_id' => $product->id,
                    'page' => $page,
                    'url' => $pageUrl
                ]);

                $html = $this->fetchPage($pageUrl);
                
                if (!$html) {
                    Log::warning("Failed to fetch reviews page", [
                        'product_id' => $product->id,
                        'page' => $page
                    ]);
                    break;
                }

                $crawler = new Crawler($html);
                $reviews = $this->extractReviews($crawler, $product->id);

                if (empty($reviews)) {
                    Log::info("No more reviews found, stopping pagination", [
                        'product_id' => $product->id,
                        'page' => $page
                    ]);
                    break;
                }

                // Save reviews to database
                foreach ($reviews as $reviewData) {
                    $this->saveReview($reviewData);
                }

                // Add delay between pages
                $this->randomDelay(3, 5);
            } catch (\Exception $e) {
                Log::error("Error scraping reviews page", [
                    'product_id' => $product->id,
                    'page' => $page,
                    'error' => $e->getMessage()
                ]);
                break;
            }
        }
    }

    /**
     * Generate reviews URL from product URL
     */
    protected function getReviewsUrl(string $productUrl, string $sku): ?string
    {
        // Amazon reviews URL format: https://www.amazon.in/product-reviews/{ASIN}/
        if (preg_match('/amazon\.in/', $productUrl)) {
            return "https://www.amazon.in/product-reviews/{$sku}/ref=cm_cr_dp_d_show_all_btm?ie=UTF8&reviewerType=all_reviews";
        }

        return null;
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
     * Extract reviews from page HTML
     */
    protected function extractReviews(Crawler $crawler, int $productId): array
    {
        $reviews = [];

        try {
            // Try multiple review container selectors
            $reviewSelectors = [
                'li[data-hook="review"]',                     // NEW: LI element (current Amazon structure)
                'div[data-hook="review"]',                    // Standard review container (old)
                'li.review',                                   // LI with review class
                'div[id*="customer_review"]',                 // Alternative ID-based
                'div.review',                                  // Class-based
                'div.a-section.review',                        // More specific class
                '[data-hook="review-collapsed"]',             // Collapsed reviews
            ];

            $foundReviews = false;
            
            foreach ($reviewSelectors as $selector) {
                $reviewNodes = $crawler->filter($selector);
                
                if ($reviewNodes->count() > 0) {
                    Log::debug("Found reviews using selector: {$selector}", [
                        'count' => $reviewNodes->count(),
                        'product_id' => $productId
                    ]);
                    $foundReviews = true;
                    
                    $reviewNodes->each(function (Crawler $reviewNode) use (&$reviews, $productId) {
                        try {
                            $reviewData = [
                                'product_id' => $productId,
                                'review_id' => $this->extractReviewId($reviewNode),
                                'reviewer_name' => $this->extractReviewerName($reviewNode),
                                'reviewer_profile_url' => $this->extractReviewerProfileUrl($reviewNode),
                                'rating' => $this->extractRating($reviewNode),
                                'review_title' => $this->extractReviewTitle($reviewNode),
                                'review_text' => $this->extractReviewText($reviewNode),
                                'review_date' => $this->extractReviewDate($reviewNode),
                                'verified_purchase' => $this->extractVerifiedPurchase($reviewNode),
                                'helpful_count' => $this->extractHelpfulCount($reviewNode),
                                'review_images' => $this->extractReviewImages($reviewNode),
                                'variant_info' => $this->extractVariantInfo($reviewNode),
                            ];

                            // Only add if we have at least review_id
                            if ($reviewData['review_id']) {
                                $reviews[] = $reviewData;
                                $this->stats['reviews_found']++;
                            }
                        } catch (\Exception $e) {
                            Log::warning("Failed to extract review data", [
                                'error' => $e->getMessage()
                            ]);
                        }
                    });
                    
                    break; // Found reviews, no need to try other selectors
                }
            }
            
            if (!$foundReviews) {
                Log::warning("No review containers found with any selector", [
                    'product_id' => $productId,
                    'tried_selectors' => $reviewSelectors
                ]);
            }
            
        } catch (\Exception $e) {
            Log::error("Failed to extract reviews from page", [
                'error' => $e->getMessage()
            ]);
        }

        Log::debug("Extracted reviews", [
            'product_id' => $productId,
            'count' => count($reviews)
        ]);

        return $reviews;
    }

    /**
     * Extract review ID
     */
    protected function extractReviewId(Crawler $reviewNode): ?string
    {
        try {
            $id = $reviewNode->attr('id');
            return $id ?: null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Extract reviewer name
     */
    protected function extractReviewerName(Crawler $reviewNode): ?string
    {
        try {
            $selectors = [
                'span.a-profile-name',
                'div.a-profile-content span',
            ];

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

    /**
     * Extract reviewer profile URL
     */
    protected function extractReviewerProfileUrl(Crawler $reviewNode): ?string
    {
        try {
            $element = $reviewNode->filter('a.a-profile');
            if ($element->count() > 0) {
                $href = $element->attr('href');
                if ($href && strpos($href, 'http') !== 0) {
                    $href = 'https://www.amazon.in' . $href;
                }
                return $href;
            }
        } catch (\Exception $e) {
            // Ignore
        }

        return null;
    }

    /**
     * Extract rating
     */
    protected function extractRating(Crawler $reviewNode): ?float
    {
        try {
            $element = $reviewNode->filter('i[data-hook="review-star-rating"] span.a-icon-alt, i.review-rating span.a-icon-alt');
            if ($element->count() > 0) {
                $ratingText = $element->first()->text();
                // Extract number from "5.0 out of 5 stars" format
                if (preg_match('/(\d+\.?\d*)/', $ratingText, $matches)) {
                    return (float) $matches[1];
                }
            }
        } catch (\Exception $e) {
            // Ignore
        }

        return null;
    }

    /**
     * Extract review title
     */
    protected function extractReviewTitle(Crawler $reviewNode): ?string
    {
        try {
            $selectors = [
                'a[data-hook="review-title"] span:not(.a-icon-alt)',
                'h5[data-hook="review-title"] span',
                'a[data-hook="review-title"]',
            ];

            foreach ($selectors as $selector) {
                $element = $reviewNode->filter($selector);
                if ($element->count() > 0) {
                    $title = trim($element->first()->text());
                    // Remove rating text if present
                    $title = preg_replace('/^\d+\.?\d*\s+out of\s+\d+\s+stars\s*/i', '', $title);
                    return $title ?: null;
                }
            }
        } catch (\Exception $e) {
            // Ignore
        }

        return null;
    }

    /**
     * Extract review text
     */
    protected function extractReviewText(Crawler $reviewNode): ?string
    {
        try {
            $element = $reviewNode->filter('span[data-hook="review-body"] span');
            if ($element->count() > 0) {
                return trim($element->first()->text());
            }
        } catch (\Exception $e) {
            // Ignore
        }

        return null;
    }

    /**
     * Extract review date
     */
    protected function extractReviewDate(Crawler $reviewNode): ?string
    {
        try {
            $element = $reviewNode->filter('span[data-hook="review-date"]');
            if ($element->count() > 0) {
                $dateText = $element->first()->text();
                // Extract date from "Reviewed in India on 15 January 2024" format
                if (preg_match('/on\s+(\d+\s+\w+\s+\d{4})/', $dateText, $matches)) {
                    $date = \DateTime::createFromFormat('d F Y', $matches[1]);
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

    /**
     * Extract verified purchase status
     */
    protected function extractVerifiedPurchase(Crawler $reviewNode): bool
    {
        try {
            $element = $reviewNode->filter('span[data-hook="avp-badge"]');
            return $element->count() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Extract helpful count
     */
    protected function extractHelpfulCount(Crawler $reviewNode): int
    {
        try {
            $element = $reviewNode->filter('span[data-hook="helpful-vote-statement"]');
            if ($element->count() > 0) {
                $text = $element->first()->text();
                // Extract number from "123 people found this helpful" format
                if (preg_match('/(\d+)\s+people?/', $text, $matches)) {
                    return (int) $matches[1];
                }
            }
        } catch (\Exception $e) {
            // Ignore
        }

        return 0;
    }

    /**
     * Extract review images
     */
    protected function extractReviewImages(Crawler $reviewNode): ?array
    {
        try {
            $images = [];
            $reviewNode->filter('img[data-hook="review-image-tile"]')->each(function (Crawler $imgNode) use (&$images) {
                $src = $imgNode->attr('src');
                if ($src) {
                    $images[] = $src;
                }
            });

            return !empty($images) ? $images : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Extract variant info
     */
    protected function extractVariantInfo(Crawler $reviewNode): ?string
    {
        try {
            $element = $reviewNode->filter('a[data-hook="format-strip"]');
            if ($element->count() > 0) {
                return trim($element->first()->text());
            }
        } catch (\Exception $e) {
            // Ignore
        }

        return null;
    }

    /**
     * Save review to database
     */
    protected function saveReview(array $reviewData): void
    {
        try {
            // Check if review already exists
            $existingReview = Review::findByProductAndReviewId(
                $reviewData['product_id'],
                $reviewData['review_id']
            );

            if ($existingReview) {
                // Update if data has changed
                if ($existingReview->updateIfChanged($reviewData)) {
                    $this->stats['reviews_updated']++;
                    Log::debug("Updated review", [
                        'review_id' => $reviewData['review_id']
                    ]);
                }
            } else {
                // Create new review
                Review::create($reviewData);
                $this->stats['reviews_added']++;
                Log::debug("Added new review", [
                    'review_id' => $reviewData['review_id']
                ]);
            }
        } catch (\Exception $e) {
            Log::error("Failed to save review", [
                'review_id' => $reviewData['review_id'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            $this->stats['errors_count']++;
        }
    }

    /**
     * Random delay to avoid rate limiting
     */
    protected function randomDelay(int $min = 2, int $max = 5): void
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
