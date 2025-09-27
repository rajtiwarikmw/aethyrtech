<?php

namespace App\Services\Scrapers;

use App\Services\DataSanitizer;
use Symfony\Component\DomCrawler\Crawler;
use Illuminate\Support\Facades\Log;

class BigBasketScraper extends BaseScraper
{
    protected function setupPlatformConfig(): void
    {
        $this->platform = 'bigbasket';
        $this->useJavaScript = false; // BigBasket may require JavaScript for dynamic content
        $this->paginationConfig = [
            'type' => 'regular',
            'max_pages' => 50, // Adjusted for BigBasket's pagination
            'page_param' => 'page',
            'has_next_selector' => '.pagination .next:not(.disabled)',
            'max_consecutive_errors' => 50,
            'delay_between_pages' => [2, 5], // Moderate delays to avoid rate limiting
            'retry_failed_pages' => true,
            'max_retries_per_page' => 3
        ];
    }

    public function __construct()
    {
        parent::__construct('bigbasket');
    }

    /**
     * Extract product URLs from BigBasket category/search page
     */
    protected function extractProductUrls(Crawler $crawler, string $categoryUrl): array
    {
        $productUrls = [];

        try {
            // BigBasket product link selectors
            $selectors = [
                'h3 a[href*="/pd/',
            ];

            foreach ($selectors as $selector) {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$productUrls) {
                    $href = $node->attr('href');
                    if ($href) {
                        // Convert relative URLs to absolute
                        if (strpos($href, 'http') !== 0) {
                            $href = 'https://www.bigbasket.com' . $href;
                        }

                        // Include only product pages (e.g., URLs containing '/pd/')
                        if (strpos($href, '/pd/') !== false) {
                            $productUrls[] = $href;
                        }
                    }
                });
            }

            // Remove duplicates and limit results
            $productUrls = array_unique($productUrls);
            $productUrls = array_slice($productUrls, 0, 50); // Limit to 50 products per page

