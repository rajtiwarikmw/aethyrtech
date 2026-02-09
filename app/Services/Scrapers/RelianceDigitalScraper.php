<?php

namespace App\Services\Scrapers;

use App\Services\DataSanitizer;
use Symfony\Component\DomCrawler\Crawler;
use Illuminate\Support\Facades\Log;
use Spatie\Browsershot\Browsershot;

class RelianceDigitalScraper extends BaseScraper
{
    protected function setupPlatformConfig(): void
    {
        $this->platform = 'reliancedigital';
        $this->useJavaScript = true;
        $this->paginationConfig = [
            'type' => 'regular',
            'max_pages' => 3,
            'page_param' => 'page_no',
            'has_next_selector' => '.pagination span[aria-label="Goto Next Page"]',
            'delay_between_pages' => [1, 2], // OPTIMIZED: Reduced delays
            'retry_failed_pages' => true,
            'max_retries_per_page' => 2 // OPTIMIZED: Reduced retries
        ];
    }

    public function __construct()
    {
        parent::__construct('reliancedigital');
    }

    protected function fetchPageWithBrowsershot(string $url): ?string
    {
        try {
            Log::debug("Fetching Reliance Digital page with JavaScript", ['url' => $url]);

            // Determine if this is a product page or category page
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
            
            $browsershot->waitForFunction(
                '() => document.readyState === "complete"',
                ['polling' => 500, 'timeout' => $timeout * 1000]
            );
            
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
                strpos($html, 'Oops!') !== false ||
                strpos($html, 'Access Denied') !== false) {
                Log::warning("Reliance Digital returned error page", ['url' => $url]);
                
                // Save HTML for debugging
                $debugFile = storage_path('logs/reliancedigital_debug_' . time() . '.html');
                file_put_contents($debugFile, $html);
                Log::debug("Saved HTML for debugging", ['file' => $debugFile]);
                
                return null;
            }

            return $html;

        } catch (\Exception $e) {
            Log::error("Failed to fetch Reliance Digital page", [
                'url' => $url,
                'error' => $e->getMessage(),
                'error_code' => $e->getCode()
            ]);
            return null;
        }
    }

    /**
     * Extract product URLs from Reliance Digital category page
     */
    protected function extractProductUrls(Crawler $crawler, string $categoryUrl): array
    {
        $productUrls = [];

        try {
            $selectors = [
                'a[href*="/product/"]',     
                '.sp__product a',
                '.product-tile a',
                '.product-item a',
                '.pdp-link',
                '.product-card a',
                'div[data-testid="product-card"] a',
                'a[data-testid="product-link"]',
            ];

            foreach ($selectors as $selector) {
                $nodes = $crawler->filter($selector);
                
                if ($nodes->count() > 0) {
                    Log::debug("Found Reliance Digital product links using selector", [
                        'selector' => $selector,
                        'count' => $nodes->count()
                    ]);

                    $nodes->each(function (Crawler $node) use (&$productUrls) {
                        $href = $node->attr('href');
                        if ($href) {
                            // Convert relative URLs to absolute
                            if (strpos($href, 'http') !== 0) {
                                $href = 'https://www.reliancedigital.in' . $href;
                            }
                            
                            // Include both /product/ and /p/ patterns
                            if (strpos($href, '/product/') !== false || strpos($href, '/p/') !== false) {
                                // Remove query parameters for consistency
                                $href = strtok($href, '?');
                                $productUrls[] = $href;
                            }
                        }
                    });

                    break; // Stop after finding products with first working selector
                }
            }

            $productUrls = array_unique($productUrls);
            $productUrls = array_slice($productUrls, 0, 500);

            Log::info("Extracted Reliance Digital product URLs", [
                'count' => count($productUrls),
                'category_url' => $categoryUrl
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to extract product URLs from Reliance Digital", [
                'error' => $e->getMessage(),
                'category_url' => $categoryUrl
            ]);
        }

        return $productUrls;
    }

    /**
     * Extract product data from Reliance Digital product page
     */
    protected function extractProductData(Crawler $crawler, string $productUrl): array
    {
        try {
            $data = [];

            // Extract SKU
            $data['sku'] = $this->extractSkuFromUrl($productUrl) ?: $this->extractSkuFromPage($crawler);
            if (!$data['sku']) {
                Log::warning("Could not extract SKU from Reliance Digital URL: {$productUrl}");
                return [];
            }

            $data['product_url'] = $productUrl;
            $data['platform_id'] = $data['sku'];

            // Try JSON-LD first (most reliable)
            $jsonLdData = $this->extractFromJsonLd($crawler);
            if ($jsonLdData) {
                $data = array_merge($data, $jsonLdData);
            }

            // Extract with fallbacks
            $data['title'] = $data['title'] ?? $this->extractProductName($crawler);
            $data['description'] = $data['description'] ?? $this->extractDescription($crawler);
            $data['brand'] = $data['brand'] ?? $this->extractBrand($crawler);
            $data['category'] = $this->extractCategory($crawler);
            $data["seller_name"] = $this->extractSellerName($crawler);
            $data["color"] = $this->extractColour($crawler);
            $data['image_urls'] = $data['image_urls'] ?? $this->extractImages($crawler);
            $data["manufacturer"] = $this->extractManufacturer($crawler);
            $data["dimensions"] = $this->extractProductDimensions($crawler);
            $data["weight"] = $this->extractItemWeight($crawler);


            // Prices
            if (!isset($data['price']) || !isset($data['sale_price'])) {
                $priceData = $this->extractPrices($crawler);
                $data['price'] = $data['price'] ?? $priceData['price'];
                $data['sale_price'] = $data['sale_price'] ?? $priceData['sale_price'];
            }
            $data['currency_code'] = 'INR';

            // Ratings
            if (!isset($data['rating']) || !isset($data['review_count'])) {
                $ratingData = $this->extractRatingAndReviews($crawler);
                $data['rating'] = $data['rating'] ?? $ratingData['rating'];
                $data['review_count'] = $data['review_count'] ?? $ratingData['review_count'];
            }
            $RatingHistogram = $this->extractRatingHistogram($crawler);
            $data["rating_1_star_percent"] = $RatingHistogram['rating_1_star_percent'];
            $data["rating_2_star_percent"] = $RatingHistogram['rating_2_star_percent'];
            $data["rating_3_star_percent"] = $RatingHistogram['rating_3_star_percent'];
            $data["rating_4_star_percent"] = $RatingHistogram['rating_4_star_percent'];
            $data["rating_5_star_percent"] = $RatingHistogram['rating_5_star_percent'];

            // Additional data
            $data['offers'] = $this->extractOffers($crawler);
            $data["highlights"] = $this->extractHighlights($crawler);
            $data['inventory_status'] = $this->extractAvailability($crawler);
            $data['model_name'] = $this->extractModelName($crawler);
            $data["delivery_price"] = $this->extractDeliveryPrice($crawler);
            $data["delivery_date"] = $this->extractDeliveryDate($crawler);
            $data["technical_details"] = $this->extractTechnicalDetails($crawler);
            $data['variation_attributes'] = $this->extractVariants($crawler);
            $data["additional_information"] = $this->extractAdditionalInformation($crawler);

            // Sanitize
            $data = DataSanitizer::sanitizeProductData($data);

            Log::debug("Extracted Reliance Digital product data", [
                'sku' => $data['sku'],
                'title' => $data['title'] ?? 'N/A'
            ]);

            return $data;

        } catch (\Exception $e) {
            Log::error("Failed to extract Reliance Digital product data", [
                'url' => $productUrl,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
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

                    if (isset($data['name'])) {
                        $extracted['title'] = $data['name'];
                    }

                    if (isset($data['description'])) {
                        $extracted['description'] = $data['description'];
                    }

                    if (isset($data['brand']['name'])) {
                        $extracted['brand'] = $data['brand']['name'];
                    } elseif (isset($data['brand']) && is_string($data['brand'])) {
                        $extracted['brand'] = $data['brand'];
                    }

                    if (isset($data['image'])) {
                        $extracted['image_urls'] = is_array($data['image']) ? $data['image'] : [$data['image']];
                    }

                    if (isset($data['offers'])) {
                        $offers = $data['offers'];
                        if (isset($offers['price'])) {
                            $extracted['sale_price'] = (float) $offers['price'];
                        }
                        if (isset($offers['highPrice'])) {
                            $extracted['price'] = (float) $offers['highPrice'];
                        }
                    }

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
     * Extract SKU from URL
     */
    private function extractSkuFromUrl(string $url): ?string
    {
        // Pattern: /product/hp-neverstop-laser-mfp-2606dn-multi-function-laserjet-printer-l2t8ca
        if (preg_match('/\/product\/([a-zA-Z0-9\-]+)(?:\?|$)/', $url, $matches)) {
            return $matches[1];
        }
        
        // Pattern: /product-name/p/494350841 (fallback)
        if (preg_match('/\/p\/(\d+)/', $url, $matches)) {
            return $matches[1];
        }
        
        // Pattern: /product-name-sku.html
        if (preg_match('/\/([a-zA-Z0-9\-]+)\.html/', $url, $matches)) {
            return $matches[1];
        }
        
        return null;
    }

    private function extractSkuFromPage(Crawler $crawler): ?string
    {
        $selectors = [
            '[data-product-id]',
            '[data-sku]',
            '.product-code',
            '.sku-number',
            '.model-number',
            'meta[property="product:retailer_item_id"]',
        ];

        foreach ($selectors as $selector) {
            $element = $crawler->filter($selector)->first();
            if ($element->count() > 0) {
                $sku = $element->attr('data-product-id') 
                    ?: $element->attr('data-sku')
                    ?: $element->attr('content')
                    ?: $element->text();
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
            'h1[data-testid="product-title"]',
            '.pdp__product-name',
            '.product-title h1',
            '.pdp-product-name',
            'h1.title',
            '.product-name',
            'h1',
        ];

        foreach ($selectors as $selector) {
            $element = $crawler->filter($selector)->first();
            if ($element->count() > 0) {
                $text = $this->cleanText($element->text());
                if ($text && strlen($text) > 3) {
                    return $text;
                }
            }
        }

        return null;
    }

    private function extractDescription(Crawler $crawler): ?string
    {
        $descNode = $crawler->filter('div.product-long-description')->first();

        if ($descNode->count() === 0) {
            return null;
        }

        $texts = [];

        // Case 1: Paragraph-based description
        $descNode->filter('p')->each(function (Crawler $p) use (&$texts) {
            $text = trim(preg_replace('/\s+/', ' ', $p->text()));
            if ($text && strlen($text) > 20) {
                $texts[] = $text;
            }
        });

        // Case 2: Plain text only (no <p>)
        if (empty($texts)) {
            $text = trim(preg_replace('/\s+/', ' ', $descNode->text()));
            if ($text && strlen($text) > 15) {
                $texts[] = $text;
            }
        }

        return !empty($texts)
            ? $this->cleanText(implode(' ', $texts))
            : null;
    }


    private function extractPrices(Crawler $crawler): array
    {
        $prices = [
            'price' => null,       // MRP / Original price
            'sale_price' => null,  // Selling price
        ];

        $salePriceSelectors = [
            '.product-price', // Reliance Digital
            'span[data-testid="selling-price"]',
            '.selling-price',
            '.offer-price',
            '.final-price',
            '.price-current',
        ];

        foreach ($salePriceSelectors as $selector) {
            try {
                $element = $crawler->filter($selector)->first();
                if ($element->count() > 0) {
                    $price = $this->extractPrice($element->text());
                    if ($price > 0) {
                        $prices['sale_price'] = $price;
                        break;
                    }
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        $mrpSelectors = [
            '.product-marked-price', // Reliance Digital
            'span[data-testid="mrp-price"]',
            '.mrp-price',
            '.original-price',
            '.price-was',
            '.strikethrough-price',
        ];

        foreach ($mrpSelectors as $selector) {
            try {
                $element = $crawler->filter($selector)->first();
                if ($element->count() > 0) {
                    $price = $this->extractPrice($element->text());
                    if ($price > 0) {
                        $prices['price'] = $price;
                        break;
                    }
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        if (!$prices['price'] && $prices['sale_price']) {
            $prices['price'] = $prices['sale_price'];
        }

        if (
            $prices['price'] &&
            $prices['sale_price'] &&
            $prices['sale_price'] > $prices['price']
        ) {
            $tmp = $prices['price'];
            $prices['price'] = $prices['sale_price'];
            $prices['sale_price'] = $tmp;
        }

        return $prices;
    }


    private function extractOffers(Crawler $crawler): ?string
    {
        $offers = [];

        $selectors = [
            '.offer-text',
            '.discount-info',
            '.promotion-text',
            'product-price-discount',
        ];

        foreach ($selectors as $selector) {
            $crawler->filter($selector)->each(function (Crawler $node) use (&$offers) {
                $text = $this->cleanText($node->text());
                if ($text) {
                    $offers[] = $text;
                }
            });
        }

        return !empty($offers) ? implode('; ', $offers) : null;
    }

    private function extractAvailability(Crawler $crawler): ?string
    {
        $availabilitySelectors = [
            'div[data-testid="stock-status"]',
            '.stock-status',
            '.availability-status',
            '.in-stock-message',
            'button[data-testid="add-to-cart"]',
        ];

        foreach ($availabilitySelectors as $selector) {
            $element = $crawler->filter($selector)->first();
            if ($element->count() > 0) {
                $text = $this->cleanText($element->text());
                if ($text) {
                    if (stripos($text, 'add') !== false || stripos($text, 'cart') !== false) {
                        return 'In Stock';
                    }
                    return $text;
                }
            }
        }

        return 'Available';
    }

    private function extractRatingAndReviews(Crawler $crawler): array
    {
        $data = ['rating' => null, 'review_count' => 0];

        $ratingSelectors = [
            'span[data-testid="rating"]',
            '.rating-value',
            '.star-rating-value',
            '.review-rating',
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

        $reviewSelectors = [
            'span[data-testid="review-count"]',
            '.review-count',
            '.total-reviews',
            '.reviews-number',
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

    private function extractBrand(Crawler $crawler): ?string
    {
        $selectors = [
            'span[data-testid="brand"]',
            '.brand-name',
            '.product-brand',
            'meta[property="product:brand"]',
        ];

        foreach ($selectors as $selector) {
            $element = $crawler->filter($selector)->first();
            if ($element->count() > 0) {
                $brand = $element->attr('content') ?: $element->text();
                if ($brand) {
                    return $this->cleanText($brand);
                }
            }
        }

        // Extract from title
        $title = $this->extractProductName($crawler);
        if ($title) {
            $brands = ['HP', 'Dell', 'Lenovo', 'ASUS', 'Acer', 'Apple', 'MSI', 'Samsung', 'LG', 'Sony', 'Toshiba', 'OnePlus', 'Xiaomi', 'Realme', 'Oppo', 'Vivo'];
            foreach ($brands as $brand) {
                if (stripos($title, $brand) !== false) {
                    return $brand;
                }
            }
        }

        return null;
    }

    private function extractCategory(Crawler $crawler): ?string
    {
        $selectors = [
            'nav[data-testid="breadcrumb"] a',
            '.breadcrumb a',
            '.category-path a',
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

        return !empty($categories) ? implode(' ,', $categories) : null;
    }
    
    private function extractSellerName(Crawler $crawler): ?string
    {
        $seller = null;

        $crawler->filter('li.specifications-list')->each(
            function (Crawler $node) use (&$seller) {

                $label = strtolower(trim($node->filter('span')->eq(0)->text('')));

                if (strpos($label, 'seller') !== false) {

                    $valueNode = $node->filter('.specifications-list--right ul');

                    if ($valueNode->count() > 0) {
                        $seller = trim($valueNode->text());
                    }
                }
            }
        );

        return $seller ? $this->cleanText($seller) : null;
    }

    private function extractModelName(Crawler $crawler): ?string
    {
        $model = null;

        $crawler->filter('#specification .specifications-list')->each(function (Crawler $node) use (&$model) {

            if ($model !== null) {
                return;
            }

            // Left label
            $labelNode = $node->filter('span')->first();
            if ($labelNode->count() === 0) {
                return;
            }

            $label = strtoupper(trim($labelNode->text()));

            if ($label === 'MODEL') {
                // Right value (inside ul)
                if ($node->filter('.specifications-list--right ul')->count() > 0) {
                    $model = trim(
                        $node->filter('.specifications-list--right ul')->first()->text()
                    );
                }
            }
        });

        return $model ?: null;
    }

    private function extractColour(Crawler $crawler): ?string
    {
        $colour = null;

        $crawler->filter('li.specifications-list')->each(
            function (Crawler $node) use (&$colour) {

                $label = strtolower(trim($node->filter('span')->eq(0)->text('')));

                if (
                    strpos($label, 'color') !== false ||
                    strpos($label, 'colour') !== false
                ) {
                    $valueNode = $node->filter('.specifications-list--right ul');

                    if ($valueNode->count() > 0) {
                        $colour = trim($valueNode->text());
                    }
                }
            }
        );

        return $colour ? $this->cleanText($colour) : null;
    }

    
    private function extractItemWeight(Crawler $crawler): ?string
    {
        $weight = null;

        $crawler->filter('li.specifications-list')->each(
            function (Crawler $node) use (&$weight) {

                $label = strtolower(trim($node->filter('span')->eq(0)->text('')));

                if (strpos($label, 'weight') !== false) {

                    $valueNode = $node->filter('.specifications-list--right ul');

                    if ($valueNode->count() > 0) {
                        $weight = $this->cleanText($valueNode->text());
                    }
                }
            }
        );

        return $weight;
    }

    private function extractProductDimensions(Crawler $crawler): ?string
    {
        $dimensions = [
            'length' => null,
            'width'  => null,
            'height' => null,
        ];

        $crawler->filter('li.specifications-list')->each(
            function (Crawler $node) use (&$dimensions) {

                $label = strtolower(trim($node->filter('span')->eq(0)->text('')));

                $valueNode = $node->filter('.specifications-list--right ul');
                $value = $valueNode->count() > 0
                    ? trim($valueNode->text())
                    : null;

                if (!$value) {
                    return;
                }

                if (strpos($label, 'item length') !== false) {
                    $dimensions['length'] = $value;
                } elseif (strpos($label, 'item width') !== false) {
                    $dimensions['width'] = $value;
                } elseif (strpos($label, 'item height') !== false) {
                    $dimensions['height'] = $value;
                }
            }
        );

        // Maintain order: L x W x H
        $final = array_filter([
            $dimensions['length'],
            $dimensions['width'],
            $dimensions['height'],
        ]);

        return !empty($final)
            ? implode(' x ', $final)
            : null;
    }

    
    private function extractManufacturer(Crawler $crawler): ?string
    {
        $manufacturer = null;

        $crawler->filter('li.specifications-list')->each(
            function (Crawler $node) use (&$manufacturer) {

                $label = trim($node->filter('span')->eq(0)->text(''));

                if (stripos($label, 'manufacturer') !== false) {

                    $valueNode = $node->filter('.specifications-list--right ul');

                    if ($valueNode->count() > 0) {
                        $manufacturer = $this->cleanText($valueNode->text());
                    }
                }
            }
        );

        return $manufacturer;
    }

    private function extractImages(Crawler $crawler): ?array
    {
        $images = [];

        $selectors = [
            'img[data-testid="product-image"]',
            '.pdp__product-images img',
            '.product-gallery img',
            '.main-image img',
        ];

        foreach ($selectors as $selector) {
            $crawler->filter($selector)->each(function (Crawler $node) use (&$images) {
                $src = $node->attr('src') ?: $node->attr('data-src') ?: $node->attr('srcset');
                if ($src) {
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

        return !empty($images) ? array_unique($images) : null;
    }

    private function extractVariants(Crawler $crawler): ?array
    {
        $variants = [];

        $crawler->filter('.variant-options li, .color-options li, div[data-testid="variant"] button')->each(function (Crawler $node) use (&$variants) {
            $title = $node->attr('title') ?: $node->attr('aria-label') ?: $node->text();
            if ($title) {
                $variants[] = ['type' => 'variant', 'value' => $this->cleanText($title)];
            }
        });

        return !empty($variants) ? $variants : null;
    }

    private function extractHighlights(Crawler $crawler): ?string
    {
        $highlights = [];

        // Reliance Digital - Key Features
        $crawler->filter('#key_features ul.features li')->each(function (Crawler $node) use (&$highlights) {
            $text = trim($node->text());
            if ($text !== '') {
                $highlights[] = $this->cleanText($text);
            }
        });

        return !empty($highlights)
            ? implode('. ', $highlights)
            : null;
    }
    private function extractTechnicalDetails(Crawler $crawler): ?array
    {
        $details = [];

        // Each specification row
        $selector = 'li.specifications-list';

        if ($crawler->filter($selector)->count() > 0) {

            $crawler->filter($selector)->each(function (Crawler $node) use (&$details) {

                // Key
                $key = trim($node->filter('span')->eq(0)->text(''));

                // Value (deep inside ul)
                $valueNode = $node->filter('.specifications-list--right ul');

                $value = $valueNode->count()
                    ? trim($valueNode->text(''))
                    : '';

                // Cleanup
                $key   = preg_replace('/\s+/', ' ', $key);
                $value = preg_replace('/\s+/', ' ', $value);

                if (!empty($key) && !empty($value)) {
                    $details[$key] = $value;
                }
            });
        }

        return !empty($details) ? $details : null;
    }

    private function extractDeliveryDate(Crawler $crawler): ?string
    {
        $node = $crawler->filter('h5.delivery-section--header')->first();

        if ($node->count() > 0) {
            $text = trim($node->text());

            // Extract date part after "by"
            if (preg_match('/by\s+(.+)$/i', $text, $matches)) {
                return $this->cleanText(trim($matches[1]));
            }
        }

        return null;
    }

    private function extractDeliveryPrice(Crawler $crawler): ?string
    {
        $node = $crawler->filter('h5.delivery-section--header')->first();

        if ($node->count() > 0) {
            $text = strtolower(trim($node->text()));

            if (strpos($text, 'free') !== false) {
                return 'Free';
            }

            // Future-proof: paid delivery
            if (preg_match('/₹\s?\d+/', $text, $matches)) {
                return $this->cleanText($matches[0]);
            }
        }

        return null;
    }

    private function extractRatingHistogram(Crawler $crawler): array
    {
        $ratings = [
            'rating_5_star_percent' => null,
            'rating_4_star_percent' => null,
            'rating_3_star_percent' => null,
            'rating_2_star_percent' => null,
            'rating_1_star_percent' => null,
        ];

        $crawler->filter('.rd-feedback-service-progress-row')->each(
            function (Crawler $row) use (&$ratings) {

                // Star value (5,4,3,2,1)
                $star = (int) trim(
                    $row->filter('.rd-feedback-service-rating-text')->text('0')
                );

                // Progress bar width percentage
                $bar = $row->filter('[style*="width"]')->first();

                if ($star >= 1 && $star <= 5 && $bar->count() > 0) {

                    $style = $bar->attr('style');

                    if (preg_match('/width:\s*([\d.]+)%/i', $style, $matches)) {
                        $ratings["rating_{$star}_star_percent"] = (float) $matches[1];
                    }
                }
            }
        );

        return $ratings;
    }
    
    private function extractAdditionalInformation(Crawler $crawler): ?array
    {
        $info = [];

        $rows = $crawler->filter('table.flix-std-specs-table tr');

        if ($rows->count() === 0) {
            return null;
        }

        $rows->each(function (Crawler $row) use (&$info) {

            $keyNode = $row->filter('th');
            $valueNode = $row->filter('td');

            if ($keyNode->count() === 0 || $valueNode->count() === 0) {
                return;
            }

            $key = trim(preg_replace('/\s+/', ' ', $keyNode->text()));

            // Preserve <br> as comma-separated values
            $value = trim(
                preg_replace(
                    '/\s+/',
                    ' ',
                    str_replace("\n", ', ', $valueNode->text())
                )
            );

            if ($key && $value) {
                $info[$key] = $value;
            }
        });

        return !empty($info) ? $info : null;
    }





}
