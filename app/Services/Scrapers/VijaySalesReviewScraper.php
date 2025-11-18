<?php

namespace App\Services\Scrapers;

use App\Models\Product;
use App\Models\Review;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class VijaySalesReviewScraper
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

    public function scrapeReviews(?array $productIds = null, ?int $limit = null): array
    {
        $query = Product::where('platform', 'vijaysales')
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
                Log::error("Failed to scrape VijaySales reviews", [
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
        try {
            $html = $this->fetchPage($product->product_url);

            if (!$html) {
                return;
            }

            $crawler = new Crawler($html);
            $reviews = $this->extractReviews($crawler, $product->id);

            foreach ($reviews as $reviewData) {
                $this->saveReview($reviewData);
            }
        } catch (\Exception $e) {
            Log::error("Error scraping VijaySales reviews", [
                'product_id' => $product->id,
                'error' => $e->getMessage()
            ]);
        }
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

    protected function extractReviews(Crawler $crawler, int $productId): array
    {
        $reviews = [];

        try {
            // VijaySales review selectors (adjust based on actual HTML structure)
            $crawler->filter('.review-item, .customer-review')->each(function (Crawler $reviewNode) use (&$reviews, $productId) {
                try {
                    $reviewData = [
                        'product_id' => $productId,
                        'platform' => 'vijaysales',
                        'review_id' => 'VS_' . substr(md5($reviewNode->text()), 0, 16),
                        'reviewer_name' => $this->extractReviewerName($reviewNode),
                        'rating' => $this->extractRating($reviewNode),
                        'review_title' => $this->extractReviewTitle($reviewNode),
                        'review_text' => $this->extractReviewText($reviewNode),
                        'review_date' => $this->extractReviewDate($reviewNode),
                        'verified_purchase' => false,
                        'helpful_count' => 0,
                    ];

                    if ($reviewData['review_text']) {
                        $reviews[] = $reviewData;
                        $this->stats['reviews_found']++;
                    }
                } catch (\Exception $e) {
                    // Skip this review
                }
            });
        } catch (\Exception $e) {
            Log::error("Failed to extract VijaySales reviews", ['error' => $e->getMessage()]);
        }

        return $reviews;
    }

    protected function extractReviewerName(Crawler $reviewNode): ?string
    {
        try {
            $element = $reviewNode->filter('.reviewer-name, .customer-name');
            if ($element->count() > 0) {
                return trim($element->first()->text());
            }
        } catch (\Exception $e) {
            // Ignore
        }
        return null;
    }

    protected function extractRating(Crawler $reviewNode): ?float
    {
        try {
            $element = $reviewNode->filter('.rating, .star-rating');
            if ($element->count() > 0) {
                $ratingText = $element->first()->text();
                if (preg_match('/(\d+\.?\d*)/', $ratingText, $matches)) {
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
            $element = $reviewNode->filter('.review-title, h3');
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
            $element = $reviewNode->filter('.review-text, .review-content, p');
            if ($element->count() > 0) {
                return trim($element->first()->text());
            }
        } catch (\Exception $e) {
            // Ignore
        }
        return null;
    }

    protected function extractReviewDate(Crawler $reviewNode): ?string
    {
        try {
            $element = $reviewNode->filter('.review-date, .date');
            if ($element->count() > 0) {
                $dateText = $element->first()->text();
                // Try to parse date
                $date = \DateTime::createFromFormat('d/m/Y', trim($dateText));
                if ($date) {
                    return $date->format('Y-m-d');
                }
            }
        } catch (\Exception $e) {
            // Ignore
        }
        return null;
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
            Log::error("Failed to save VijaySales review", ['error' => $e->getMessage()]);
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
