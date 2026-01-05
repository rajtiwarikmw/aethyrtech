<?php

namespace App\Services\Scrapers;

use App\Models\Product;
use App\Models\Review;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;
use Spatie\Browsershot\Browsershot;

class RelianceDigitalReviewScraper
{
    protected $stats = [
        'products_processed' => 0,
        'reviews_found' => 0,
        'reviews_saved' => 0,
        'errors' => 0,
    ];

    /**
     * Scrape reviews for all Reliance Digital products
     */
    public function scrapeAllReviews(?int $limit = null): array
    {
        Log::info("Starting Reliance Digital review scraping", ['limit' => $limit]);

        $query = Product::where('platform', 'reliancedigital')
            ->whereNotNull('product_url')
            ->whereNotNull('sku');

        if ($limit) {
            $query->limit($limit);
        }

        $products = $query->get();

        Log::info("Found Reliance Digital products to scrape reviews", ['count' => $products->count()]);

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
            Log::info("Scraping Reliance Digital reviews for product", [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'title' => $product->title
            ]);

            $this->stats['products_processed']++;

            // Reviews are typically on the product page itself
            $reviewsUrl = $product->product_url;
            
            if (!$reviewsUrl) {
                Log::warning("No product URL for Reliance Digital product", [
                    'product_id' => $product->id
                ]);
                return;
            }

            // Fetch page with JavaScript rendering
            $html = $this->fetchPageWithBrowsershot($reviewsUrl);

            if (!$html) {
                Log::warning("Failed to fetch Reliance Digital reviews page", [
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
                Log::info("No Reliance Digital reviews found for product", [
                    'product_id' => $product->id,
                    'sku' => $product->sku
                ]);
                return;
            }

            // Save reviews
            foreach ($reviews as $reviewData) {
                $this->saveReview($reviewData);
            }

            Log::info("Scraped Reliance Digital reviews for product", [
                'product_id' => $product->id,
                'reviews_count' => count($reviews)
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to scrape Reliance Digital reviews for product", [
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
            Log::debug("Fetching Reliance Digital reviews page with JavaScript", ['url' => $url]);
            $isProductPage = strpos($url, '/product/') !== false;
            
            $timeout = $isProductPage ? 45 : 30;
            
            $browsershot = Browsershot::url($url)
                ->userAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36')
                ->setExtraHttpHeaders([
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9',
                    'Accept-Encoding' => 'gzip, deflate, br',
                    'Connection' => 'keep-alive',
                    'Upgrade-Insecure-Requests' => '1',
                    'Sec-Fetch-Dest' => 'document',
                    'Sec-Fetch-Mode' => 'navigate',
                    'Sec-Fetch-Site' => 'none',
                ])
                ->timeout($timeout);
            
            // $browsershot->waitForFunction(
            //     '() => document.readyState === "complete"',
            //     ['polling' => 500, 'timeout' => $timeout * 1000]
            // );
            
            $html = $browsershot->bodyHtml();

            $contentLength = strlen($html);

            Log::debug("Reliance Digital page response", [
                'status_code' => 200,
                'content_length' => $contentLength,
                'is_product_page' => $isProductPage,
                'timeout_used' => $timeout
            ]);

            if ($contentLength < 1000) {
                Log::warning("Reliance Digital returned suspiciously small response", [
                    'content_length' => $contentLength,
                    'url' => $url
                ]);
                return null;
            }

            // Check for error pages
            if (strpos($html, 'page was not found') !== false ||
                strpos($html, 'Oops!') !== false) {
                Log::error("Reliance Digital returned error page", ['url' => $url]);
                
                // Save HTML for debugging
                //$debugFile = storage_path('logs/reliancedigital_reviews_debug_' . time() . '.html');
                // file_put_contents($debugFile, $html);
                // Log::debug("Saved HTML for debugging", ['file' => $debugFile]);
                
                return null;
            }

            return $html;

        } catch (\Exception $e) {
            Log::error("Failed to fetch Reliance Digital reviews page", [
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
            // Reliance Digital review container selectors
            $containerSelectors = [
                'div.rd-feedback-service-review-container', // ✅ exact
                'div[class*="feedback-service-review"]',
            ];

            $reviewNodes = null;
            $usedSelector = null;

            // Try each selector
            foreach ($containerSelectors as $selector) {
                $nodes = $crawler->filter($selector);
                if ($nodes->count() > 0) {
                    $reviewNodes = $nodes;
                    $usedSelector = $selector;
                    Log::debug("Found Reliance Digital reviews using selector", [
                        'selector' => $selector,
                        'count' => $nodes->count()
                    ]);
                    break;
                }
            }

            if (!$reviewNodes || $reviewNodes->count() === 0) {
                Log::warning("No Reliance Digital review containers found", [
                    'tried_selectors' => $containerSelectors
                ]);
                
                // Save HTML for debugging
                $debugFile = storage_path('logs/reliancedigital_reviews_debug_no_reviews_' . time() . '.html');
                file_put_contents($debugFile, $crawler->html());
                Log::debug("Saved HTML for debugging", ['file' => $debugFile]);
                
                return [];
            }

            // Extract each review
            $reviewNodes->each(function (Crawler $node) use (&$reviews, $productId, $sku) {
                try {
                    $reviewerName = $this->extractReviewerName($node);
                    $reviewDate   = $this->extractReviewDate($node);

                    $reviewData = [
                        'product_id' => $productId,
                        'sku' => $sku,
                        'platform' => 'reliancedigital',
                        'reviewer_name' => $reviewerName,
                        'rating' => $this->extractRating($node),
                        'review_title' => $this->extractReviewTitle($node),
                        'review_text' => $this->extractReviewText($node),
                        'review_date' => $reviewDate,
                        'verified_purchase' => $this->extractVerifiedPurchase($node),
                        'helpful_count' => $this->extractHelpfulCount($node),
                        'images' => $this->extractReviewImages($node),
                    ];

                    // 🔥 review_id AFTER all data exists
                    $reviewData['review_id'] = $this->buildReviewId(
                        $reviewerName,
                        $reviewDate
                    );

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
                    Log::warning("Failed to extract Reliance Digital review", [
                        'error' => $e->getMessage()
                    ]);
                }
            });

        } catch (\Exception $e) {
            Log::error("Failed to extract Reliance Digital reviews", [
                'error' => $e->getMessage()
            ]);
        }

        return $reviews;
    }
    
    protected function buildReviewId(string $reviewer, ?string $date): string
    {
        // Clean reviewer name
        $reviewer = strtolower(trim($reviewer ?: 'anonymous'));
        $reviewer = preg_replace('/[^a-z0-9]+/i', '-', $reviewer);

        // Date already normalized as Y-m-d
        if ($date) {
            $dateForId = str_replace('-', '', $date); // 2025-05-20 → 20250520
        } else {
            $dateForId = now()->format('Ymd');
        }

        // FINAL ID: FD_reviewer_date
        return 'FD_' . $reviewer . '_' . $dateForId;
    }


    /**
     * Extract review ID
     */
    protected function extractReviewId(Crawler $node): ?string
    {
        $id = $node->attr('data-review-id') ?: $node->attr('data-id') ?: $node->attr('id');
        
        if ($id) {
            return 'RD_' . $id;
        }

        // Generate from content hash
        $text = $node->text();
        return 'RD_' . substr(md5($text), 0, 12);
    }

    /**
     * Extract reviewer name
     */
    protected function extractReviewerName(Crawler $node): ?string
    {
        $selectors = [
            '.rd-feedback-service-jds-desk-body-s',
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
        try {
            // Count only yellow stars (filled)
            $filledStars = $node->filter('svg path[fill="#FFD947"]')->count();

            return $filledStars > 0 ? (float) $filledStars : null;

        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Extract review title
     */
    protected function extractReviewTitle(Crawler $node): ?string
    {
        $selectors = [
            '.rd-feedback-service-review-row-title',
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
            '.rd-feedback-service-review-row-description',
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
        if ($node->filter('.rd-feedback-service-review-row-top-right')->count() === 0) {
            return null;
        }

        $rawDate = trim(
            $node->filter('.rd-feedback-service-review-row-top-right')->first()->text()
        );

        // Reliance Digital format: d/m/Y or dd/m/Y
        $date = \DateTime::createFromFormat('d/m/Y', $rawDate);

        if ($date instanceof \DateTime) {
            return $date->format('Y-m-d'); // ✅ normalized
        }

        // ❌ agar parse fail ho, raw return mat karo
        Log::warning('Invalid review date format', ['raw' => $rawDate]);

        return null;
    }


    /**
     * Extract verified purchase status
     */
    protected function extractVerifiedPurchase(Crawler $node): bool
    {
        $selectors = [
            '.rd-feedback-service-certified-buyer-container',
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
            '.rd-feedback-service-vote-text',
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

            Log::debug("Saved Reliance Digital review", [
                'review_id' => $reviewData['review_id'],
                'product_id' => $reviewData['product_id']
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to save Reliance Digital review", [
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
