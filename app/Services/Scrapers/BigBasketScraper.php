<?php

namespace App\Services\Scrapers;

use App\Services\DataSanitizer;
use Symfony\Component\DomCrawler\Crawler;
use Illuminate\Support\Facades\Log;
use Spatie\Browsershot\Browsershot;

class BigBasketScraper extends BaseScraper
{
    protected function setupPlatformConfig(): void
    {
        $this->platform = 'bigbasket';
        $this->useJavaScript = true; // FIXED: BigBasket requires JavaScript
        $this->paginationConfig = [
            'type' => 'regular',
            'max_pages' => 50,
            'page_param' => 'page',
            'has_next_selector' => '.pagination .next:not(.disabled)',
            'max_consecutive_errors' => 50,
            'delay_between_pages' => [3, 6], // Increased delays to avoid blocking
            'retry_failed_pages' => true,
            'max_retries_per_page' => 3
        ];
    }

    public function __construct()
    {
        parent::__construct('bigbasket');
    }

    /**
     * Fetch page with JavaScript rendering and anti-bot headers
     */
    protected function fetchPageWithBrowsershot(string $url): ?string
    {
        try {
            Log::debug("Fetching BigBasket page with JavaScript", ['url' => $url]);

            $html = Browsershot::url($url)
                ->userAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36')
                ->setExtraHttpHeaders([
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9',
                    'Accept-Encoding' => 'gzip, deflate, br',
                    'Connection' => 'keep-alive',
                    'Upgrade-Insecure-Requests' => '1',
                ])
                ->waitUntilNetworkIdle()
                ->timeout(60)
                ->bodyHtml();

            if (strlen($html) < 1000) {
                Log::warning("BigBasket returned suspiciously small response", [
                    'url' => $url,
                    'length' => strlen($html)
                ]);
                return null;
            }

            // Check for access denied
            if (strpos($html, 'Access Denied') !== false || strpos($html, 'permission to access') !== false) {
                Log::error("BigBasket blocked the request", ['url' => $url]);
                return null;
            }

            Log::debug("Successfully fetched BigBasket page", [
                'url' => $url,
                'length' => strlen($html)
            ]);

            return $html;

        } catch (\Exception $e) {
            Log::error("Failed to fetch BigBasket page with Browsershot", [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Extract product URLs from BigBasket category/search page
     */
    protected function extractProductUrls(Crawler $crawler, string $categoryUrl): array
    {
        $productUrls = [];

        try {
            // BigBasket product link selectors (updated for 2024)
            $selectors = [
                'a[href*="/pd/"]',  // Primary product links
                'a[qa="product"]',  // QA attribute
                'div[data-testid="product-card"] a',  // Test ID
                'h3 a[href*="/pd/"]',  // Legacy selector
            ];

            foreach ($selectors as $selector) {
                $nodes = $crawler->filter($selector);
                
                if ($nodes->count() > 0) {
                    Log::debug("Found BigBasket product links using selector", [
                        'selector' => $selector,
                        'count' => $nodes->count()
                    ]);

                    $nodes->each(function (Crawler $node) use (&$productUrls) {
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

                    break; // Stop after finding products with first working selector
                }
            }

            // Remove duplicates and limit results
            $productUrls = array_unique($productUrls);
            $productUrls = array_slice($productUrls, 0, 50);

            Log::info("Extracted BigBasket product URLs", [
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
            $data["platform_id"] = $data["sku"];

            // Try to extract from JSON-LD first (most reliable)
            $jsonLdData = $this->extractFromJsonLd($crawler);
            if ($jsonLdData) {
                $data = array_merge($data, $jsonLdData);
            }

            // Product Attributes (with fallbacks)
            $data["title"] = $data["title"] ?? $this->extractProductName($crawler);
            $data["description"] = $data["description"] ?? $this->extractDescription($crawler);
            $data["brand"] = $data["brand"] ?? $this->extractBrand($crawler);
            $data["category"] = $data["category"] ?? $this->extractCategory($crawler);
            $data["image_urls"] = $data["image_urls"] ?? $this->extractImages($crawler);
            $data["weight"] = $this->extractItemWeight($crawler);
            $data["dimensions"] = $this->extractProductDimensions($crawler);

            // Price Attributes
            if (!isset($data["price"]) || !isset($data["sale_price"])) {
                $priceData = $this->extractPrices($crawler);
                $data["price"] = $data["price"] ?? $priceData["price"];
                $data["sale_price"] = $data["sale_price"] ?? $priceData["sale_price"];
            }
            $data["currency_code"] = $this->extractCurrencyCode($crawler);

            // Ratings Attributes
            if (!isset($data["rating"]) || !isset($data["review_count"])) {
                $ratingData = $this->extractRatingAndReviews($crawler);
                $data["rating"] = $data["rating"] ?? $ratingData["rating"];
                $data["review_count"] = $data["review_count"] ?? $ratingData["review_count"];
            }

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
     * Extract data from JSON-LD structured data
     */
    private function extractFromJsonLd(Crawler $crawler): ?array
    {
        try {
            $jsonLdNodes = $crawler->filter('script[type="application/ld+json"]');
            
            if ($jsonLdNodes->count() === 0) {
                return null;
            }

            foreach ($jsonLdNodes as $node) {
                $json = $node->textContent;
                $data = json_decode($json, true);

                if ($data && isset($data['@type']) && $data['@type'] === 'Product') {
                    $extracted = [];

                    // Extract title
                    if (isset($data['name'])) {
                        $extracted['title'] = $data['name'];
                    }

                    // Extract description
                    if (isset($data['description'])) {
                        $extracted['description'] = $data['description'];
                    }

                    // Extract brand
                    if (isset($data['brand']['name'])) {
                        $extracted['brand'] = $data['brand']['name'];
                    } elseif (isset($data['brand']) && is_string($data['brand'])) {
                        $extracted['brand'] = $data['brand'];
                    }

                    // Extract images
                    if (isset($data['image'])) {
                        $extracted['image_urls'] = is_array($data['image']) ? $data['image'] : [$data['image']];
                    }

                    // Extract prices
                    if (isset($data['offers'])) {
                        $offers = $data['offers'];
                        if (isset($offers['price'])) {
                            $extracted['sale_price'] = (float) $offers['price'];
                        }
                        if (isset($offers['highPrice'])) {
                            $extracted['price'] = (float) $offers['highPrice'];
                        }
                    }

                    // Extract rating
                    if (isset($data['aggregateRating'])) {
                        $rating = $data['aggregateRating'];
                        if (isset($rating['ratingValue'])) {
                            $extracted['rating'] = (float) $rating['ratingValue'];
                        }
                        if (isset($rating['reviewCount'])) {
                            $extracted['review_count'] = (int) $rating['reviewCount'];
                        }
                    }

                    Log::debug("Extracted data from JSON-LD", ['fields' => array_keys($extracted)]);
                    return $extracted;
                }
            }

        } catch (\Exception $e) {
            Log::warning("Failed to extract from JSON-LD", ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Extract SKU from BigBasket URL
     */
    private function extractSkuFromUrl(string $url): ?string
    {
        // BigBasket SKU pattern: /pd/{product_id}/{product-slug}/
        if (preg_match('/\/pd\/(\d+)/', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Extract product name (updated selectors for 2024)
     */
    private function extractProductName(Crawler $crawler): ?string
    {
        $selectors = [
            'h1[data-testid="product-title"]',  // Test ID
            'h1.prod-name',  // Legacy
            'h1.product-title',
            '.prod-details h1',
            'div[qa="product-title"] h1',
            'h1',  // Fallback to any h1
        ];

        foreach ($selectors as $selector) {
            $element = $crawler->filter($selector);
            if ($element->count() > 0) {
                $text = $this->cleanText($element->first()->text());
                $text = preg_replace('/\s+/', ' ', $text);
                if ($text && strlen($text) > 3) {
                    return trim($text);
                }
            }
        }

        return null;
    }

    /**
     * Extract product description (updated for React components)
     */
    private function extractDescription(Crawler $crawler): ?string
    {
        $descriptions = [];

        // Try multiple description selectors
        $selectors = [
            'div[data-testid="product-description"]',
            '.description .desc-text',
            '.prod-details p',
            'div[qa="product-description"]',
            '.product-description',
        ];

        foreach ($selectors as $selector) {
            $crawler->filter($selector)->each(function (Crawler $node) use (&$descriptions) {
                $text = $this->cleanText($node->text());
                if ($text && strlen($text) > 10) {
                    $descriptions[] = $text;
                }
            });

            if (!empty($descriptions)) {
                break;
            }
        }

        return !empty($descriptions) ? implode('. ', $descriptions) : null;
    }

    /**
     * Extract prices (updated selectors)
     */
    private function extractPrices(Crawler $crawler): array
    {
        $prices = ["price" => null, "sale_price" => null];

        // Sale price selectors (updated for 2024)
        $salePriceSelectors = [
            'span[data-testid="selling-price"]',
            '.price .discnt-price',
            '.prod-price .price-amt',
            '.final-price',
            'span[qa="selling-price"]',
            'div.SellingPrice___StyledDiv-sc-1e0twzt-0',  // Styled component
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

        // Original price (MRP) selectors
        $mrpPriceSelectors = [
            'span[data-testid="mrp-price"]',
            '.price .mrp-price',
            '.original-price',
            '.strikethrough-price',
            'span[qa="mrp-price"]',
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

        $selectors = [
            'div[data-testid="offer-text"]',
            '.offer-text',
            '.discount-label',
            'span[qa="offer"]',
        ];

        foreach ($selectors as $selector) {
            $discount = $crawler->filter($selector)->first();
            if ($discount->count() > 0) {
                $offers[] = $this->cleanText($discount->text());
            }
        }

        return !empty($offers) ? implode('; ', $offers) : null;
    }

    /**
     * Extract availability status
     */
    private function extractAvailability(Crawler $crawler): ?string
    {
        $availabilitySelectors = [
            'div[data-testid="stock-status"]',
            '.stock-status',
            '.availability-text',
            '.out-of-stock',
            'button[qa="add-to-cart"]',  // If button exists, it's in stock
        ];

        foreach ($availabilitySelectors as $selector) {
            $element = $crawler->filter($selector)->first();
            if ($element->count() > 0) {
                $text = $this->cleanText($element->text());
                if ($text) {
                    // Check if it's the add button
                    if (stripos($text, 'add') !== false) {
                        return 'In Stock';
                    }
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

        // Rating selectors (updated)
        $ratingSelectors = [
            'span[data-testid="rating"]',
            '.rating .avg-rating',
            '.product-rating .stars',
            '.rating-stars',
            'div[qa="rating"]',
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

        // Review count selectors
        $reviewSelectors = [
            'span[data-testid="review-count"]',
            '.review-count',
            '.rating .reviews',
            '.total-reviews',
            'div[qa="review-count"]',
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
            'span[data-testid="brand"]',
            '.brand-name',
            '.prod-details .brand',
            '.product-brand',
            'div[qa="brand"]',
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
            'nav[data-testid="breadcrumb"] a',
            '.breadcrumb a',
            '.category-path a',
            '.prod-details .category',
        ];

        $categories = [];
        foreach ($selectors as $selector) {
            $crawler->filter($selector)->each(function (Crawler $node) use (&$categories) {
                $text = $this->cleanText($node->text());
                if ($text && !in_array($text, $categories) && $text !== 'Home') {
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

        // Image selectors (updated)
        $selectors = [
            'img[data-testid="product-image"]',
            '.product-img img',
            '.main-image img',
            'div[qa="product-image"] img',
        ];

        foreach ($selectors as $selector) {
            $crawler->filter($selector)->each(function (Crawler $node) use (&$images) {
                $src = $node->attr('src') ?: $node->attr('data-src') ?: $node->attr('srcset');
                if ($src) {
                    // Handle srcset
                    if (strpos($src, ',') !== false) {
                        $srcParts = explode(',', $src);
                        $src = trim(explode(' ', trim($srcParts[0]))[0]);
                    }
                    if (strpos($src, 'http') === 0) {
                        $images[] = $src;
                    }
                }
            });
        }

        return !empty($images) ? array_values(array_unique($images)) : null;
    }

    /**
     * Extract item weight
     */
    private function extractItemWeight(Crawler $crawler): ?string
    {
        $selectors = [
            'span[data-testid="weight"]',
            '.prod-details .weight',
            '.product-specs .weight',
            '.spec-table tr:contains("Weight") td:nth-child(2)',
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
            'span[data-testid="dimensions"]',
            '.prod-details .dimensions',
            '.product-specs .dimensions',
            '.spec-table tr:contains("Dimensions") td:nth-child(2)',
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

        $crawler->filter('.variant-selector .variant-option, div[data-testid="variant"] button')->each(function (Crawler $node) use (&$attributes) {
            $name = $node->filter('.variant-name')->count() > 0 ? $this->cleanText($node->filter('.variant-name')->text()) : $this->cleanText($node->text());
            $image = $node->filter('img')->count() > 0 ? ($node->filter('img')->attr('src') ?: $node->filter('img')->attr('data-src')) : null;
            $sku = $node->attr('data-sku') ?: $node->attr('data-product-id') ?: null;

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