            Log::info("Extracted {count} product URLs from BigBasket category page", [
                'count' => count($productUrls),
                'category_url' => $categoryUrl
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to extract product URLs from BigBasket", [
                'error' => $e->getMessage(),
                'category_url' => $categoryUrl
            ]);
        }

        return $productUrls;
    }

    /**
     * Extract product data from BigBasket product page
     */
    protected function extractProductData(Crawler $crawler, string $productUrl): array
    {
        try {
            $data = [];

            // Extract SKU from URL
            $data["sku"] = $this->extractSkuFromUrl($productUrl);
            if (!$data["sku"]) {
                Log::warning("Could not extract SKU from BigBasket URL: {$productUrl}");
                return [];
            }

            $data["product_url"] = $productUrl;
            $data["platform_id"] = $data["sku"]; // BigBasket uses SKU as unique identifier

            // Product Attributes
            $data["title"] = $this->extractProductName($crawler);
            $data["description"] = $this->extractDescription($crawler);
            $data["brand"] = $this->extractBrand($crawler);
            $data["category"] = $this->extractCategory($crawler);
            $data["image_urls"] = $this->extractImages($crawler);
            $data["weight"] = $this->extractItemWeight($crawler);
            $data["dimensions"] = $this->extractProductDimensions($crawler);

            // Price Attributes
            $priceData = $this->extractPrices($crawler);
            $data["price"] = $priceData["price"];
            $data["sale_price"] = $priceData["sale_price"];
            $data["currency_code"] = $this->extractCurrencyCode($crawler);

            // Ratings Attributes
            $ratingData = $this->extractRatingAndReviews($crawler);
            $data["rating"] = $ratingData["rating"];
            $data["review_count"] = $ratingData["review_count"];

            // Additional Attributes
            $data["offers"] = $this->extractOffers($crawler);
            $data["inventory_status"] = $this->extractAvailability($crawler);
            $data["variation_attributes"] = $this->extractVariationAttributes($crawler);
            $data["technical_details"] = $this->extractTechnicalDetails($crawler);

            // Sanitize all data
            $data = DataSanitizer::sanitizeProductData($data);

            Log::debug("Extracted BigBasket product data", [
                "sku" => $data["sku"],
                "title" => $data["title"] ?? "N/A"
            ]);

            return $data;
        } catch (\Exception $e) {
            Log::error("Failed to extract BigBasket product data", [
                "url" => $productUrl,
                "error" => $e->getMessage(),
                "trace" => $e->getTraceAsString()
            ]);
            return [];
        }
    }

    /**
     * Extract SKU from BigBasket URL
     */
    private function extractSkuFromUrl(string $url): ?string
    {
        // BigBasket SKU pattern (e.g., /pd/40012345/)
        if (preg_match('/\/pd\/(\d+)/', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Extract product name
     */
    private function extractProductName(Crawler $crawler): ?string
    {
        $selectors = [
            'h1.prod-name', // Primary product title
            '.product-title h1',
            '.prod-details h1'
        ];

        foreach ($selectors as $selector) {
            $element = $crawler->filter($selector);
            if ($element->count() > 0) {
                $text = $this->cleanText($element->text());
                $text = preg_replace('/\s+/', ' ', $text);
                return trim($text);
            }
        }

        return null;
    }

    /**
     * Extract product description
     */
    private function extractDescription(Crawler $crawler): ?string
    {
        $descriptions = [];

        // Description sections
        $crawler->filter('.description .desc-text')->each(function (Crawler $node) use (&$descriptions) {
            $text = $this->cleanText($node->text());
            if ($text && strlen($text) > 10) {
                $descriptions[] = $text;
            }
        });

        // Fallback to product details
        $productDesc = $crawler->filter('.prod-details p')->first();
        if ($productDesc->count() > 0) {
            $descriptions[] = $this->cleanText($productDesc->text());
        }

        return !empty($descriptions) ? implode('. ', $descriptions) : null;
    }

    /**
     * Extract prices
     */
    private function extractPrices(Crawler $crawler): array
    {
        $prices = ["price" => null, "sale_price" => null];

        // Sale price
        $salePriceSelectors = [
            '.price .discnt-price', // Discounted price
            '.prod-price .price-amt',
            '.final-price'
        ];

        foreach ($salePriceSelectors as $selector) {
            $element = $crawler->filter($selector)->first();
            if ($element->count() > 0) {
                $priceText = $this->cleanText($element->text());
                $price = $this->extractPrice($priceText);
                if ($price) {
                    $prices["sale_price"] = $price;
                    break;
                }
            }
        }

        // Original price (MRP)
        $mrpPriceSelectors = [
            '.price .mrp-price', // MRP price
            '.original-price',
            '.strikethrough-price'
        ];

        foreach ($mrpPriceSelectors as $selector) {
            $element = $crawler->filter($selector)->first();
            if ($element->count() > 0) {
                $priceText = $this->cleanText($element->text());
                $price = $this->extractPrice($priceText);
                if ($price) {
                    $prices["price"] = $price;
                    break;
                }
            }
        }

        // If no original price found, use sale price as regular price
        if (!$prices["price"] && $prices["sale_price"]) {
            $prices["price"] = $prices["sale_price"];
        }

        return $prices;
    }

    /**
     * Extract currency code
     */
    private function extractCurrencyCode(Crawler $crawler): ?string
    {
        $priceSymbolNode = $crawler->filter('.price .currency, .prod-price .currency')->first();

        if ($priceSymbolNode->count() > 0) {
            $symbol = trim($priceSymbolNode->text());
            $currencyMap = [
                '₹' => 'INR',
                '$' => 'USD',
                '£' => 'GBP',
                '€' => 'EUR',
                '¥' => 'JPY',
                '₩' => 'KRW',
            ];
            return $currencyMap[$symbol] ?? $symbol;
        }

        return 'INR'; // Default to INR for BigBasket
    }

    /**
     * Extract offers
     */
    private function extractOffers(Crawler $crawler): ?string
    {
        $offers = [];

        $discount = $crawler->filter('.offer-text, .discount-label')->first();
        if ($discount->count() > 0) {
            $offers[] = $this->cleanText($discount->text());
        }

        return !empty($offers) ? implode('; ', $offers) : null;
    }

    /**
     * Extract availability status
     */
    private function extractAvailability(Crawler $crawler): ?string
    {
        $availabilitySelectors = [
            '.stock-status',
            '.availability-text',
            '.out-of-stock'
        ];

        foreach ($availabilitySelectors as $selector) {
            $element = $crawler->filter($selector)->first();
            if ($element->count() > 0) {
                $text = $this->cleanText($element->text());
                if ($text) {
                    return $text;
                }
            }
        }

        return 'Unknown';
    }

    /**
     * Extract rating and review count
     */
    private function extractRatingAndReviews(Crawler $crawler): array
    {
        $data = ['rating' => null, 'review_count' => 0];

        // Rating
        $ratingSelectors = [
            '.rating .avg-rating',
            '.product-rating .stars',
            '.rating-stars'
        ];

        foreach ($ratingSelectors as $selector) {
            $element = $crawler->filter($selector)->first();
            if ($element->count() > 0) {
                $rating = $this->extractRating($element->text());
                if ($rating) {
                    $data['rating'] = $rating;
                    break;
                }
            }
        }

        // Review count
        $reviewSelectors = [
            '.review-count',
            '.rating .reviews',
            '.total-reviews'
        ];

        foreach ($reviewSelectors as $selector) {
            $element = $crawler->filter($selector)->first();
            if ($element->count() > 0) {
                $reviewCount = $this->extractReviewCount($element->text());
                if ($reviewCount > 0) {
                    $data['review_count'] = $reviewCount;
                    break;
                }
            }
        }

        return $data;
    }

    /**
     * Extract brand
     */
    private function extractBrand(Crawler $crawler): ?string
    {
        $selectors = [
            '.brand-name',
            '.prod-details .brand',
            '.product-brand'
        ];

        foreach ($selectors as $selector) {
            $element = $crawler->filter($selector)->first();
            if ($element->count() > 0) {
                return $this->cleanText($element->text());
            }
        }

        return null;
    }

    /**
     * Extract category
     */
    private function extractCategory(Crawler $crawler): ?string
    {
        $selectors = [
            '.breadcrumb a',
            '.category-path a',
            '.prod-details .category'
        ];

        $categories = [];
        foreach ($selectors as $selector) {
            $crawler->filter($selector)->each(function (Crawler $node) use (&$categories) {
                $text = $this->cleanText($node->text());
                if ($text && !in_array($text, $categories)) {
                    $categories[] = $text;
                }
            });
            if (!empty($categories)) {
                break;
            }
        }

        return !empty($categories) ? implode(' > ', $categories) : null;
    }

    /**
     * Extract product images
     */
    private function extractImages(Crawler $crawler): ?array
    {
        $images = [];

        // Main product image
        $crawler->filter('.product-img img, .main-image img')->each(function (Crawler $node) use (&$images) {
            $src = $node->attr('src') ?: $node->attr('data-src');
            if ($src && strpos($src, 'http') === 0) {
                $images[] = $src;
            }
        });

        // Gallery images
        $crawler->filter('.gallery-thumbs img, .additional-images img')->each(function (Crawler $node) use (&$images) {
            $src = $node->attr('src') ?: $node->attr('data-src');
            if ($src && strpos($src, 'http') === 0) {
                $images[] = $src;
            }
        });

        return !empty($images) ? array_values(array_unique($images)) : null;
    }

    /**
     * Extract item weight
     */
    private function extractItemWeight(Crawler $crawler): ?string
    {
        $selectors = [
            '.prod-details .weight',
            '.product-specs .weight',
            '.spec-table tr:contains("Weight") td:nth-child(2)'
        ];

        foreach ($selectors as $selector) {
            $element = $crawler->filter($selector)->first();
            if ($element->count() > 0) {
                return $this->cleanText($element->text());
            }
        }

        return null;
    }

    /**
     * Extract product dimensions
     */
    private function extractProductDimensions(Crawler $crawler): ?string
    {
        $selectors = [
            '.prod-details .dimensions',
            '.product-specs .dimensions',
            '.spec-table tr:contains("Dimensions") td:nth-child(2)'
        ];

        foreach ($selectors as $selector) {
            $element = $crawler->filter($selector)->first();
            if ($element->count() > 0) {
                return $this->cleanText($element->text());
            }
        }

        return null;
    }

    /**
     * Extract technical details
     */
    private function extractTechnicalDetails(Crawler $crawler): ?array
    {
        $details = [];

        $crawler->filter('.spec-table tr, .product-details-table tr')->each(function (Crawler $node) use (&$details) {
            $key = trim(preg_replace('/\s+/', ' ', $node->filter('th')->text('')));
            $value = trim(preg_replace('/\s+/', ' ', $node->filter('td')->text('')));
            if ($key && $value) {
                $details[$key] = $value;
            }
        });

        return !empty($details) ? $details : null;
    }

    /**
     * Extract variation attributes
     */
    private function extractVariationAttributes(Crawler $crawler): ?array
    {
        $attributes = [];

        $crawler->filter('.variant-selector .variant-option')->each(function (Crawler $node) use (&$attributes) {
            $name = $node->filter('.variant-name')->count() > 0 ? $this->cleanText($node->filter('.variant-name')->text()) : null;
            $image = $node->filter('img')->count() > 0 ? ($node->filter('img')->attr('src') ?: $node->filter('img')->attr('data-src')) : null;
            $sku = $node->attr('data-sku') ?: null;

            if ($name) {
                $attributes[] = [
                    'name' => $name,
                    'image' => $image,
                    'sku' => $sku
                ];
            }
        });

        return !empty($attributes) ? $attributes : null;
    }
}