<?php

namespace App\Services\Scrapers;

use App\Models\Product;
use App\Models\Review;
use App\Services\BrowserServiceWithCookies;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class AmazonReviewScraperWithAuth
{
    protected BrowserServiceWithCookies $browserService;
    protected array $stats = [
        'products_processed' => 0,
        'reviews_found' => 0,
        'reviews_added' => 0,
        'reviews_updated' => 0,
        'errors_count' => 0,
    ];

    public function __construct()
    {
        $this->browserService = new BrowserServiceWithCookies();
        
        // Load cookies from config
        $cookies = config('amazon_cookies.cookies', []);
        if (!empty($cookies)) {
            $this->browserService->setCookies($cookies);
            Log::info("Amazon cookies loaded", ['cookie_count' => count($cookies)]);
        } else {
            Log::warning("No Amazon cookies configured. Reviews may not be accessible.");
        }
    }

    /**
     * Scrape reviews for all Amazon products or specific product IDs
     */
    public function scrapeReviews(?array $productIds = null, ?int $limit = null): array
    {
        Log::info("Starting Amazon review scraping (Authenticated Browser mode)", [
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
                $this->randomDelay(3, 6);
            } catch (\Exception $e) {
                Log::error("Failed to scrape reviews for product", [
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'error' => $e->getMessage()
                ]);
                $this->stats['errors_count']++;
            }
        }

        Log::info("Amazon review scraping completed (Authenticated Browser mode)", $this->stats);

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

        $maxPages = 5; // Maximum pages to scrape per product
        
        for ($page = 1; $page <= $maxPages; $page++) {
            try {
                $url = $this->buildReviewUrl($product->sku, $page);
                
                Log::info("Fetching reviews page (Authenticated Browser mode)", [
                    'product_id' => $product->id,
                    'page' => $page,
                    'url' => $url
                ]);

                // Use browser service with cookies to fetch page
                $html = $this->browserService->getPageContent($url, 5);
                
                if (!$html) {
                    Log::warning("Failed to fetch reviews page", [
                        'product_id' => $product->id,
                        'page' => $page
                    ]);
                    break;
                }

                // Check if redirected to login page
                if ($this->isLoginPage($html)) {
                    Log::warning("Redirected to login page (cookies may be expired)", [
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

                Log::info("Extracted reviews from page", [
                    'product_id' => $product->id,
                    'page' => $page,
                    'count' => count($reviews)
                ]);

                // Save reviews to database
                foreach ($reviews as $reviewData) {
                    $this->saveReview($reviewData);
                }

                // Add delay between pages
                $this->randomDelay(2, 4);
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
     * Check if page is a login page
     */
    protected function isLoginPage(string $html): bool
    {
        $loginIndicators = [
            'ap_signin_form',
            'Sign-In',
            'Sign in to continue',
            'Enter your email or mobile phone number',
            'ap_email',
            'ap_password',
        ];

        foreach ($loginIndicators as $indicator) {
            if (stripos($html, $indicator) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build review URL for a product
     */
    protected function buildReviewUrl(string $asin, int $page = 1): string
    {
        $baseUrl = 'https://www.amazon.in/product-reviews/' . $asin;
        $params = [
            'ie' => 'UTF8',
            'reviewerType' => 'all_reviews',
            'pageNumber' => $page,
        ];

        return $baseUrl . '?' . http_build_query($params);
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
                'li[data-hook="review"]',
                'div[data-hook="review"]',
                'li.review',
                'div[id*="customer_review"]',
                'div.review',
                'div.a-section.review',
                '[data-hook="review-collapsed"]',
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
            $element = $reviewNode->filter('.a-profile-name');
            if ($element->count() > 0) {
                return trim($element->first()->text());
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
            $element = $reviewNode->filter('.a-profile');
            if ($element->count() > 0) {
                $href = $element->first()->attr('href');
                if ($href) {
                    return 'https://www.amazon.in' . $href;
                }
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
                // Extract number from "X people found this helpful" format
                if (preg_match('/(\d+)/', $text, $matches)) {
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
            $review = Review::updateOrCreate(
                [
                    'product_id' => $reviewData['product_id'],
                    'review_id' => $reviewData['review_id'],
                ],
                [
                    'reviewer_name' => $reviewData['reviewer_name'],
                    'reviewer_profile_url' => $reviewData['reviewer_profile_url'],
                    'rating' => $reviewData['rating'],
                    'review_title' => $reviewData['review_title'],
                    'review_text' => $reviewData['review_text'],
                    'review_date' => $reviewData['review_date'],
                    'verified_purchase' => $reviewData['verified_purchase'],
                    'helpful_count' => $reviewData['helpful_count'],
                    'review_images' => $reviewData['review_images'] ? json_encode($reviewData['review_images']) : null,
                    'variant_info' => $reviewData['variant_info'],
                ]
            );

            if ($review->wasRecentlyCreated) {
                $this->stats['reviews_added']++;
            } else {
                $this->stats['reviews_updated']++;
            }
        } catch (\Exception $e) {
            Log::error("Failed to save review", [
                'review_id' => $reviewData['review_id'],
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Random delay between requests
     */
    protected function randomDelay(int $min, int $max): void
    {
        $seconds = rand($min, $max);
        sleep($seconds);
    }

    /**
     * Get scraping statistics
     */
    public function getStats(): array
    {
        return $this->stats;
    }
}
