<?php

namespace App\Services\Scrapers;

use App\Models\Product;
use App\Models\Review;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;
use Spatie\Browsershot\Browsershot;

class CromaReviewScraper
{
    protected $stats = [
        'products_processed' => 0,
        'reviews_found' => 0,
        'reviews_saved' => 0,
        'errors' => 0,
    ];

    /**
     * Scrape reviews for all Croma products
     */
    public function scrapeAllReviews(?int $limit = null): array
    {
        Log::info("Starting Croma review scraping", ['limit' => $limit]);

        $query = Product::where('platform', 'croma')
            ->whereNotNull('product_url')
            ->whereNotNull('sku');

        if ($limit) {
            $query->limit($limit);
        }

        $products = $query->get();

        Log::info("Found Croma products to scrape reviews", ['count' => $products->count()]);

        foreach ($products as $product) {
            $this->scrapeProductReviews($product);
            
            // Add delay between products
            sleep(rand(3, 6));
        }

        return $this->stats;
    }

    /**
     * Scrape reviews for a single product
     */
    public function scrapeProductReviews(Product $product): void
    {
        try {
            Log::info("Scraping Croma reviews for product", [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'title' => $product->title
            ]);

            $this->stats['products_processed']++;

            // Reviews are typically on the product page itself
            $reviewsUrl = $product->product_url;
            
            if (!$reviewsUrl) {
                Log::warning("No product URL for Croma product", [
                    'product_id' => $product->id
                ]);
                return;
            }

            // Fetch page with JavaScript rendering
            $html = $this->fetchPageWithBrowsershot($reviewsUrl);

            if (!$html) {
                Log::warning("Failed to fetch Croma reviews page", [
                    'product_id' => $product->id,
                    'review_url' => $reviewsUrl
                ]);
                $this->stats['errors']++;
                return;
            }

            // Parse HTML
            $crawler = new Crawler($html);

            // Extract reviews
            $reviews = $this->extractReviews($crawler, $product->id, $product->sku);

            if (empty($reviews)) {
                Log::info("No Croma reviews found for product", [
                    'product_id' => $product->id,
                    'sku' => $product->sku
                ]);
                return;
            }

            // Save reviews
            foreach ($reviews as $reviewData) {
                $this->saveReview($reviewData);
            }

            Log::info("Scraped Croma reviews for product", [
                'product_id' => $product->id,
                'reviews_count' => count($reviews)
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to scrape Croma reviews for product", [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->stats['errors']++;
        }
    }

    /**
     * Fetch page with JavaScript rendering
     */
    protected function fetchPageWithBrowsershot(string $url): ?string
    {
        try {
            Log::debug("Fetching Croma reviews page with JavaScript", ['url' => $url]);

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

            if (strlen($html) < 1000) {
                Log::warning("Croma returned suspiciously small response", [
                    'url' => $url,
                    'length' => strlen($html)
                ]);
                return null;
            }

            // Check for redirects to homepage
            if (strpos($html, '<title>Croma Electronics | Online Electronics Shopping') !== false &&
                strpos($url, '/p/') !== false) {
                Log::error("Croma redirected product page to homepage", ['url' => $url]);
                
                // Save HTML for debugging
                $debugFile = storage_path('logs/croma_reviews_redirect_' . time() . '.html');
                file_put_contents($debugFile, $html);
                Log::debug("Saved HTML for debugging", ['file' => $debugFile]);
                
                return null;
            }

            return $html;

        } catch (\Exception $e) {
            Log::error("Failed to fetch Croma reviews page", [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Extract reviews from page
     */
    protected function extractReviews(Crawler $crawler, int $productId, string $sku): array
    {
        $reviews = [];

        try {
            // Croma review container selectors
            $containerSelectors = [
                'div[data-testid="review-item"]',
                'div[data-testid="review"]',
                '.review-item',
                '.review-card',
                '.customer-review',
                'div[class*="Review"]',
                '.pdp-review-item',
                '.review-container',
            ];

            $reviewNodes = null;
            $usedSelector = null;

            // Try each selector
            foreach ($containerSelectors as $selector) {
                $nodes = $crawler->filter($selector);
                if ($nodes->count() > 0) {
                    $reviewNodes = $nodes;
                    $usedSelector = $selector;
                    Log::debug("Found Croma reviews using selector", [
                        'selector' => $selector,
                        'count' => $nodes->count()
                    ]);
                    break;
                }
            }

            if (!$reviewNodes || $reviewNodes->count() === 0) {
                Log::warning("No Croma review containers found", [
                    'tried_selectors' => $containerSelectors
                ]);
                
                // Save HTML for debugging
                $debugFile = storage_path('logs/croma_reviews_debug_no_reviews_' . time() . '.html');
                file_put_contents($debugFile, $crawler->html());
                Log::debug("Saved HTML for debugging", ['file' => $debugFile]);
                
                return [];
            }

            // Extract each review
            $reviewNodes->each(function (Crawler $node) use (&$reviews, $productId, $sku) {
                try {
                    $reviewData = [
                        'product_id' => $productId,
                        'sku' => $sku,
                        'platform' => 'croma',
                        'review_id' => $this->extractReviewId($node),
                        'reviewer_name' => $this->extractReviewerName($node),
                        'rating' => $this->extractRating($node),
                        'review_title' => $this->extractReviewTitle($node),
                        'review_text' => $this->extractReviewText($node),
                        'review_date' => $this->extractReviewDate($node),
                        'verified_purchase' => $this->extractVerifiedPurchase($node),
                        'helpful_count' => $this->extractHelpfulCount($node),
                        'images' => $this->extractReviewImages($node),
                    ];

                    // Calculate sentiment based on rating
                    if ($reviewData['rating']) {
                        if ($reviewData['rating'] >= 4) {
                            $reviewData['sentiment'] = 'positive';
                        } elseif ($reviewData['rating'] >= 3) {
                            $reviewData['sentiment'] = 'neutral';
                        } else {
                            $reviewData['sentiment'] = 'negative';
                        }
                    }

                    // Only add if we have minimum required data
                    if ($reviewData['review_text'] || $reviewData['rating']) {
                        $reviews[] = $reviewData;
                        $this->stats['reviews_found']++;
                    }

                } catch (\Exception $e) {
                    Log::warning("Failed to extract Croma review", [
                        'error' => $e->getMessage()
                    ]);
                }
            });

        } catch (\Exception $e) {
            Log::error("Failed to extract Croma reviews", [
                'error' => $e->getMessage()
            ]);
        }

        return $reviews;
    }

    /**
     * Extract review ID
     */
    protected function extractReviewId(Crawler $node): ?string
    {
        $id = $node->attr('data-review-id') ?: $node->attr('data-id') ?: $node->attr('id');
        
        if ($id) {
            return 'CROMA_' . $id;
        }

        // Generate from content hash
        $text = $node->text();
        return 'CROMA_' . substr(md5($text), 0, 12);
    }

    /**
     * Extract reviewer name
     */
    protected function extractReviewerName(Crawler $node): ?string
    {
        $selectors = [
            'span[data-testid="reviewer-name"]',
            '.reviewer-name',
            '.review-author',
            '.author-name',
            '.customer-name',
        ];

        foreach ($selectors as $selector) {
            $element = $node->filter($selector);
            if ($element->count() > 0) {
                return trim($element->first()->text());
            }
        }

        return 'Anonymous';
    }

    /**
     * Extract rating
     */
    protected function extractRating(Crawler $node): ?float
    {
        $selectors = [
            'span[data-testid="rating"]',
            '.rating-value',
            '.star-rating',
            '.review-rating',
        ];

        foreach ($selectors as $selector) {
            $element = $node->filter($selector);
            if ($element->count() > 0) {
                $text = $element->first()->text();
                
                // Extract number from text
                if (preg_match('/(\d+\.?\d*)/', $text, $matches)) {
                    return (float) $matches[1];
                }

                // Count filled stars
                $stars = $node->filter('.star.filled, .star-filled, svg.filled');
                if ($stars->count() > 0) {
                    return (float) $stars->count();
                }
            }
        }

        return null;
    }

    /**
     * Extract review title
     */
    protected function extractReviewTitle(Crawler $node): ?string
    {
        $selectors = [
            'h3[data-testid="review-title"]',
            '.review-title',
            '.review-heading',
            'h4',
            'h5',
        ];

        foreach ($selectors as $selector) {
            $element = $node->filter($selector);
            if ($element->count() > 0) {
                return trim($element->first()->text());
            }
        }

        return null;
    }

    /**
     * Extract review text
     */
    protected function extractReviewText(Crawler $node): ?string
    {
        $selectors = [
            'div[data-testid="review-text"]',
            '.review-text',
            '.review-content',
            '.review-body',
            '.review-description',
            'p',
        ];

        foreach ($selectors as $selector) {
            $element = $node->filter($selector);
            if ($element->count() > 0) {
                $text = trim($element->first()->text());
                if (strlen($text) > 10) {
                    return $text;
                }
            }
        }

        return null;
    }

    /**
     * Extract review date
     */
    protected function extractReviewDate(Crawler $node): ?string
    {
        $selectors = [
            'span[data-testid="review-date"]',
            '.review-date',
            '.review-time',
            'time',
            '.date',
        ];

        foreach ($selectors as $selector) {
            $element = $node->filter($selector);
            if ($element->count() > 0) {
                $dateText = trim($element->first()->text());
                
                // Try to parse date
                try {
                    $date = new \DateTime($dateText);
                    return $date->format('Y-m-d');
                } catch (\Exception $e) {
                    return $dateText;
                }
            }
        }

        return null;
    }

    /**
     * Extract verified purchase status
     */
    protected function extractVerifiedPurchase(Crawler $node): bool
    {
        $selectors = [
            'span[data-testid="verified-badge"]',
            '.verified-purchase',
            '.verified-badge',
            '.verified-buyer',
        ];

        foreach ($selectors as $selector) {
            $element = $node->filter($selector);
            if ($element->count() > 0) {
                $text = strtolower($element->first()->text());
                return strpos($text, 'verified') !== false;
            }
        }

        return false;
    }

    /**
     * Extract helpful count
     */
    protected function extractHelpfulCount(Crawler $node): int
    {
        $selectors = [
            'span[data-testid="helpful-count"]',
            '.helpful-count',
            '.vote-count',
            '.thumbs-up-count',
        ];

        foreach ($selectors as $selector) {
            $element = $node->filter($selector);
            if ($element->count() > 0) {
                $text = $element->first()->text();
                if (preg_match('/(\d+)/', $text, $matches)) {
                    return (int) $matches[1];
                }
            }
        }

        return 0;
    }

    /**
     * Extract review images
     */
    protected function extractReviewImages(Crawler $node): ?array
    {
        $images = [];

        $selectors = [
            'img[data-testid="review-image"]',
            '.review-image img',
            '.review-photos img',
            '.customer-image img',
        ];

        foreach ($selectors as $selector) {
            $node->filter($selector)->each(function (Crawler $img) use (&$images) {
                $src = $img->attr('src') ?: $img->attr('data-src');
                if ($src && strpos($src, 'http') === 0) {
                    $images[] = $src;
                }
            });

            if (!empty($images)) {
                break;
            }
        }

        return !empty($images) ? $images : null;
    }

    /**
     * Save review to database
     */
    protected function saveReview(array $reviewData): void
    {
        try {
            Review::updateOrCreate(
                [
                    'platform' => $reviewData['platform'],
                    'review_id' => $reviewData['review_id'],
                ],
                $reviewData
            );

            $this->stats['reviews_saved']++;

            Log::debug("Saved Croma review", [
                'review_id' => $reviewData['review_id'],
                'product_id' => $reviewData['product_id']
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to save Croma review", [
                'review_id' => $reviewData['review_id'] ?? 'unknown',
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
