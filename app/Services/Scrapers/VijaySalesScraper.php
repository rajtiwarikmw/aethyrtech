<?php

namespace App\Services\Scrapers;

use App\Services\DataSanitizer;
use Symfony\Component\DomCrawler\Crawler;
use Illuminate\Support\Facades\Log;

class VijaySalesScraper extends BaseScraper
{
    protected function setupPlatformConfig(): void
    {
        $this->platform = 'vijaysales';
        $this->useJavaScript = false; // VijaySales works with regular HTTP requests
        $this->paginationConfig = [
            'type' => 'regular',
            'max_pages' => 1,
            'page_param' => 'p',
            'has_next_selector' => '.pages .next',
        ];
    }

    public function __construct()
    {
        parent::__construct('vijaysales');
    }

    /**
     * Extract product URLs from VijaySales category page
     */
    protected function extractProductUrls(Crawler $crawler, string $categoryUrl): array
    {
        $productUrls = [];

        try {
            // Updated VijaySales product links patterns
            $selectors = [
                'a.product-card__link',
                '.product-card__link',   
                'a[href^="/p/"]',
                'a[data-href*="/p/"]',
            ];

            foreach ($selectors as $selector) {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$productUrls) {
                    $href = $node->attr('href');
                    if ($href) {
                        // Convert relative URLs to absolute
                        if (strpos($href, 'http') !== 0) {
                            $href = 'https://www.vijaysales.com' . $href;
                        }

                        // Only include valid product URLs
                        if ($this->isValidVijaySalesProductUrl($href)) {
                            $productUrls[] = $href;
                        }
                    }
                });

                // If we found products with this selector, break
                if (!empty($productUrls)) {
                    break;
                }
            }

            // Remove duplicates and limit results
            $productUrls = array_unique($productUrls);
            $productUrls = array_slice($productUrls, 0, 50);

