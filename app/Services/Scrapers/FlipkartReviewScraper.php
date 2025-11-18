<?php

namespace App\Services\Scrapers;

use App\Models\Product;
use App\Models\Review;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class FlipkartReviewScraper
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
        // Flipkart reviews are often loaded via AJAX or on the same page
        $reviewsUrl = $this->getReviewsUrl($product->product_url);

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

    protected function getReviewsUrl(string $productUrl): ?string
    {
        // Flipkart reviews are usually on the same page or with #reviews anchor
        return $productUrl;
    }

    protected function fetchPage(string $url): ?string
    {
        try {
            $response = $this->httpClient->get($url);
            if ($response->getStatusCode() === 200) {
                return $response->getBody()->getContents();
            }
            return null;
        } catch (RequestException $e) {
            Log::error("HTTP request failed", ['url' => $url, 'error' => $e->getMessage()]);
            return null;
        }
    }

    protected function extractReviews(Crawler $crawler, int $productId): array
    {
        $reviews = [];

        try {
            // Flipkart review selectors (these may need adjustment based on current HTML structure)
            $crawler->filter('div._1AtVbE, div.col-12-12')->each(function (Crawler $reviewNode) use (&$reviews, $productId) {
                try {
                    $reviewData = [
                        'product_id' => $productId,
                        'platform' => 'flipkart',
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
        // Generate a unique ID from review content hash
        try {
            $text = $reviewNode->text();
            return 'FK_' . substr(md5($text), 0, 16);
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function extractReviewerName(Crawler $reviewNode): ?string
    {
        try {
            $selectors = ['p._2sc7ZR._2V5EHH', 'p._2NsDsF'];
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

    protected function extractRating(Crawler $reviewNode): ?float
    {
        try {
            $element = $reviewNode->filter('div._3LWZlK, div.hGSR34');
            if ($element->count() > 0) {
                $ratingText = $element->first()->text();
                if (preg_match('/(\d+)/', $ratingText, $matches)) {
                    return (float) $matches[1];
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
            $element = $reviewNode->filter('p._2-N8zT');
            if ($element->count() > 0) {
                return trim($element->first()->text());
            }
        } catch (\Exception $e) {
            // Ignore
        }
        return null;
    }

    protected function extractReviewText(Crawler $reviewNode): ?string
    {
        try {
            $selectors = ['div.t-ZTKy', 'div._1AtVbE div div'];
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

    protected function extractReviewDate(Crawler $reviewNode): ?string
    {
        try {
            $element = $reviewNode->filter('p._2sc7ZR');
            if ($element->count() > 0) {
                $dateText = $element->first()->text();
                // Parse Flipkart date format
                if (preg_match('/(\d+)\s+(\w+),?\s+(\d{4})/', $dateText, $matches)) {
                    $date = \DateTime::createFromFormat('d F Y', "{$matches[1]} {$matches[2]} {$matches[3]}");
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
            $element = $reviewNode->filter('p._2mcZGG');
            return $element->count() > 0 && strpos($element->text(), 'Certified Buyer') !== false;
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function extractHelpfulCount(Crawler $reviewNode): int
    {
        try {
            $element = $reviewNode->filter('span._3c3Px5');
            if ($element->count() > 0) {
                $text = $element->first()->text();
                if (preg_match('/(\d+)/', $text, $matches)) {
                    return (int) $matches[1];
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

    protected function randomDelay(int $min = 2, int $max = 5): void
    {
        usleep(rand($min * 1000000, $max * 1000000));
    }

    public function getStats(): array
    {
        return $this->stats;
    }
}
