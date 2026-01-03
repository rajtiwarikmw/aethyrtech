<?php

namespace App\Services\Scrapers;

use App\Services\DataSanitizer;
use Symfony\Component\DomCrawler\Crawler;
use Illuminate\Support\Facades\Log;
use Spatie\Browsershot\Browsershot;

class CromaScraper extends BaseScraper
{
    protected function setupPlatformConfig(): void
    {
        $this->platform = 'croma';
        $this->useJavaScript = true; // Croma requires JavaScript
        $this->paginationConfig = [
            'type' => 'regular',
            'max_pages' => 100,
            'page_param' => 'page',
            'has_next_selector' => '.pagination .next:not(.disabled)',
            'delay_between_pages' => [3, 6], // Increased delays
            'retry_failed_pages' => true,
            'max_retries_per_page' => 3
        ];
    }

    public function __construct()
    {
        parent::__construct('croma');
    }

    /**
     * Fetch page with JavaScript rendering and enhanced headers
     */
    protected function fetchPageWithBrowsershot(string $url): ?string
    {
        try {
            Log::debug("Fetching Croma page with JavaScript", ['url' => $url]);

            $html = Browsershot::url($url)
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
                ->waitUntilNetworkIdle()
                ->timeout(60)
                ->bodyHtml();

            $contentLength = strlen($html);

            Log::debug("Croma page response", [
                'status_code' => 200,
                'content_length' => $contentLength
            ]);

            if ($contentLength < 1000) {
                Log::warning("Croma returned suspiciously small response", [
                    'content_length' => $contentLength,
                    'url' => $url
                ]);
                return null;
            }

            // Check for redirects to homepage or error pages
            if (strpos($html, '<title>Croma Electronics | Online Electronics Shopping') !== false &&
                strpos($url, '/p/') !== false) {
                Log::warning("Croma redirected product page to homepage", ['url' => $url]);
                
                // Save HTML for debugging
                $debugFile = storage_path('logs/croma_redirect_debug_' . time() . '.html');
                file_put_contents($debugFile, $html);
                Log::debug("Saved HTML for debugging", ['file' => $debugFile]);
                
                return null;
            }

            // Check for other error pages
            if (strpos($html, 'Access Denied') !== false ||
                strpos($html, 'Page not found') !== false) {
                Log::warning("Croma returned error page", ['url' => $url]);
                
                // Save HTML for debugging
                $debugFile = storage_path('logs/croma_debug_' . time() . '.html');
                file_put_contents($debugFile, $html);
                Log::debug("Saved HTML for debugging", ['file' => $debugFile]);
                
                return null;
            }

            return $html;

        } catch (\Exception $e) {
            Log::error("Failed to fetch Croma page", [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Extract product URLs from Croma category page
     */
    protected function extractProductUrls(Crawler $crawler, string $categoryUrl): array
    {
        $productUrls = [];

        try {
            // Croma product link selectors (updated for 2024)
            $selectors = [
                'a[href*="/p/"]',  // Product links with /p/ pattern
                '.product-item a',
                '.plp-product-tile a',
                '.product-tile-wrapper a',
                '.product-card a',
                '.cp-product a',
                'div[data-testid="product-card"] a',
                'a[data-testid="product-link"]',
            ];

            foreach ($selectors as $selector) {
                $nodes = $crawler->filter($selector);
                
                if ($nodes->count() > 0) {
                    Log::debug("Found Croma product links using selector", [
                        'selector' => $selector,
                        'count' => $nodes->count()
                    ]);

                    $nodes->each(function (Crawler $node) use (&$productUrls) {
                        $href = $node->attr('href');
                        if ($href) {
                            // Convert relative URLs to absolute
                            if (strpos($href, 'http') !== 0) {
                                $href = 'https://www.croma.com' . $href;
                            }
                            
                            // Only include product pages (with /p/ pattern)
                            if (strpos($href, '/p/') !== false) {
                                $productUrls[] = $href;
                            }
                        }
                    });

                    break; // Stop after finding products with first working selector
                }
            }

            $productUrls = array_unique($productUrls);
            $productUrls = array_slice($productUrls, 0, 50);

            Log::info("Extracted Croma product URLs", [
                'count' => count($productUrls),
                'category_url' => $categoryUrl
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to extract product URLs from Croma", [
                'error' => $e->getMessage(),
                'category_url' => $categoryUrl
            ]);
        }

        return $productUrls;
    }

    /**
     * Extract product data from Croma product page
     */
    protected function extractProductData(Crawler $crawler, string $productUrl): array
    {
        try {
            $data = [];

            // Extract SKU
            $data['sku'] = $this->extractSkuFromUrl($productUrl) ?: $this->extractSkuFromPage($crawler);
            if (!$data['sku']) {
                Log::warning("Could not extract SKU from Croma URL: {$productUrl}");
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
            $data["color"] = $this->extractColour($crawler);
            $data['image_urls'] = $data['image_urls'] ?? $this->extractImages($crawler);
            $data["weight"] = $this->extractItemWeight($crawler);
            $data["dimensions"] = $this->extractProductDimensions($crawler);
            $data["highlights"] = $this->extractHighlights($crawler);
            $data["manufacturer"] = $this->extractManufacturer($crawler);
            $data["video_urls"] = $this->extractVideoUrls($crawler);
            $data["delivery_date"] = $this->extractDeliveryDate($crawler);

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
            $data['inventory_status'] = $this->extractAvailability($crawler);
            $data['model_name'] = $this->extractModelName($crawler);
            $data["technical_details"] = $this->extractTechnicalDetails($crawler);
            $data['variation_attributes'] = $this->extractVariants($crawler);

            // Sanitize
            $data = DataSanitizer::sanitizeProductData($data);

            Log::debug("Extracted Croma product data", [
                'sku' => $data['sku'],
                'title' => $data['title'] ?? 'N/A'
            ]);

            return $data;

        } catch (\Exception $e) {
            Log::error("Failed to extract Croma product data", [
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

    private function extractSkuFromUrl(string $url): ?string
    {
        // Pattern: /product-name/p/299691
        if (preg_match('/\/p\/(\d+)/', $url, $matches)) {
            return $matches[1];
        }
        
        // Pattern: /product-name-sku
        if (preg_match('/\/([a-zA-Z0-9\-]+)$/', $url, $matches)) {
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
            '.model-number',
            '.sku-number',
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
            '.pdp-product-name',
            '.product-title h1',
            '.cp-product-name',
            'h1.title',
            '.product-name',
            'h1.pdp-title',
            '.pdp-title',
            '[data-testid="product-title"]',
            '.product-details h1',
            'h1[class*="product"]',
            'h1[class*="title"]',
            '[itemprop="name"]',
            'h1',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $text = $element->first()->text();
                    $text = $this->cleanText($text);
                    
                    // Validate meaningful text
                    if (!empty($text) && strlen($text) > 3) {
                        Log::debug("Extracted Croma title using selector: {$selector}", ['title' => $text]);
                        return $text;
                    }
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        Log::warning("Failed to extract Croma product title with any selector");
        return null;
    }

    private function extractDescription(Crawler $crawler): ?string
    {
        $descriptions = [];

        // ✅ ONLY selectors that exist in provided HTML
        $descSelectors = [
            '.MuiAccordionDetails-root p',
            '.accordian-content p',
            '.overview-fixed-height p',
            '#overview_inner_container p',
            '.cp-overview p',
        ];

        foreach ($descSelectors as $selector) {
            if ($crawler->filter($selector)->count() > 0) {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$descriptions) {
                    $text = $this->cleanText($node->text());
                    if ($text && strlen($text) > 20) {
                        $descriptions[] = $text;
                    }
                });
                break;
            }
        }

        return !empty($descriptions)
            ? implode('. ', array_unique($descriptions))
            : null;
    }


    private function extractPrices(Crawler $crawler): array
    {
        $prices = ['price' => null, 'sale_price' => null];

        //  Sale price (current price)
        $saleSelector = '.cp-price.main-product-price .new-price .amount';
        $element = $crawler->filter($saleSelector)->first();
        if ($element->count() > 0) {
            $price = $this->extractPrice($element->text());
            if ($price) {
                $prices['sale_price'] = $price;
            }
        }

        //  Original price (MRP / old price)
        $mrpSelector = '.cp-price.discount .old-price .amount';
        $element = $crawler->filter($mrpSelector)->first();
        if ($element->count() > 0) {
            $price = $this->extractPrice($element->text());
            if ($price) {
                $prices['price'] = $price;
            }
        }

        // Fallback: if no MRP, use sale price as price
        if (!$prices['price'] && $prices['sale_price']) {
            $prices['price'] = $prices['sale_price'];
        }

        return $prices;
    }


    private function extractOffers(Crawler $crawler): ?string
    {
        $offers = [];

        $selectors = [
            '.cp-price .dicount-value',
            '.outer-product-pricebox .offer-text',
            '.discount-info',
            'div[data-testid="offers"]',
        ];

        foreach ($selectors as $selector) {
            $crawler->filter($selector)->each(function (Crawler $node) use (&$offers) {
                $text = trim($node->text());
                if (!empty($text)) {
                    $offers[] = $text;
                }
            });

            // Stop at first selector that has offers
            if (!empty($offers)) {
                break;
            }
        }

        return !empty($offers) ? implode('; ', $offers) : null;
    }


    private function extractAvailability(Crawler $crawler): ?string
    {
        $availabilitySelectors = [
            'div[data-testid="stock-status"]',
            '.stock-status',
            '.availability-info',
            '.in-stock',
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

        // ✅ Rating
        $crawler->filter('div.cp-rating')->each(function (Crawler $node) use (&$data) {
            // Try nested span first
            $nestedText = $node->filter('span:first-child')->count() ? trim($node->filter('span:first-child')->text()) : '';
            
            // If no nested text, fallback to direct div text
            if (!$nestedText) {
                $nestedText = trim($node->text());
            }

            // Extract numeric rating
            if ($nestedText && preg_match('/\d+(\.\d+)?/', $nestedText, $matches)) {
                $data['rating'] = (float)$matches[0];
            }
        });

        // ✅ Review count
        $crawler->filter('div.cp-rating span.text a.pr-review')->each(function (Crawler $node) use (&$data) {
            $text = trim($node->text());
            if ($text && preg_match('/(\d+)\s+Reviews/', $text, $matches)) {
                $data['review_count'] = (int)$matches[1];
            }
        });

        return $data;
    }



    private function extractBrand(Crawler $crawler): ?string
    {
        $brand = null;

        // Loop through all spec info blocks
        $crawler->filter('.cp-specification-spec-info')->each(function (Crawler $node) use (&$brand) {

            if ($brand !== null) {
                return; // Already found
            }

            $labelNode = $node->filter('.cp-specification-spec-title h4')->first();
            $valueNode = $node->filter('.cp-specification-spec-details')->first();

            if ($labelNode->count() === 0 || $valueNode->count() === 0) {
                return;
            }

            $label = strtoupper(trim($labelNode->text()));
            $value = trim($valueNode->text());

            // Only pick if label is exactly "BRAND"
            if ($label === 'BRAND') {
                $brand = $this->cleanText($value);
            }
        });

        return $brand ?: null;
    }



    private function extractCategory(Crawler $crawler): ?string
    {
        $categories = [];

        $crawler->filter('.cp-breadcrumb ul.list li a')->each(function (Crawler $node) use (&$categories) {
            $text = trim($node->text());
            if ($text && !in_array($text, $categories) && strtolower($text) !== 'home') {
                $categories[] = $text;
            }
        });

        return !empty($categories) ? implode(' , ', $categories) : null;
    }


    private function extractColour(Crawler $crawler): ?string
    {
        $colourList = [];
        $colour = null;

        try {
            $crawler->filter('.cp-specification-spec-details')->each(function (Crawler $node) use (&$colourList) {
                $titleNode = $node->previousAll('.cp-specification-spec-title')->first();

                if ($titleNode->count() > 0) {
                    $label = strtolower(trim($titleNode->filter('h4')->text()));

                    if ($label === 'Color') {
                        $text = trim($node->text());
                        if ($text !== '') {
                            $colourList[] = $text;
                        }
                    }
                }
            });

            if (!empty($colourList)) {
                $colour = implode(', ', array_unique($colourList));
            }

        } catch (\Exception $e) {
            Log::warning('Colour extraction failed', ['error' => $e->getMessage()]);
        }

        return $colour ? $this->cleanText($colour) : null;
    }

    private function extractItemWeight(Crawler $crawler): ?string
    {
        $weight = null;

        $crawler->filter('.cp-specification-spec-details')->each(function (Crawler $node) use (&$weight) {

            $titleNode = $node->previousAll('.cp-specification-spec-title')->first();

            if ($titleNode->count() > 0) {
                $label = strtolower(trim($titleNode->filter('h4')->text()));

                // Match ONLY item weight field
                if ($label === 'main unit weight') {
                    $text = trim($node->text());
                    if ($text !== '') {
                        $weight = $text;
                    }
                }
            }
        });

        return $weight ? $this->cleanText($weight) : null;
    }
    
    private function extractProductDimensions(Crawler $crawler): ?string
    {
        $dimensions = [];

        $crawler->filter('.cp-specification-spec-details')->each(function (Crawler $node) use (&$dimensions) {

            $titleNode = $node->previousAll('.cp-specification-spec-title')->first();

            if ($titleNode->count() > 0) {
                $label = strtolower(trim($titleNode->filter('h4')->text()));
                $value = trim($node->text());

                if ($value === '') {
                    return;
                }

                // Prefer CM dimensions
                if ($label === 'dimensions in cm (wxdxh)') {
                    $dimensions['cm'] = $value. ' cm';
                }

                // Fallback: Inches
                if (!isset($dimensions['cm']) && $label === 'dimensions in inches (wxdxh)') {
                    $dimensions['in'] = $value. ' inch';
                }
            }
        });

        if (!empty($dimensions)) {
            return $this->cleanText($dimensions['cm'] ?? $dimensions['in']);
        }

        return null;
    }


    private function extractModelName(Crawler $crawler): ?string
    {
        $model = null;

        // Loop through all spec info
        $crawler->filter('.cp-specification-spec-info')->each(function (Crawler $node) use (&$model) {

            if ($model !== null) {
                return; // Already found, skip
            }

            $labelNode = $node->filter('.cp-specification-spec-title h4')->first();
            $valueNode = $node->filter('.cp-specification-spec-details')->first();

            if ($labelNode->count() === 0 || $valueNode->count() === 0) {
                return;
            }

            $label = strtoupper(trim($labelNode->text()));
            $value = trim($valueNode->text());

            if ($label === 'MODEL NUMBER' || $label === 'MODEL SERIES') {
                $model = $this->cleanText($value);
            }
        });

        return $model ?: null;
    }



    private function extractSpecifications(Crawler $crawler): ?array
    {
        $specs = [];

        $crawler->filter('.product-specs tr, .specifications tr, .tech-specs tr')->each(function (Crawler $row) use (&$specs) {
            $cells = $row->filter('td');
            if ($cells->count() >= 2) {
                $label = $this->cleanText($cells->eq(0)->text());
                $value = $this->cleanText($cells->eq(1)->text());
                
                if ($label && $value) {
                    $specs[$label] = $value;
                }
            }
        });

        return !empty($specs) ? $specs : null;
    }

    private function extractImages(Crawler $crawler): ?array
    {
        $images = [];

        $selectors = [
            'img[data-testid^="super-zoom-img"]', // main gallery images
            'img[data-testid^="galary-thumb-img"]', // thumbnail images
            '.pdp-product-image img',
            '.product-gallery img',
            '.main-image img',
        ];

        foreach ($selectors as $selector) {
            $crawler->filter($selector)->each(function (Crawler $node) use (&$images) {
                // Prefer full-size image in data-zoom, then data-src, then src
                $src = $node->attr('data-zoom') ?: $node->attr('data-src') ?: $node->attr('src') ?: $node->attr('srcset');

                if ($src) {
                    // srcset may contain multiple URLs
                    if (strpos($src, ',') !== false) {
                        $srcParts = explode(',', $src);
                        $src = trim(explode(' ', trim($srcParts[0]))[0]);
                    }

                    // Ensure full URL
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

        $crawler->filter('.variant-options li, .color-swatches li, div[data-testid="variant"] button')->each(function (Crawler $node) use (&$variants) {
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

        $crawler->filter('.key-features-box .cp-keyfeature ul li')->each(function (Crawler $node) use (&$highlights) {
            $text = trim($node->text());
            if (!empty($text)) {
                $highlights[] = $text;
            }
        });

        return !empty($highlights)
            ? implode('. ', array_unique($highlights))
            : null;
    }

    private function extractManufacturer(Crawler $crawler): ?string
    {
        $manufacturer = null;

        $crawler->filter('.cp-specification-spec-details')->each(function (Crawler $node) use (&$manufacturer) {

            if ($manufacturer !== null) {
                return;
            }

            $titleNode = $node->previousAll('.cp-specification-spec-title')->first();

            if ($titleNode->count() > 0) {
                $label = strtolower(trim($titleNode->filter('h4')->text()));

                if ($label === 'manufacturer/importer/marketer name & address') {
                    $text = trim($node->text());
                    if ($text !== '') {
                        $manufacturer = $text;
                    }
                }
            }
        });

        return $manufacturer ? $this->cleanText($manufacturer) : null;
    }

    private function extractVideoUrls(Crawler $crawler): ?array
    {
        $videoUrls = [];

        // ✅ Product gallery video thumbnail (data-video-url)
        $crawler->filter('.cp-product-gallery img[data-video-url]')->each(function (Crawler $node) use (&$videoUrls) {
            $url = $node->attr('data-video-url');
            if ($url) {
                $videoUrls[] = $url;
            }
        });

        // ✅ Video source inside product section
        $crawler->filter('.cp-product-gallery video source')->each(function (Crawler $node) use (&$videoUrls) {
            $src = $node->attr('src');
            if ($src) {
                $videoUrls[] = $src;
            }
        });

        // ✅ Embedded videos (YouTube / Vimeo)
        $crawler->filter('iframe[src*="youtube"], iframe[src*="vimeo"]')->each(function (Crawler $node) use (&$videoUrls) {
            $src = $node->attr('src');
            if ($src) {
                $videoUrls[] = $src;
            }
        });

        return !empty($videoUrls) ? array_values(array_unique($videoUrls)) : null;
    }

    private function extractDeliveryDate(Crawler $crawler): ?string
    {

        $node = $crawler->filter('.cp-ship-opt .del-date p.pdp-delivery-details')->first();

        if ($node->count() > 0) {
            $date = trim($node->text());
            if (!empty($date)) {
                return $this->cleanText($date);
            }
        }

        return null;
    }

    private function extractTechnicalDetails(Crawler $crawler): ?array
    {
        $details = [];

        $crawler->filter('.cp-specification-spec-info')->each(function (Crawler $ul) use (&$details) {

            $keyNode = $ul->filter('.cp-specification-spec-title h4')->first();
            $valueNode = $ul->filter('.cp-specification-spec-details')->first();

            if ($keyNode->count() > 0 && $valueNode->count() > 0) {
                $key = trim(preg_replace('/\s+/', ' ', $keyNode->text()));
                $value = trim(preg_replace('/\s+/', ' ', $valueNode->text()));

                if ($key && $value) {
                    $details[$key] = $value;
                }
            }
        });

        return !empty($details) ? $details : null;
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

        // Loop through each star row
        $crawler->filter('div.barAndStar, div.barAndStar-no-review')->each(function (Crawler $node) use (&$ratings) {

            // Star value: text inside .star-text, e.g., "5 star"
            $starText = $node->filter('.star-text')->first()->text('');
            if (preg_match('/(\d+)\s*star/i', $starText, $matches)) {
                $star = (int)$matches[1];

                // Get width from the bar inside .bar-container > div
                $barNode = $node->filter('.bar-container div')->first();
                if ($barNode->count() > 0) {
                    $style = $barNode->attr('style');
                    if (preg_match('/width:\s*([\d.]+)%/i', $style, $matchesWidth)) {
                        $ratings["rating_{$star}_star_percent"] = (float)$matchesWidth[1];
                    }
                }
            }
        });

        return $ratings;
    }






}