            Log::info("Extracted {count} product URLs from VijaySales category page", [
                'count' => count($productUrls),
                'category_url' => $categoryUrl,
                'sample_urls' => array_slice($productUrls, 0, 3)
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to extract product URLs from VijaySales", [
                'error' => $e->getMessage(),
                'category_url' => $categoryUrl
            ]);
        }

        return $productUrls;
    }

    /**
     * Check if URL is a valid VijaySales product URL
     */
    private function isValidVijaySalesProductUrl(string $url): bool
    {
        return strpos($url, 'vijaysales.com') !== false &&
            (strpos($url, '/product/') !== false ||
                strpos($url, '/p/') !== false ||
                preg_match('/\/[a-zA-Z0-9\-]+\.html$/', $url));
    }

    /**
     * Extract product data from VijaySales product page
     */
    protected function extractProductData(Crawler $crawler, string $productUrl): array
    {
        try {
            $data = [];

            // Extract SKU from URL or page
            //$data['sku'] = $this->extractSkuFromUrl($productUrl) ?: $this->extractSkuFromPage($crawler);
            $data['sku'] = $this->extractSkuFromUrl($productUrl);
            if (!$data['sku']) {
                Log::warning("Could not extract SKU from VijaySales URL: {$productUrl}");
                return [];
            }

            $data['product_url'] = $productUrl;
            $data['title'] = $this->extractProductName($crawler);
            $data['description'] = $this->extractDescription($crawler);
            
            // ✅ NEW: Extract all missing fields
            $data['brand'] = $this->extractBrand($crawler);
            $data['manufacturer'] = $this->extractManufacturer($crawler);
            $data['model_name'] = $this->extractModelName($crawler);
            $data['color'] = $this->extractColour($crawler);
            $data['highlights'] = $this->extractHighlights($crawler);
            $data['product_badge'] = $this->extractProductBadge($crawler);
            $data['category'] = $this->extractCategory($crawler);
            $data['technical_details'] = $this->extractTechnicalDetails($crawler);
            $data['weight'] = $this->extractItemWeight($crawler);
            $data['dimensions'] = $this->extractProductDimensions($crawler);
            $data['delivery_date'] = $this->extractDeliveryDate($crawler);
            $data['delivery_price'] = $this->extractDeliveryPrice($crawler);

            $priceData = $this->extractPrices($crawler);
            $data['price'] = $priceData['price'];
            $data['sale_price'] = $priceData['sale_price'];

            $data['offers'] = $this->extractOffers($crawler);
            $data['inventory_status'] = $this->extractAvailability($crawler);

            $ratingData = $this->extractRatingAndReviews($crawler);
            $data['rating'] = $ratingData['rating'];
            $data['review_count'] = $ratingData['review_count'];
            $data['rating_count'] = $ratingData['rating_count'];

            $specs = $this->extractSpecifications($crawler);
            $data = array_merge($data, $specs);

            $data['image_urls'] = $this->extractImages($crawler);
            $data['variants'] = $this->extractVariants($crawler);

            $data = DataSanitizer::sanitizeProductData($data);

            Log::debug("Extracted VijaySales product data", [
                'sku' => $data['sku'],
                'title' => $data['title'] ?? 'N/A'
            ]);

            return $data;
        } catch (\Exception $e) {
            Log::error("Failed to extract VijaySales product data", [
                'url' => $productUrl,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    private function extractSkuFromUrl(string $url): ?string
    {
        // Case 1: parent + child
        if (preg_match('#/p/([A-Z0-9]+)/(\d+)#i', $url, $matches)) {
            return strtoupper($matches[1]) . '-' . $matches[2];
        }

        // Case 2: only child
        if (preg_match('#/p/(\d+)#', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }


    private function extractSkuFromPage(Crawler $crawler): ?string
    {
        $selectors = [
            '[data-product-sku]',
            '.product-sku',
            '.sku-number',
            '.product-code'
        ];

        foreach ($selectors as $selector) {
            $element = $crawler->filter($selector)->first();
            if ($element->count() > 0) {
                $sku = $element->attr('data-product-id') ?: $element->text();
                if ($sku) {
                    return $this->cleanText($sku);
                }
            }
        }

        return null;
    }

    private function extractProductName(Crawler $crawler): ?string
    {
        $selectors = [
            '.page-title h1',
            '.product-name h1',
            '.product-title',
            '.productFullDetail__title .productFullDetail__productName span[role="name"]',
            '.productFullDetail__productName span[role="name"]',
        ];

        foreach ($selectors as $selector) {
            $element = $crawler->filter($selector)->first();
            if ($element->count() > 0) {
                return $this->cleanText($element->text());
            }
        }

        return null;
    }

    private function extractDescription(Crawler $crawler): ?string
    {
        $descriptions = [];

        $crawler->filter('.product-description, .short-description, .product-overview')->each(function (Crawler $node) use (&$descriptions) {
            $text = $this->cleanText($node->text());
            if ($text && strlen($text) > 10) {
                $descriptions[] = $text;
            }
        });

        return !empty($descriptions) ? implode('. ', $descriptions) : null;
    }

    private function extractPrices(Crawler $crawler): array
    {
        $prices = [
            'price' => null,
            'sale_price' => null,
        ];

        // ✅ FINAL / SALE PRICE (VISIBLE only)
        $saleNode = $crawler->filter(
            '.product__price--deatils:not(.d-none) [data-final-price]'
        );

        if ($saleNode->count() > 0) {
            $prices['sale_price'] = (float) $saleNode->first()->attr('data-final-price');
        }

        // ✅ MRP PRICE (VISIBLE only)
        $mrpNode = $crawler->filter(
            '.product__price--deatils:not(.d-none) [data-mrp]'
        );

        if ($mrpNode->count() > 0) {
            $prices['price'] = (float) $mrpNode->first()->attr('data-mrp');
        }

        return $prices;
    }

    private function extractOffers(Crawler $crawler): ?string
    {
        $offers = [];

        $crawler->filter('.product__price--discount-label')
            ->each(function (Crawler $node) use (&$offers) {
                $text = $this->cleanText($node->text());
                if ($text) {
                    $offers[] = $text;
                }
            });

        // Remove duplicates
        $offers = array_unique($offers);

        return !empty($offers) ? implode('; ', $offers) : null;
    }

    private function extractAvailability(Crawler $crawler): ?string
    {
        // Get all instock elements
        $crawler->filter('.instock__text')->each(function (Crawler $node) use (&$availability) {

            $classes = $node->attr('class') ?? '';

            // Visible element (no d-none)
            if (strpos($classes, 'd-none') === false) {
                $availability = 'In Stock';
            }
        });

        if (!empty($availability)) {
            return $availability;
        }

        // If instock__text exists but all are hidden
        if ($crawler->filter('.instock__text')->count() > 0) {
            return 'Out of Stock';
        }

        // Fallback selectors (generic)
        $availabilitySelectors = [
            '.stock-status',
            '.availability',
            '.in-stock',
            '.out-of-stock',
        ];

        foreach ($availabilitySelectors as $selector) {
            $element = $crawler->filter($selector)->first();
            if ($element->count() > 0) {
                return $this->cleanText($element->text());
            }
        }

        // Safe default
        return 'In Stock';
    }


    private function extractRatingAndReviews(Crawler $crawler): array
    {
        $data = [
            'rating' => null,
            'rating_count' => 0,
            'review_count' => 0
        ];

        // Extract rating from data-rating-summary
        $ratingElement = $crawler->filter('.product__title--reviews-star')->first();
        if ($ratingElement->count() > 0) {
            $data['rating'] = (float) $ratingElement->attr('data-rating-summary');
        }

        // Extract rating count and review count from span text
        // Format: "5.0 (4 Ratings & 4 Reviews)"
        $reviewElement = $crawler->filter('.product__title--stats span')->first();
        if ($reviewElement->count() > 0) {
            $text = $reviewElement->text();
            
            // Extract rating count
            if (preg_match('/(\d+)\s+Ratings?/i', $text, $matches)) {
                $data['rating_count'] = (int) $matches[1];
            }
            
            // Extract review count
            if (preg_match('/(\d+)\s+Reviews?/i', $text, $matches)) {
                $data['review_count'] = (int) $matches[1];
            }
        }

        return $data;
    }

    private function extractSpecifications(Crawler $crawler): array
    {
        $specs = [];

        $crawler->filter('.product-specs tr, .specifications tr, .tech-specs tr')->each(function (Crawler $row) use (&$specs) {
            $cells = $row->filter('td');
            if ($cells->count() >= 2) {
                $label = strtolower($this->cleanText($cells->eq(0)->text()));
                $value = $this->cleanText($cells->eq(1)->text());

                if (strpos($label, 'ram') !== false || strpos($label, 'memory') !== false) {
                    $specs['ram'] = $value;
                }
            }
        });

        return $specs;
    }

    private function extractImages(Crawler $crawler): ?array
    {
        $images = [];

        $crawler->filter('.thumbnail__image')->each(function (Crawler $node) use (&$images) {
            $src = $node->attr('src') ?: $node->attr('data-src');
            if ($src && strpos($src, 'http') === 0) {
                $images[] = $src;
            }
        });

        return !empty($images) ? array_unique($images) : null;
    }

    private function extractVariants(Crawler $crawler): ?array
    {
        $variants = [];

        $crawler->filter('.color-options li, .variant-options li')->each(function (Crawler $node) use (&$variants) {
            $title = $node->attr('title') ?: $node->text();
            if ($title) {
                $variants[] = ['type' => 'variant', 'value' => $this->cleanText($title)];
            }
        });

        return !empty($variants) ? $variants : null;
    }

    // ============================================
    // ✅ NEW EXTRACTION METHODS
    // ============================================

    private function extractBrand(Crawler $crawler): ?string
    {
        $brand = null;

        $crawler->filter('.productspecification .panel-list-key')->each(function (Crawler $keyNode) use (&$brand) {
            if (trim(strtoupper($keyNode->text())) === 'BRAND') {
                $valueNode = $keyNode->siblings()->filter('.panel-list-value')->first();
                if ($valueNode->count() > 0) {
                    $brand = trim($valueNode->text());
                }
            }
        });

        return $brand;
    }

    private function extractManufacturer(Crawler $crawler): ?string
    {
        $manufacturer = null;

        $crawler->filter('.productspecification .panel-list-key')->each(function (Crawler $keyNode) use (&$manufacturer) {
            $keyText = trim(strtoupper($keyNode->text()));
            if ($keyText === 'MANUFACTURERS DETAILS' || $keyText === 'MANUFACTURER DETAILS') {
                $valueNode = $keyNode->siblings()->filter('.panel-list-value')->first();
                if ($valueNode->count() > 0) {
                    $manufacturer = trim($valueNode->text());
                }
            }
        });

        return $manufacturer;
    }

    private function extractModelName(Crawler $crawler): ?string
    {
        $modelName = null;

        $crawler->filter('.productspecification .panel-list-key')->each(function (Crawler $keyNode) use (&$modelName) {
            if (trim(strtoupper($keyNode->text())) === 'MODEL NAME') {
                $valueNode = $keyNode->siblings()->filter('.panel-list-value')->first();
                if ($valueNode->count() > 0) {
                    $modelName = trim($valueNode->text());
                }
            }
        });

        return $modelName;
    }

    private function extractColour(Crawler $crawler): ?string
    {
        $colour = null;

        // Check in specifications
        $crawler->filter('.productspecification .panel-list-key')->each(function (Crawler $keyNode) use (&$colour) {
            $keyText = trim(strtoupper($keyNode->text()));
            if ($keyText === 'COLOR' || $keyText === 'COLOUR') {
                $valueNode = $keyNode->siblings()->filter('.panel-list-value')->first();
                if ($valueNode->count() > 0) {
                    $colour = trim($valueNode->text());
                }
            }
        });

        return $colour;
    }

    private function extractHighlights(Crawler $crawler): ?string
    {
        $highlights = [];

        // Vijay Sales key features structure
        $crawler->filter('div.product__keyfeatures ul.product__keyfeatures--list > li')
            ->each(function (Crawler $node) use (&$highlights) {
                $text = trim(preg_replace('/\s+/', ' ', $node->text()));
                if ($text !== '') {
                    $highlights[] = $text;
                }
            });

        return !empty($highlights) ? implode(' | ', array_unique($highlights)) : null;
    }

    private function extractProductBadge(Crawler $crawler): ?string
    {
        // Extract product badge/label
        // Format: <p class="product__tags--label label-two" style="display: block;">New Arrival</p>
        
        $badge = null;
        
        $badgeElement = $crawler->filter('p.product__tags--label')->first();
        if ($badgeElement->count() > 0) {
            // Check if it's visible (not d-none and display is not none)
            $style = $badgeElement->attr('style');
            $classes = $badgeElement->attr('class');
            
            if (strpos($classes, 'd-none') === false && 
                strpos($style, 'display: none') === false) {
                $badge = trim($badgeElement->text());
            }
        }

        return $badge;
    }

    private function extractCategory(Crawler $crawler): ?string
    {
        // Vijay Sales doesn't have visible breadcrumbs in the HTML provided
        // This would need to be extracted from URL or page metadata
        return null;
    }

    private function extractTechnicalDetails(Crawler $crawler): ?array
    {
        $details = [];

        // Loop through all keys inside productspecification
        $crawler->filter('.productspecification .panel-list-key')->each(function (Crawler $keyNode) use (&$details) {
            $valueNode = $keyNode->siblings()->filter('.panel-list-value')->first();
            if ($valueNode->count() > 0) {
                $key = trim($keyNode->text());
                $value = trim($valueNode->text());
                if ($key && $value) {
                    $details[$key] = $value;
                }
            }
        });

        return !empty($details) ? $details : null;
    }

    private function extractItemWeight(Crawler $crawler): ?string
    {
        $weight = null;

        // Loop through all accordion sections
        $crawler->filter('.accordion-item')->each(function (Crawler $section) use (&$weight) {

            if ($weight !== null) {
                return;
            }

            $section->filter('.panel-list-key')->each(function (Crawler $keyNode) use (&$weight) {

                $key = strtoupper(trim(preg_replace('/\s+/', ' ', $keyNode->text())));

                if (str_contains($key, 'WEIGHT')) {
                    $valueNode = $keyNode->siblings('.panel-list-value')->first();
                    if ($valueNode->count() > 0) {
                        $weight = trim(preg_replace('/\s+/', ' ', $valueNode->text()));
                    }
                }
            });
        });

        return $weight;
    }


    private function extractProductDimensions(Crawler $crawler): ?string
    {
        $dimensions = null;

        // Loop through accordion sections
        $crawler->filter('.accordion-item')->each(function (Crawler $section) use (&$dimensions) {

            if ($dimensions !== null) {
                return;
            }

            $titleNode = $section->filter('.accordion-title');
            if ($titleNode->count() === 0) {
                return;
            }

            $title = strtoupper(trim($titleNode->text()));

            // Only dimension sections
            if (!str_contains($title, 'DIMENSION') && !str_contains($title, 'SIZE')) {
                return;
            }

            // Extract dimension value
            $section->filter('.panel-list-key')->each(function (Crawler $keyNode) use (&$dimensions) {

                $key = strtoupper(trim(preg_replace('/\s+/', ' ', $keyNode->text())));

                if (
                    str_contains($key, 'DIMENSION') ||
                    str_contains($key, 'SIZE') ||
                    str_contains($key, 'W X')
                ) {
                    $valueNode = $keyNode->siblings('.panel-list-value')->first();
                    if ($valueNode->count() > 0) {
                        $dimensions = trim(preg_replace('/\s+/', ' ', $valueNode->text()));
                    }
                }
            });
        });

        return $dimensions;
    }



    private function extractDeliveryDate(Crawler $crawler): ?string
    {
        // Format: <p class="delivery__text">Free delivery by 16 December, 2025</p>
        
        $deliveryDate = null;
        
        $deliveryElement = $crawler->filter('p.delivery__text')->first();
        if ($deliveryElement->count() > 0) {
            $text = $deliveryElement->text();
            
            // Extract date pattern: "by 16 December, 2025"
            if (preg_match('/by\s+(\d+\s+\w+,?\s+\d{4})/i', $text, $matches)) {
                $deliveryDate = trim($matches[1]);
            }
        }

        return $deliveryDate;
    }

    private function extractDeliveryPrice(Crawler $crawler): ?string
    {
        // Format: <p class="delivery__text">Free delivery by 16 December, 2025</p>
        
        $deliveryPrice = null;
        
        $deliveryElement = $crawler->filter('p.delivery__text')->first();
        if ($deliveryElement->count() > 0) {
            $text = strtolower($deliveryElement->text());
            
            // Check for "free delivery"
            if (strpos($text, 'free delivery') !== false) {
                $deliveryPrice = 'Free';
            }
            // Check for price pattern like "₹40 delivery"
            elseif (preg_match('/₹\s?\d+/', $text, $matches)) {
                $deliveryPrice = trim($matches[0]);
            }
        }

        return $deliveryPrice;
    }
}
