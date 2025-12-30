<?php

namespace App\Services\Scrapers;

use App\Services\DataSanitizer;
use Symfony\Component\DomCrawler\Crawler;
use Illuminate\Support\Facades\Log;

/**
 * Meesho Scraper
 * 
 * Meesho is a JavaScript-heavy platform, so this scraper uses browser automation
 * Similar to Flipkart, it requires Browsershot/Puppeteer for reliable scraping
 * 
 * URL Pattern:
 * - Category: https://www.meesho.com/{category-name}/pl/{category-id}
 * - Product: https://www.meesho.com/p/{product-id}
 * - Search: https://www.meesho.com/search?q={query}
 */
class MeeshoScraper extends BaseScraper
{
    protected function setupPlatformConfig(): void
    {
        $this->platform = 'meesho';
        $this->useJavaScript = true; // Meesho requires JavaScript rendering
        $this->paginationConfig = [
            'type' => 'infinite_scroll', // Meesho uses infinite scroll
            'max_pages' => 50,
            'page_param' => 'page',
            'delay_between_pages' => [2, 5],
            'max_consecutive_errors' => 3,
            'retry_failed_pages' => true,
            'max_retries_per_page' => 2
        ];
    }

    public function __construct()
    {
        parent::__construct('meesho');
    }

    /**
     * Extract product URLs from Meesho category/search page
     * 
     * Meesho uses infinite scroll with dynamic loading
     */
    protected function extractProductUrls(Crawler $crawler, string $categoryUrl): array
    {
        $productUrls = [];

        try {
            // Meesho product link patterns
            $selectors = [
                'a[href*="/p/"]',           // Main product link pattern
                'a.productCardImg',         // Product card image link
                'a.productCardTitle',       // Product card title link
                'div[data-testid="productCard"] a',  // Product card container link
                'a[class*="product"]',      // Any link with "product" in class
            ];

            foreach ($selectors as $selector) {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$productUrls) {
                    $href = $node->attr('href');
                    if ($href) {
                        // Convert relative URLs to absolute
                        if (strpos($href, 'http') !== 0) {
                            $href = 'https://www.meesho.com' . $href;
                        }

                        // Only include product pages (pattern: /p/{product-id})
                        if (strpos($href, '/p/') !== false) {
                            $productUrls[] = $href;
                        }
                    }
                });
            }

            // Remove duplicates and limit results
            $productUrls = array_unique($productUrls);
            $productUrls = array_slice($productUrls, 0, 50); // Limit to 50 products per page

