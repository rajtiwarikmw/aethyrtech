<?php

namespace App\Services\Scrapers;

use App\Services\DataSanitizer;
use Symfony\Component\DomCrawler\Crawler;
use Illuminate\Support\Facades\Log;

class BlinkitScraper extends BaseScraper
{
    protected function setupPlatformConfig(): void
    {
        $this->platform = 'blinkit';
        $this->useJavaScript = true; // Blinkit requires JS rendering for dynamic content
        $this->paginationConfig = [
            'type' => 'cursor', // Blinkit uses infinite scroll or cursor-based pagination
            'max_pages' => 100,
            'page_param' => 'page',
            'has_next_selector' => null, // Handled via JS/infinite scroll detection
            'max_consecutive_errors' => 50,
            'delay_between_pages' => [2, 5], // Random delay in seconds
            'retry_failed_pages' => true,
            'max_retries_per_page' => 3
        ];
    }

    public function __construct()
    {
        parent::__construct('blinkit');
    }

    /**
     * Extract product URLs from Blinkit category/search page
     */
    protected function extractProductUrls(Crawler $crawler, string $categoryUrl): array
    {
        $productUrls = [];

        try {
            
            $crawler->filter('div[tabindex="0"][role="button"]')->each(function (Crawler $node) use (&$productUrls) {
               
                $productId = $node->attr('id');
                
             
                $productNameNode = $node->filter('div.tw-text-300.tw-font-semibold.tw-line-clamp-2');
                $productName = $productNameNode->count() > 0 ? trim($productNameNode->text()) : null;

                if ($productId && $productName) {
                  
                    $slug = $this->createSlug($productName);
                    
                    $url = "https://blinkit.com/prn/{$slug}/prid/{$productId}";
                    $productUrls[] = $url;
                }
            });


            $productUrls = array_unique($productUrls);
            $productUrls = array_slice($productUrls, 0, 50);

            Log::info("Extracted {count} Blinkit product URLs from {category_url}", [
                'count' => count($productUrls),
                'category_url' => $categoryUrl
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to extract Blinkit product URLs", [
                'error' => $e->getMessage(),
                'category_url' => $categoryUrl
            ]);
        }

        return $productUrls;
    }

    /**
     * Extract product data from Blinkit product page
     */
    protected function extractProductData(Crawler $crawler, string $productUrl): array
    {
        try {
            $data = [];
            $data["product_url"] = $productUrl;

            // Try to extract from JSON-LD first (structured data)
            $jsonLd = $this->extractJsonLd($crawler);
            $data["sku"] = $jsonLd["sku"] ?? $this->extractProductIdFromUrl($productUrl);
            $data["title"] = $jsonLd["name"] ?? $this->extractTitle($crawler);
            $data["description"] = $jsonLd["description"] ?? $this->extractDescription($crawler);
            $data["brand"] = $jsonLd["brand"]["name"] ?? $this->extractBrand($crawler);
            $data["category"] = $jsonLd["category"] ?? $this->extractCategory($crawler);
            $data["image_urls"] = isset($jsonLd["image"]) ? (array) $jsonLd["image"] : $this->extractImages($crawler);

            
            $priceText = $jsonLd["offers"]["price"] ?? $this->extractPriceText($crawler);
            $data["price"] = $this->extractPrice($priceText);
            $data["sale_price"] = $data["price"]; // Blinkit often shows sale price as main price
            $data["mrp"] = $jsonLd["offers"]["listPrice"] ?? $this->extractMRP($crawler);
            $data["currency_code"] = $jsonLd["offers"]["priceCurrency"] ?? "INR";
            $data["discount"] = $this->extractDiscount($crawler);

            // Ratings and reviews
            $ratingText = $jsonLd["aggregateRating"]["ratingValue"] ?? $this->extractRatingText($crawler);
            $data["rating"] = $this->extractRating($ratingText);
            $reviewText = $jsonLd["aggregateRating"]["reviewCount"] ?? $this->extractReviewCountText($crawler);
            $data["review_count"] = $this->extractReviewCount($reviewText);

        
            $data["weight"] = $this->extractWeight($crawler);
            $data["dimensions"] = $this->extractDimensions($crawler);
            $data["manufacturer"] = $jsonLd["manufacturer"] ?? null;
            $data["asin"] = null; 
            $data["offers"] = $this->extractOffers($crawler);
            $data["inventory_status"] = $this->extractAvailability($crawler);
            $data["delivery_time"] = $this->extractDeliveryTime($crawler);
            $data["unit"] = $this->extractUnit($crawler);
            $data["technical_details"] = $this->extractTechnicalDetails($crawler);
            $data["additional_information"] = null;

            $data = DataSanitizer::sanitizeProductData($data);

            Log::debug("Extracted Blinkit product data", [
                "sku" => $data["sku"],
                "title" => $data["title"] ?? "N/A",
                "price" => $data["price"] ?? "N/A"
            ]);

            return $data;
        } catch (\Exception $e) {
            Log::error("Failed to extract Blinkit product data", [
                "url" => $productUrl,
                "error" => $e->getMessage(),
                "trace" => $e->getTraceAsString()
            ]);
            return [];
        }
    }

    /**
     * Extract JSON-LD structured data from script tags
     */
    protected function extractJsonLd(Crawler $crawler): array
    {
        try {
            $jsonNodes = $crawler->filter('script[type="application/ld+json"]');
            foreach ($jsonNodes as $node) {
                $json = trim($node->nodeValue);
                $decoded = json_decode($json, true);
                if ($decoded && isset($decoded['@type']) && $decoded['@type'] === 'Product') {
                    return $decoded;
                }
            }
            return [];
        } catch (\Exception $e) {
            Log::warning("Failed to parse JSON-LD", ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Extract product ID from URL (fallback for SKU)
     */
    protected function extractProductIdFromUrl(string $url): string
    {
        if (preg_match('/prid\/(\d+)/', $url, $matches)) {
            return $matches[1];
        }
        return md5($url);
    }

    /**
     * Extract title using Tailwind classes (fallback from listings/product page)
     */
    protected function extractTitle(Crawler $crawler): ?string
    {
        // Try product page specific, fallback to listing style
        $titleNode = $crawler->filter('h1, div.tw-text-400.tw-font-bold, div.tw-text-300.tw-font-semibold.tw-line-clamp-2')->first();
        return $titleNode->count() > 0 ? $this->cleanText($titleNode->text()) : null;
    }

    /**
     * Extract description (may be in a dedicated section or meta)
     */
    protected function extractDescription(Crawler $crawler): ?string
    {
        // Look for description div or meta tag
        $descNode = $crawler->filter('div[data-pf="product-description"], meta[name="description"]')->first();
        if ($descNode->count() > 0) {
            return $this->cleanText($descNode->attr('content') ?? $descNode->text());
        }
        // Fallback: first few paragraphs
        $paras = $crawler->filter('p')->slice(0, 3);
        return $paras->count() > 0 ? $this->cleanText($paras->text()) : null;
    }

    /**
     * Extract brand (from title or specific element)
     */
    protected function extractBrand(Crawler $crawler): ?string
    {
        $title = $this->extractTitle($crawler);
        if ($title) {
            // Assume first word(s) before model number is brand
            return $this->cleanText(preg_replace('/\s+(?:\d+.*)?$/', '', $title));
        }
        // Fallback selector
        $brandNode = $crawler->filter('span[data-test-id="pdp-brand"]')->first();
        return $brandNode->count() > 0 ? $this->cleanText($brandNode->text()) : null;
    }

    /**
     * Extract category (from breadcrumbs or meta)
     */
    protected function extractCategory(Crawler $crawler): ?string
    {
        $categoryNode = $crawler->filter('nav[data-test-id="breadcrumb"], meta[property="og:category"]')->first();
        return $categoryNode->count() > 0 ? $this->cleanText($categoryNode->text()) : 'Electronics > Printer Cartridges';
    }

    /**
     * Extract images using Tailwind or img tags
     */
    protected function extractImages(Crawler $crawler): array
    {
        $images = [];
        $crawler->filter('img[src][alt*="product"], div.tw-overflow-hidden img')->each(function (Crawler $node) use (&$images) {
            $src = $node->attr('src');
            if ($src && strpos($src, 'http') === 0 && !str_contains($src, 'logo') && !str_contains($src, 'badge')) {
                // Ensure full URL
                if (strpos($src, '//') === 0) {
                    $src = 'https:' . $src;
                }
                $images[] = $src;
            }
        });
        return array_unique($images);
    }

    /**
     * Extract price text from HTML
     */
    protected function extractPriceText(Crawler $crawler): ?string
    {
        $priceNode = $crawler->filter('div.tw-text-200.tw-font-semibold, span[data-test-id="pdp-price"]')->first();
        return $priceNode->count() > 0 ? $this->cleanText($priceNode->text()) : null;
    }

    /**
     * Extract price (override parent to use extracted text)
     */
    protected function extractPrice(?string $priceText): ?float
    {
        return parent::extractPrice($priceText);
    }

    /**
     * Extract MRP (original price)
     */
    protected function extractMRP(Crawler $crawler): ?string
    {
        $mrpNode = $crawler->filter('div.tw-text-200.tw-font-regular.tw-line-through, span[data-test-id="pdp-mrp"]')->first();
        return $mrpNode->count() > 0 ? $this->cleanText($mrpNode->text()) : null;
    }

    /**
     * Extract discount percentage or text
     */
    protected function extractDiscount(Crawler $crawler): ?string
    {
        $discountNode = $crawler->filter('div.tw-text-050.tw-absolute, span[data-test-id="pdp-discount"]')->first();
        if ($discountNode->count() > 0) {
            $text = $this->cleanText($discountNode->text());
            return preg_match('/(\d+)%/', $text, $matches) ? $matches[1] . '%' : $text;
        }
        return null;
    }

    /**
     * Extract rating text from HTML
     */
    protected function extractRatingText(Crawler $crawler): ?string
    {
        $ratingNode = $crawler->filter('div[data-test-id="pdp-rating"], span[aria-label*="stars"]')->first();
        return $ratingNode->count() > 0 ? $this->cleanText($ratingNode->text()) : null;
    }

    /**
     * Extract rating (override parent to use extracted text)
     */
    protected function extractRating(?string $ratingText): ?float
    {
        return parent::extractRating($ratingText);
    }

    /**
     * Extract review count text from HTML
     */
    protected function extractReviewCountText(Crawler $crawler): ?string
    {
        $reviewNode = $crawler->filter('span[data-test-id="pdp-review-count"]')->first();
        return $reviewNode->count() > 0 ? $this->cleanText($reviewNode->text()) : null;
    }

    /**
     * Extract review count (override parent to use extracted text)
     */
    protected function extractReviewCount(?string $reviewText): int
    {
        return parent::extractReviewCount($reviewText);
    }

    /**
     * Extract weight/unit
     */
    protected function extractWeight(Crawler $crawler): ?string
    {
        $unitNode = $crawler->filter('div.tw-text-200.tw-font-medium.tw-line-clamp-1, span[data-test-id="pdp-quantity"]')->first();
        return $unitNode->count() > 0 ? $this->cleanText($unitNode->text()) : null;
    }

    /**
     * Extract dimensions (if available)
     */
    protected function extractDimensions(Crawler $crawler): ?string
    {
        $dimNode = $crawler->filter('div[data-test-id="pdp-dimensions"]')->first();
        return $dimNode->count() > 0 ? $this->cleanText($dimNode->text()) : null;
    }

    /**
     * Extract delivery time
     */
    protected function extractDeliveryTime(Crawler $crawler): ?string
    {
        $deliveryNode = $crawler->filter('div.tw-text-050.tw-font-bold.tw-uppercase, span[data-test-id="pdp-delivery"]')->first();
        return $deliveryNode->count() > 0 ? $this->cleanText($deliveryNode->text()) : null;
    }

    /**
     * Extract unit/size
     */
    protected function extractUnit(Crawler $crawler): ?string
    {
        return $this->extractWeight($crawler); // Often the same as weight/unit
    }

    /**
     * Extract availability status
     */
    protected function extractAvailability(Crawler $crawler): string
    {
        $addButton = $crawler->filter('div[data-pf="reset"]:contains("ADD"), button:contains("ADD")')->count();
        return $addButton > 0 ? 'In Stock' : 'Out of Stock';
    }

    /**
     * Extract offers/discounts
     */
    protected function extractOffers(Crawler $crawler): ?string
    {
        $offers = [];
        $offerNode = $crawler->filter('div.tw-bg-green-050, span[data-test-id="pdp-offer"]')->first();
        if ($offerNode->count() > 0) {
            $offers[] = $this->cleanText($offerNode->text());
        }
        $discount = $this->extractDiscount($crawler);
        if ($discount) {
            $offers[] = $discount; // Include discount if extracted
        }
        return !empty($offers) ? implode("; ", $offers) : null;
    }

    /**
     * Extract technical details (specs table or list)
     */
    protected function extractTechnicalDetails(Crawler $crawler): ?array
    {
        $specs = [];
        $crawler->filter('table tr, ul li[data-spec]')->each(function (Crawler $node) use (&$specs) {
            $labelNode = $node->filter('th, strong')->first();
            $valueNode = $node->filter('td, span')->first();
            $label = $labelNode->count() > 0 ? $this->cleanText($labelNode->text()) : null;
            $value = $valueNode->count() > 0 ? $this->cleanText($valueNode->text()) : null;
            if ($label && $value) {
                $specs[$label] = $value;
            }
        });
        return !empty($specs) ? $specs : null;
    }

    /**
     * Create a URL-friendly slug from a string.
     */
    protected function createSlug(string $string): string
    {
        // Convert to lowercase, replace non-alphanumeric chars with hyphens, and trim
        $slug = strtolower($string);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug;
    }
}