            Log::info("Extracted product URLs from Meesho category page", [
                'count' => count($productUrls),
                'category_url' => $categoryUrl
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to extract product URLs from Meesho", [
                'error' => $e->getMessage(),
                'category_url' => $categoryUrl
            ]);
        }

        return $productUrls;
    }

    /**
     * Extract product data from Meesho product page
     */
    protected function extractProductData(Crawler $crawler, string $productUrl): array
    {
        try {
            $data = [];

            // Extract product ID from URL
            $data["sku"] = $this->extractSkuFromUrl($productUrl);
            if (!$data["sku"]) {
                Log::warning("Could not extract SKU from Meesho URL: {$productUrl}");
                return [];
            }

            $data["product_url"] = $productUrl;

            // Product Information
            $data["title"] = $this->extractProductName($crawler);
            $data["description"] = $this->extractDescription($crawler);
            $data["brand"] = $this->extractBrand($crawler);
            $data["color"] = $this->extractColour($crawler);
            $data["size"] = $this->extractSize($crawler);
            $data["material"] = $this->extractMaterial($crawler);
            $data["highlights"] = $this->extractHighlights($crawler);
            $data["image_urls"] = $this->extractImages($crawler);
            $data["video_urls"] = $this->extractVideoUrls($crawler);
            $data["category"] = $this->extractCategory($crawler);

            // Price Information
            $priceData = $this->extractPrices($crawler);
            $data["price"] = $priceData["price"];
            $data["sale_price"] = $priceData["sale_price"];
            $data["currency_code"] = "INR"; // Meesho operates in India

            // Rating and Reviews
            $ratingData = $this->extractRatingAndReviews($crawler);
            $data["rating"] = $ratingData["rating"];
            $data["review_count"] = $ratingData["review_count"];

            // Meesho-specific attributes
            $data["seller_name"] = $this->extractSellerName($crawler);
            $data["seller_rating"] = $this->extractSellerRating($crawler);
            $data["delivery_time"] = $this->extractDeliveryTime($crawler);
            $data["return_policy"] = $this->extractReturnPolicy($crawler);
            $data["inventory_status"] = $this->extractAvailability($crawler);
            $data["offers"] = $this->extractOffers($crawler);

            // Product Specifications
            $specs = $this->extractSpecifications($crawler);
            $data = array_merge($data, $specs);

            // Sanitize all data
            $data = DataSanitizer::sanitizeProductData($data);

            Log::debug("Extracted Meesho product data", [
                "sku" => $data["sku"],
                "title" => $data["title"] ?? "N/A"
            ]);

            return $data;
        } catch (\Exception $e) {
            Log::error("Failed to extract Meesho product data", [
                "url" => $productUrl,
                "error" => $e->getMessage(),
                "trace" => $e->getTraceAsString()
            ]);
            return [];
        }
    }

    /**
     * Extract SKU from Meesho URL
     * Pattern: https://www.meesho.com/p/{product-id}
     */
    private function extractSkuFromUrl(string $url): ?string
    {
        // Meesho product ID pattern
        if (preg_match('/\/p\/([a-z0-9]+)/i', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Extract product name/title
     */
    private function extractProductName(Crawler $crawler): ?string
    {
        try {
            $selectors = [
                'h1.productTitle',
                'h1[class*="title"]',
                'h1',
                '[data-testid="productTitle"]',
                '.productName',
                '[class*="productName"]',
            ];

            foreach ($selectors as $selector) {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $text = $this->cleanText($element->text(''));
                    if ($text && strlen($text) > 3) {
                        return $text;
                    }
                }
            }

            // Fallback to meta tag
            $metaTitle = $crawler->filter('meta[name="title"], meta[property="og:title"]');
            if ($metaTitle->count() > 0) {
                $text = $this->cleanText($metaTitle->first()->attr('content'));
                return trim($text);
            }
        } catch (\Exception $e) {
            Log::debug("Error extracting product name: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Extract product description
     */
    private function extractDescription(Crawler $crawler): ?string
    {
        try {
            $selectors = [
                '.productDescription',
                '[data-testid="productDescription"]',
                '.description',
                'div[class*="description"]',
                'p[class*="description"]',
            ];

            foreach ($selectors as $selector) {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $text = $this->cleanText($element->text(''));
                    if ($text && strlen($text) > 10) {
                        return $text;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::debug("Error extracting description: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Extract brand
     */
    private function extractBrand(Crawler $crawler): ?string
    {
        try {
            $selectors = [
                '.brandName',
                '[data-testid="brandName"]',
                'span.brand',
                'a.brand',
            ];

            foreach ($selectors as $selector) {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $text = $this->cleanText($element->text(''));
                    if ($text && strlen($text) > 1) {
                        return $text;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::debug("Error extracting brand: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Extract color
     */
    private function extractColour(Crawler $crawler): ?string
    {
        try {
            $selectors = [
                'span:contains("Color")',
                'span:contains("colour")',
                '[data-testid="color"]',
                '.color-value',
            ];

            foreach ($selectors as $selector) {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $text = $this->cleanText($element->text(''));
                    if ($text && strlen($text) > 1) {
                        return $text;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::debug("Error extracting color: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Extract size
     */
    private function extractSize(Crawler $crawler): ?string
    {
        try {
            $selectors = [
                'span:contains("Size")',
                '[data-testid="size"]',
                '.size-value',
            ];

            foreach ($selectors as $selector) {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $text = $this->cleanText($element->text(''));
                    if ($text && strlen($text) > 1) {
                        return $text;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::debug("Error extracting size: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Extract material
     */
    private function extractMaterial(Crawler $crawler): ?string
    {
        try {
            $selectors = [
                'span:contains("Material")',
                '[data-testid="material"]',
                '.material-value',
            ];

            foreach ($selectors as $selector) {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $text = $this->cleanText($element->text(''));
                    if ($text && strlen($text) > 1) {
                        return $text;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::debug("Error extracting material: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Extract highlights/key features
     */
    private function extractHighlights(Crawler $crawler): ?string
    {
        try {
            $highlights = [];
            $selectors = [
                'ul.highlights li',
                '.highlights li',
                '[data-testid="highlights"] li',
                'ul[class*="feature"] li',
            ];

            foreach ($selectors as $selector) {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$highlights) {
                    $text = $this->cleanText($node->text(''));
                    if ($text && strlen($text) > 2) {
                        $highlights[] = $text;
                    }
                });
            }

            if (!empty($highlights)) {
                return implode(', ', array_slice($highlights, 0, 5)); // Limit to 5 highlights
            }
        } catch (\Exception $e) {
            Log::debug("Error extracting highlights: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Extract images
     */
    private function extractImages(Crawler $crawler): ?string
    {
        try {
            $images = [];
            $selectors = [
                'img.productImage',
                'img[data-testid="productImage"]',
                'img[class*="product"]',
                '.gallery img',
            ];

            foreach ($selectors as $selector) {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$images) {
                    $src = $node->attr('src');
                    if ($src && strpos($src, 'http') === 0) {
                        $images[] = $src;
                    }
                });
            }

            if (!empty($images)) {
                $images = array_unique($images);
                return implode(',', array_slice($images, 0, 10)); // Limit to 10 images
            }
        } catch (\Exception $e) {
            Log::debug("Error extracting images: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Extract video URLs
     */
    private function extractVideoUrls(Crawler $crawler): ?string
    {
        try {
            $videos = [];
            $selectors = [
                'video source',
                'iframe[src*="youtube"]',
                'iframe[src*="vimeo"]',
            ];

            foreach ($selectors as $selector) {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$videos) {
                    $src = $node->attr('src');
                    if ($src && strpos($src, 'http') === 0) {
                        $videos[] = $src;
                    }
                });
            }

            if (!empty($videos)) {
                return implode(',', array_slice($videos, 0, 5)); // Limit to 5 videos
            }
        } catch (\Exception $e) {
            Log::debug("Error extracting videos: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Extract category
     */
    private function extractCategory(Crawler $crawler): ?string
    {
        try {
            $selectors = [
                '.breadcrumb a:last-child',
                '[data-testid="breadcrumb"] a:last-child',
                'nav.breadcrumb li:last-child',
            ];

            foreach ($selectors as $selector) {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $text = $this->cleanText($element->text(''));
                    if ($text && strlen($text) > 1) {
                        return $text;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::debug("Error extracting category: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Extract prices
     */
    private function extractPrices(Crawler $crawler): array
    {
        $prices = [
            'price' => null,
            'sale_price' => null,
        ];

        try {
            // Try to find sale price first
            $salePriceSelectors = [
                '.salePrice',
                '[data-testid="salePrice"]',
                'span.price',
                '.productPrice',
            ];

            foreach ($salePriceSelectors as $selector) {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $text = $this->cleanText($element->text(''));
                    $price = $this->extractNumericPrice($text);
                    if ($price) {
                        $prices['sale_price'] = $price;
                        break;
                    }
                }
            }

            // Try to find original price
            $originalPriceSelectors = [
                '.originalPrice',
                '[data-testid="originalPrice"]',
                'span.strikePrice',
                '.mrp',
            ];

            foreach ($originalPriceSelectors as $selector) {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $text = $this->cleanText($element->text(''));
                    $price = $this->extractNumericPrice($text);
                    if ($price) {
                        $prices['price'] = $price;
                        break;
                    }
                }
            }

            // If only sale price found, use it as price
            if ($prices['sale_price'] && !$prices['price']) {
                $prices['price'] = $prices['sale_price'];
                $prices['sale_price'] = null;
            }
        } catch (\Exception $e) {
            Log::debug("Error extracting prices: " . $e->getMessage());
        }

        return $prices;
    }

    /**
     * Extract rating and review count
     */
    private function extractRatingAndReviews(Crawler $crawler): array
    {
        $data = [
            'rating' => null,
            'review_count' => null,
        ];

        try {
            // Extract rating
            $ratingSelectors = [
                '.rating',
                '[data-testid="rating"]',
                '.stars',
                'span.productRating',
            ];

            foreach ($ratingSelectors as $selector) {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $text = $this->cleanText($element->text(''));
                    $rating = $this->extractNumericPrice($text);
                    if ($rating && $rating <= 5) {
                        $data['rating'] = $rating;
                        break;
                    }
                }
            }

            // Extract review count
            $reviewSelectors = [
                '.reviewCount',
                '[data-testid="reviewCount"]',
                'span.reviews',
                '.totalReviews',
            ];

            foreach ($reviewSelectors as $selector) {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $text = $this->cleanText($element->text(''));
                    $count = $this->extractNumericPrice($text);
                    if ($count) {
                        $data['review_count'] = intval($count);
                        break;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::debug("Error extracting rating and reviews: " . $e->getMessage());
        }

        return $data;
    }

    /**
     * Extract seller name
     */
    private function extractSellerName(Crawler $crawler): ?string
    {
        try {
            $selectors = [
                '.sellerName',
                '[data-testid="sellerName"]',
                '.seller-info',
                'a.seller',
            ];

            foreach ($selectors as $selector) {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $text = $this->cleanText($element->text(''));
                    if ($text && strlen($text) > 1) {
                        return $text;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::debug("Error extracting seller name: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Extract seller rating
     */
    private function extractSellerRating(Crawler $crawler): ?float
    {
        try {
            $selectors = [
                '.sellerRating',
                '[data-testid="sellerRating"]',
                '.seller-rating',
            ];

            foreach ($selectors as $selector) {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $text = $this->cleanText($element->text(''));
                    $rating = $this->extractNumericPrice($text);
                    if ($rating && $rating <= 5) {
                        return floatval($rating);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::debug("Error extracting seller rating: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Extract delivery time
     */
    private function extractDeliveryTime(Crawler $crawler): ?string
    {
        try {
            $selectors = [
                '.deliveryTime',
                '[data-testid="deliveryTime"]',
                '.delivery-info',
                'span:contains("day")',
            ];

            foreach ($selectors as $selector) {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $text = $this->cleanText($element->text(''));
                    if ($text && strlen($text) > 1) {
                        return $text;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::debug("Error extracting delivery time: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Extract return policy
     */
    private function extractReturnPolicy(Crawler $crawler): ?string
    {
        try {
            $selectors = [
                '.returnPolicy',
                '[data-testid="returnPolicy"]',
                '.policy-info',
                'span:contains("return")',
            ];

            foreach ($selectors as $selector) {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $text = $this->cleanText($element->text(''));
                    if ($text && strlen($text) > 1) {
                        return $text;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::debug("Error extracting return policy: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Extract availability status
     */
    private function extractAvailability(Crawler $crawler): ?string
    {
        try {
            $selectors = [
                '.availability',
                '[data-testid="availability"]',
                '.stock-status',
                'span:contains("stock")',
            ];

            foreach ($selectors as $selector) {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $text = $this->cleanText($element->text(''));
                    if ($text && strlen($text) > 1) {
                        return $text;
                    }
                }
            }

            // Default to in stock if not found
            return 'In Stock';
        } catch (\Exception $e) {
            Log::debug("Error extracting availability: " . $e->getMessage());
        }

        return 'In Stock';
    }

    /**
     * Extract offers/discounts
     */
    private function extractOffers(Crawler $crawler): ?string
    {
        try {
            $offers = [];
            $selectors = [
                '.offers li',
                '[data-testid="offers"] li',
                '.offer-badge',
                'span.discount',
            ];

            foreach ($selectors as $selector) {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$offers) {
                    $text = $this->cleanText($node->text(''));
                    if ($text && strlen($text) > 2) {
                        $offers[] = $text;
                    }
                });
            }

            if (!empty($offers)) {
                return implode(', ', array_slice($offers, 0, 5)); // Limit to 5 offers
            }
        } catch (\Exception $e) {
            Log::debug("Error extracting offers: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Extract specifications
     */
    private function extractSpecifications(Crawler $crawler): array
    {
        $specs = [];

        try {
            $crawler->filter('.specifications tr, [data-testid="specifications"] tr')->each(function (Crawler $row) use (&$specs) {
                $cells = $row->filter('td, th');
                if ($cells->count() >= 2) {
                    $key = $this->cleanText($cells->eq(0)->text(''));
                    $value = $this->cleanText($cells->eq(1)->text(''));
                    
                    if ($key && $value) {
                        $specs[strtolower(str_replace(' ', '_', $key))] = $value;
                    }
                }
            });
        } catch (\Exception $e) {
            Log::debug("Error extracting specifications: " . $e->getMessage());
        }

        return $specs;
    }

    /**
     * Helper function to clean text
     */
    protected function cleanText(?string $text): ?string
    {
        if (!$text) {
            return null;
        }

        $text = trim(strip_tags($text));
        $text = preg_replace('/\s+/', ' ', $text);
        return $text ?: null;
    }

    /**
     * Helper function to extract numeric price
     */
    protected function extractNumericPrice(?string $text): ?float
    {
        if (!$text) {
            return null;
        }

        // Extract numbers (handles ₹, $, etc.)
        if (preg_match('/[\d,]+\.?\d*/', str_replace(',', '', $text), $matches)) {
            return floatval($matches[0]);
        }

        return null;
    }
}
