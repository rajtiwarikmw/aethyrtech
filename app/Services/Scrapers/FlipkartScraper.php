<?php

namespace App\Services\Scrapers;

use App\Services\DataSanitizer;
use Symfony\Component\DomCrawler\Crawler;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Cookie\CookieJar;
class FlipkartScraper extends BaseScraper
{
    protected function setupPlatformConfig(): void
    {
        $this->platform = 'flipkart';
        $this->useJavaScript = false; // Start with HTTP, fallback to browser
        $this->paginationConfig = [
            'type' => 'regular',
            'max_pages' => 100,
            'page_param' => 'page',
            'has_next_selector' => '._1LKTO3:last-child:not(._34Gtpf)',
            'max_consecutive_errors' => 500, // Allow more errors before stopping
            'delay_between_pages' => [3, 7], // Longer delays to avoid rate limiting
            'retry_failed_pages' => true,
            'max_retries_per_page' => 3
        ];

        // Will be set dynamically using UserAgentRotator
        $this->defaultHeaders = [];
    }

    public function __construct()
    {
        parent::__construct('flipkart');
    }

    /**
     * Override scrape method to implement browser fallback for 403 errors
     */
    public function scrape(array $categoryUrls): void
    {
        $this->scrapingLog = \App\Models\ScrapingLog::startSession($this->platform);

        try {
            Log::info("Starting Flipkart scraping with HTTP requests", [
                'platform' => $this->platform,
                'categories' => count($categoryUrls)
            ]);

            foreach ($categoryUrls as $categoryUrl) {
                // Try HTTP first
                if ($this->tryHttpScraping($categoryUrl)) {
                    // Success
                } else {
                    // If HTTP fails, switch to browser automation
                    Log::warning("HTTP scraping failed for Flipkart, switching to browser automation");
                    $this->useJavaScript = true;
                    $this->scrapeCategoryWithBrowser($categoryUrl);
                }

                if ($this->isExecutionTimeLimitReached()) {
                    break;
                }
            }

            $this->scrapingLog->complete($this->stats);
        } catch (\Exception $e) {
            $this->handleError("Scraping failed for Flipkart", $e);
            $this->scrapingLog->fail($e->getMessage(), [], $this->stats);
        }
    }

    /**
     * Try HTTP scraping with enhanced anti-blocking measures
     */
    private function tryHttpScraping(string $categoryUrl): bool
    {
        $userAgentRotator = new \App\Services\UserAgentRotator();
        $attempts = 0;
        $maxAttempts = 3;

        while ($attempts < $maxAttempts) {
            try {
                // Get randomized headers for this attempt
                $this->defaultHeaders = $userAgentRotator->getBrowserSessionHeaders();

                Log::info("Attempting HTTP scraping for Flipkart", [
                    'attempt' => $attempts + 1,
                    'url' => $categoryUrl,
                    'user_agent' => substr($this->defaultHeaders['User-Agent'], 0, 50) . '...'
                ]);

                // Add random delay before request
                sleep(rand(3, 8));

                $html = $this->fetchPage($categoryUrl);

                if ($html && strlen($html) > 1000) {
                    // Process the page if we got valid content
                    $productCount = $this->processPageContent($html, $categoryUrl);

                    if ($productCount > 0) {
                        Log::info("HTTP scraping successful for Flipkart", [
                            'products_found' => $productCount,
                            'attempt' => $attempts + 1
                        ]);

                        // Continue with pagination if first page was successful
                        $this->scrapeCategoryWithPagination($categoryUrl);
                        return true;
                    }
                }
            } catch (\Exception $e) {
                Log::warning("HTTP attempt failed for Flipkart", [
                    'attempt' => $attempts + 1,
                    'error' => $e->getMessage()
                ]);
            }

            $attempts++;

            // Exponential backoff with randomization
            if ($attempts < $maxAttempts) {
                $delay = pow(2, $attempts) * rand(3, 7);
                Log::info("Waiting {$delay} seconds before next attempt");
                sleep($delay);
            }
        }

        return false;
    }

    /**
     * Extract product URLs from Flipkart search/category page
     */
    protected function extractProductUrls(Crawler $crawler, string $categoryUrl): array
    {
        $productUrls = [];

        try {
            // Flipkart product links patterns
            $selectors = [
                'a[href*="/p/"]',
                'a.k7wcnx', 

            ];

            foreach ($selectors as $selector) {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$productUrls) {
                    $href = $node->attr('href');
                    if ($href) {
                        // Convert relative URLs to absolute
                        if (strpos($href, 'http') !== 0) {
                            $href = 'https://www.flipkart.com' . $href;
                        }
                        //$href = explode('?', $href)[0];
                        // Only include Product product pages
                        if (strpos($href, '/p/') !== false) {
                            $productUrls[] = $href;
                        }
                    }
                });
            }

            // Remove duplicates and limit results
            $productUrls = array_unique($productUrls);
            $productUrls = array_slice($productUrls, 0, 50); // Limit to 50 products per page

            Log::info("Extracted {count} product URLs from Flipkart category page", [
                'count' => count($productUrls),
                'category_url' => $categoryUrl
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to extract product URLs from Flipkart", [
                'error' => $e->getMessage(),
                'category_url' => $categoryUrl
            ]);
        }

        return $productUrls;
    }

    /**
     * Extract product data from Flipkart product page
     */
    protected function extractProductData(Crawler $crawler, string $productUrl): array
    {
        try {
            $data = [];

            // Extract SKU from URL
            $data['sku'] = $this->extractSkuFromUrl($productUrl);
            if (!$data['sku']) {
                Log::warning("Could not extract SKU from Flipkart URL: {$productUrl}");
                return [];
            }

            $data['product_url'] = $productUrl = explode('?', $productUrl)[0];

            // Product name
            $data['title'] = $this->extractProductName($crawler);

            // Description
            $data['description'] = $this->extractDescription($crawler);
            $data["brand"] = $this->extractBrand($crawler);
            $data["model_name"] = $this->extractModelName($crawler);
            $data["color"] = $this->extractColour($crawler);
            $data["weight"] = $this->extractItemWeight($crawler);
            $data["dimensions"] = $this->extractProductDimensions($crawler);
            $data["highlights"] = $this->extractHighlights($crawler);
            $data["manufacturer"] = $this->extractManufacturer($crawler);
            $data["video_urls"] = $this->extractVideoUrls($crawler);
            $data["category"] = $this->extractCategory($crawler);
            $data["seller_name"] = $this->extractSellerName($crawler);
            $data["delivery_price"] = $this->extractDeliveryPrice($crawler);
            $data["delivery_date"] = $this->extractDeliveryDate($crawler);
            $data["technical_details"] = $this->extractTechnicalDetails($crawler);
            $data["additional_information"] = $this->extractAdditionalInformation($crawler);

            // Prices
            $priceData = $this->extractPrices($crawler);
            $data['price'] = $priceData['price'];
            $data['sale_price'] = $priceData['sale_price'];
            $data["currency_code"] = $this->extractCurrencyCode($crawler);

            // Offers
            $data['offers'] = $this->extractOffers($crawler);

            // Availability
            $data['inventory_status'] = $this->extractAvailability($crawler);

            // Rating and reviews
            $ratingData = $this->extractRatingAndReviews($crawler);
            $data['rating'] = $ratingData['rating'];
            $data['review_count'] = $ratingData['review_count'];
            $RatingHistogram = $this->extractRatingHistogram($crawler);
            $data["rating_1_star_percent"] = $RatingHistogram['rating_1_star_percent'];
            $data["rating_2_star_percent"] = $RatingHistogram['rating_2_star_percent'];
            $data["rating_3_star_percent"] = $RatingHistogram['rating_3_star_percent'];
            $data["rating_4_star_percent"] = $RatingHistogram['rating_4_star_percent'];
            $data["rating_5_star_percent"] = $RatingHistogram['rating_5_star_percent'];


            // Specifications
            $specs = $this->extractSpecifications($crawler);
            $data = array_merge($data, $specs);

            // Images
            $data['image_urls'] = $this->extractImages($crawler);

            // Variants
            $data['variants'] = $this->extractVariants($crawler);

            // Sanitize all data
            $data = DataSanitizer::sanitizeProductData($data);

            Log::debug("Extracted Flipkart product data", [
                'sku' => $data['sku'],
                'title' => $data['title'] ?? 'N/A'
            ]);

            return $data;
        } catch (\Exception $e) {
            Log::error("Failed to extract Flipkart product data", [
                'url' => $productUrl,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Extract SKU from Flipkart URL
     */
    private function extractSkuFromUrl(string $url): ?string
    {
        // Flipkart product ID pattern
        if (preg_match('#/p/(itm[a-zA-Z0-9]+)#', $url, $matches)) {
            return $matches[1];
        }

        if (preg_match('/pid=([A-Z0-9]+)/', $url, $matches)) {
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
            '.v1zwn21j.v1zwn26',
            'div.v1zwn21j.v1zwn26',
            '[class*="v1zwn26"]',
        ];

        foreach ($selectors as $selector) {
            $element = $crawler->filter($selector)->first();

            if ($element->count() > 0) {

                // Try raw text
                $text = trim($element->text());

                // If text empty, try inner HTML without tags
                if (!$text) {
                    $text = trim(strip_tags($element->html()));
                }

                // Ensure final text is valid
                if ($text && strlen($text) > 3) {
                    return $this->cleanText($text);
                }
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

        // Flipkart description main block
        $flipkartSelectors = [
            '.NF5i5Y.zH_aw0 p',
            '.NF5i5Y.zH_aw0',
            '.cdXR5N p',
            '.tUTk_J p', 
            '._4gvKMe p',
        ];

        foreach ($flipkartSelectors as $selector) {
            if ($crawler->filter($selector)->count()) {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$descriptions) {
                    $text = trim($node->text());
                    if ($text && strlen($text) > 10) {
                        $descriptions[] = $text;
                    }
                });
            }
        }

        // Flipkart Key Features
        $crawler->filter('._1mXcCf li, ._3k-BhJ li, .w9jEaj')->each(function (Crawler $node) use (&$descriptions) {
            $text = trim($node->text());
            if ($text && strlen($text) > 10) {
                $descriptions[] = $text;
            }
        });

        // Amazon fallback selectors
        $amazonSelectors = [
            '.NF5i5Y.zH_aw0',
            '#productDescription p',
            '.a-expander-content p',
            '.aplus-v2 p'
        ];

        foreach ($amazonSelectors as $selector) {
            if ($crawler->filter($selector)->count()) {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$descriptions) {
                    $text = trim($node->text());
                    if ($text && strlen($text) > 10) {
                        $descriptions[] = $text;
                    }
                });
            }
        }

        $descriptions = array_unique($descriptions); // remove duplicates

        return !empty($descriptions) ? implode(' ', $descriptions) : null;
    }



    /**
     * Extract prices
     */
    private function extractPrices(Crawler $crawler): array
    {
        $prices = ["price" => null, "sale_price" => null];

        // Current price (sale price)
        $salePriceSelectors = [
            '.v1zwn21j.v1zwn20',
            '[class*="v1zwn21j"][class*="v1zwn20"]',
            ".Nx9bqj.CxhGGd",
            ".hZ3P6w.bnqy13",
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

        // Original price (MRP or regular price)
        $mrpPriceSelectors = [
            '.v1zwn21k.v1zwn21',
             '[class*="v1zwn21k"][style*="line-through"]',
            ".hl05eU .yRaY8j",
            ".kRYCnD.yHYOcc",
        ];
            
        foreach ($mrpPriceSelectors as $selector) {
            $element = $crawler->filter($selector)->first();
            if ($element->count() > 0) {
                $priceText = $this->cleanText($element->text());
                $price = $this->extractPrice($priceText);
                if ($price) {
                    $prices["price"] = $price;       // This is the regular price
                    break;
                }
            }
        }


        return $prices;
    }


     
    private function extractCurrencyCode(Crawler $crawler): string
    {
        return 'INR';
    }


    /**
     * Extract offers and discounts
     */
    private function extractOffers(Crawler $crawler): ?string
    {
        $offers = [];

        // 🔒 Lock inside product container
        $root = $crawler->filter('.OmE16y')->first();

        if (!$root->count()) {
            return null;
        }

        $selectors = [
            '.v1zwn21y.v1zwn20',   // New Flipkart layout (discount %)
            '[class*="v1zwn21y"]', // fallback
        ];

        foreach ($selectors as $selector) {

            $elements = $root->filter($selector);

            foreach ($elements as $node) {

                $element = new Crawler($node);
                $text = trim($element->text());

                // Only accept values containing %
                if (preg_match('/\d+\s?%/', $text)) {
                    $offers[] = $this->cleanText($text);
                }
            }

            if (!empty($offers)) {
                break; // stop after first valid selector
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
            '._16FRp0',
            '._3xgqrA',
            '._1fGeJ5',
            '.yN+eNk'
        ];

        foreach ($availabilitySelectors as $selector) {
            $element = $crawler->filter($selector)->first();
            if ($element->count() > 0) {
                $text = $this->cleanText($element->text());
                if ($text && (stripos($text, 'stock') !== false || stripos($text, 'available') !== false)) {
                    return $text;
                }
            }
        }

        return 'In Stock'; // Default for Flipkart
    }

    /**
     * Extract rating and review count
     */
    private function extractRatingAndReviews(Crawler $crawler): array
    {
        $data = ['rating' => null, 'review_count' => 0];

        $ratingSelectors = [
            '.asbjxx.A02XR3.lV7ANv.Yd5OMU .css-1rynq56',
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
            'a[href*="ratings-reviews"] .css-1rynq56'
        ];

        foreach ($reviewSelectors as $selector) {
            $element = $crawler->filter($selector)->first();
            if ($element->count() > 0) {
                $text = trim($element->text());

                // Flipkart format: "278 Ratings & 23 Reviews"
                if (preg_match('/(\d+)\s+Ratings.*?(\d+)\s+Reviews/', $text, $matches)) {
                    $data['review_count'] = (int)$matches[2];
                } else {
                    $reviewCount = $this->extractReviewCount($text);
                    if ($reviewCount > 0) {
                        $data['review_count'] = $reviewCount;
                    }
                }

                break;
            }
        }

        return $data;
    }



    /**
     * Extract technical specifications
     */
    private function extractSpecifications(Crawler $crawler): array
    {
        $specs = [];

        // Extract from specifications table
        $crawler->filter('._1s_Smc tr, ._21lJbe tr')->each(function (Crawler $row) use (&$specs) {
            $cells = $row->filter('td');
            if ($cells->count() >= 2) {
                $label = strtolower($this->cleanText($cells->eq(0)->text()));
                $value = $this->cleanText($cells->eq(1)->text());

                if (strpos($label, 'color') !== false || strpos($label, 'colour') !== false) {
                    $specs['color'] = $value;
                }
            }
        });

        // Extract from key features if specs table not found
        if (empty($specs)) {
            $description = $this->extractDescription($crawler);
            if ($description) {
                $extractedSpecs = DataSanitizer::extractSpecifications($description);
                $specs = array_merge($specs, $extractedSpecs);
            }
        }

        return $specs;
    }

    /**
     * Extract product images
     */
     private function extractImages(Crawler $crawler): ?array
    {
        $images = [];

        $crawler->filter('ul.f67RGv li img.EIfF82')->each(function (Crawler $node) use (&$images) {
            $src = $node->attr('src');
            if ($src && strpos($src, 'http') === 0) {
                $images[] = $src;
            }
        });

        return !empty($images) ? array_values(array_unique($images)) : null;
    }

    /**
     * Extract product variants
     */
    private function extractVariants(Crawler $crawler): ?string
    {
        $variants = [];

        // Only links inside variant blocks
        $crawler->filter('div[data-observerid] a[href*="/p/itm"]')
            ->each(function (Crawler $node) use (&$variants) {

                $href = $node->attr('href');
                if (!$href) return;

                if (preg_match('#/p/(itm[a-zA-Z0-9]+)#', $href, $match)) {
                    $variants[] = $match[1];
                }
            });

        $variants = array_values(array_unique($variants));

        return $variants ? implode(',', $variants) : null;
    }



    // Product Attributes
    private function extractBrand(Crawler $crawler): ?string
    {
        $brand = null;

        // NEW Flipkart layout (grid specs)
        $crawler->filter('.grid-formation-dynamic')->each(function (Crawler $row) use (&$brand) {

            if ($brand !== null) {
                return;
            }

            $labelNode = $row->filter('.v1zwn21k')->first();
            $valueNode = $row->filter('.v1zwn21j')->first();

            if (!$labelNode->count() || !$valueNode->count()) {
                return;
            }

            $label = strtolower(trim($labelNode->text()));

            if (str_contains($label, 'brand')) {
                $brand = trim($valueNode->text());
            }
        });

        return $brand;
    }



    private function extractModelName(Crawler $crawler): ?string
    {
        $model = null;

        $crawler->filter('.grid-formation-dynamic')->each(function (Crawler $row) use (&$model) {

            if ($model !== null) {
                return;
            }

            // label = Model Name
            $labelNode = $row->filter('.v1zwn21k')->first();
            if (!$labelNode->count()) {
                return;
            }

            $label = strtolower(trim($labelNode->text()));

            if ($label === 'model name') {

                // value = g06 power
                $valueNode = $row->filter('.v1zwn21j')->first();
                if ($valueNode->count()) {
                    $model = trim($valueNode->text());
                }
            }
        });

        return $model;
    }


    private function extractColour(Crawler $crawler): ?string
    {
        $colour = null;

        $crawler->filter('.grid-formation-dynamic')->each(function (Crawler $row) use (&$colour) {

            if ($colour !== null) {
                return;
            }

            // label node (Color)
            $labelNode = $row->filter('.v1zwn21k')->first();
            if (!$labelNode->count()) {
                return;
            }

            $label = strtolower(trim($labelNode->text()));

            // match color / colour both
            if ($label === 'color' || $label === 'colour') {

                // value node (Pantone Laurel Oak)
                $valueNode = $row->filter('.v1zwn21j')->first();
                if ($valueNode->count()) {
                    $colour = trim($valueNode->text());
                }
            }
        });

        return $colour;
    }





    private function extractItemWeight(Crawler $crawler): ?string
    {
        $weight = null;

        $crawler->filter('table.n7infM tr, table._0ZhAN9 tr')->each(function (Crawler $row) use (&$weight) {
            $cells = $row->filter('td');
            if ($cells->count() >= 2) {
                $label = strtolower(trim($cells->eq(0)->text()));
                if (strpos($label, 'weight') !== false) {
                    // Value inside <ul><li>
                    $weightNode = $cells->eq(1)->filter('li')->first();
                    if ($weightNode->count() > 0) {
                        $weight = trim($weightNode->text());
                    } else {
                        $weight = trim($cells->eq(1)->text());
                    }
                }
            }
        });

        return $weight;
    }

    private function extractProductDimensions(Crawler $crawler): ?string
    {
        $dimensions = [];

        // loop through spec rows
        $crawler->filter('.grid-formation-dynamic')->each(function (Crawler $row) use (&$dimensions) {

            $labelNode = $row->filter('.v1zwn21k')->first();
            $valueNode = $row->filter('.v1zwn21j')->first();

            if (!$labelNode->count() || !$valueNode->count()) {
                return;
            }

            $label = strtolower(trim($labelNode->text()));
            $value = trim($valueNode->text());

            if (str_contains($label, 'height')) {
                $dimensions['height'] = $value;
            } elseif (str_contains($label, 'width')) {
                $dimensions['width'] = $value;
            } elseif (str_contains($label, 'depth') || str_contains($label, 'thickness')) {
                $dimensions['depth'] = $value;
            }
        });

        // order fix (Width x Depth x Height)
        if (!empty($dimensions)) {

            $ordered = [
                $dimensions['width']  ?? null,
                $dimensions['depth']  ?? null,
                $dimensions['height'] ?? null,
            ];

            $ordered = array_filter($ordered);

            return implode(' x ', $ordered);
        }

        return null;
    }


    private function extractManufacturer(Crawler $crawler): ?string
    {
        $manufacturer = null;

        $crawler->filter('.v1zwn21k')->each(function (Crawler $labelNode) use (&$manufacturer) {

            if ($manufacturer !== null) {
                return;
            }

            $label = strtolower(trim($labelNode->text()));

            if (str_contains($label, 'manufacturer')) {

                // value is next sibling with class v1zwn21j
                $valueNode = $labelNode->ancestors()->first()->filter('.v1zwn21j')->first();

                if ($valueNode->count()) {
                    $manufacturer = trim($valueNode->text());
                }
            }
        });

        return $manufacturer ? $this->cleanText($manufacturer) : null;
    }


    private function extractVideoUrls(Crawler $crawler): ?array
    {
        $videoUrls = [];

        /*
        |--------------------------------------------------------------------------
        | 1. Normal video / iframe (fallback – old layouts)
        |--------------------------------------------------------------------------
        */
        $crawler->filter("video source, video, iframe[src*='youtube'], iframe[src*='vimeo']")
            ->each(function (Crawler $node) use (&$videoUrls) {

                $src = $node->attr("src");
                if ($src) {
                    $videoUrls[] = $src;
                }
            });

        /*
        |--------------------------------------------------------------------------
        | 2. NEW Flipkart layout – detect youtube thumbnails
        |--------------------------------------------------------------------------
        | Pattern:
        | https://img.youtube.com/vi/VIDEO_ID/0.jpg
        */
        $crawler->filter('img[src*="img.youtube.com/vi/"]')
            ->each(function (Crawler $img) use (&$videoUrls) {

                $src = $img->attr('src');

                if (!$src) return;

                if (preg_match('~/vi/([^/]+)/~', $src, $m)) {

                    $videoId = $m[1];

                    // Convert to actual video URL
                    $videoUrls[] = "https://www.youtube.com/watch?v=" . $videoId;

                    // optional embed version
                    $videoUrls[] = "https://www.youtube.com/embed/" . $videoId;
                }
            });

        /*
        |--------------------------------------------------------------------------
        | 3. Remove duplicates
        |--------------------------------------------------------------------------
        */
        $videoUrls = array_values(array_unique($videoUrls));

        return !empty($videoUrls) ? $videoUrls : null;
    }


    private function extractCategory(Crawler $crawler): ?string
    {
        $categories = [];

        /*
        |--------------------------------------------------------------------------
        | Breadcrumb links in new Flipkart layout
        |--------------------------------------------------------------------------
        | Container stable hai, classes random hoti hain
        | Isliye anchor based detection use karenge
        */
        $crawler->filter('div.faikzn a')->each(function (Crawler $node) use (&$categories) {

            $text = trim($node->text());

            if ($text !== '') {
                $categories[] = $text;
            }
        });

        /*
        |--------------------------------------------------------------------------
        | Remove "Home"
        |--------------------------------------------------------------------------
        */
        if (!empty($categories) && strtolower($categories[0]) === 'home') {
            array_shift($categories);
        }

        /*
        |--------------------------------------------------------------------------
        | Last element product category hota hai, remove nahi karna
        | (Old logic me last remove karte the — ab nahi)
        |--------------------------------------------------------------------------
        */

        return !empty($categories) ? implode(', ', $categories) : null;
    }


    private function extractSellerName(Crawler $crawler): ?string
    {
        $sellerNode = $crawler->filter('#sellerName span span')->first(); // target inner span with name
        if ($sellerNode->count() > 0) {
            return $this->cleanText($sellerNode->text());
        }

        return null;
    }

    private function extractDeliveryDate(Crawler $crawler): ?string
    {
        
        $node = $crawler->filter('a[href*="delivery-page"]')->first();

        if ($node->count() === 0) {
            return null;
        }


        $text = trim($node->text());

        if (!$text) {
            return null;
        }

        if (preg_match('/(in\s+\d+\s+days?|by\s+[A-Za-z0-9\s]+)/i', $text, $match)) {
            return $this->cleanText($match[0]);
        }

        return $this->cleanText($text);
    }


    private function extractDeliveryPrice(Crawler $crawler): ?string
    {
        // Flipkart often includes delivery charges inside a nearby div or span
        $price = null;

        // Common selector for delivery charge text
        $crawler->filter('div.hVvnXm, div._3XINqE, span._3XINqE')->each(function (Crawler $node) use (&$price) {
            $text = strtolower($node->text());

            // Match lines like "Free delivery" or "₹40 delivery charge"
            if (strpos($text, 'free delivery') !== false) {
                $price = 'Free';
            } elseif (preg_match('/₹\s?\d+/', $text, $matches)) {
                $price = trim($matches[0]);
            }
        });

        return $price ? $this->cleanText($price) : null;
    }

    
    private function extractHighlights(Crawler $crawler): ?string
    {
        $highlights = [];

        /*
        * ✅ NEW Flipkart Highlights (grid cards under "Product highlights")
        * Target only text blocks inside the highlights section
        */
        $crawler->filter('div:contains("Product highlights")')->each(function (Crawler $node) use (&$highlights) {
            // Use filter to find the next sibling container that holds the highlights
            $container = $node->closest('div')->nextAll()->first();
            if ($container->count() > 0) {
                $container->filter('div.v1zwn21j.v1zwn25')->each(function (Crawler $item) use (&$highlights) {
                    $text = trim($item->text());
                    if ($text !== '') {
                        $highlights[] = $text;
                    }
                });
            }
        });

        /*
        * ✅ Previous Flipkart structure
        */
        if (empty($highlights)) {
            $crawler->filter('div.iNKhRz ul li.LS5qY1')->each(function (Crawler $node) use (&$highlights) {
                $text = trim($node->text());
                if ($text !== '') {
                    $highlights[] = $text;
                }
            });
        }

        /*
        * ✅ Older fallback
        */
        if (empty($highlights)) {
            $crawler->filter('div.xFVion ul li._7eSDEz')->each(function (Crawler $node) use (&$highlights) {
                $text = trim($node->text());
                if ($text !== '') {
                    $highlights[] = $text;
                }
            });
        }

        return !empty($highlights) ? implode(' | ', $highlights) : null;
    }





    private function extractTechnicalDetails(Crawler $crawler): ?string
    {
        $value = null;
        $targetLabel =null;
        $crawler->filter('div.grid-formation-dynamic')->each(function (Crawler $block) use (&$value, $targetLabel) {

            $labelNode = $block->filter('div.v1zwn21k')->first();
            $valueNode = $block->filter('div.v1zwn21j.v1zwn26')->first();

            if ($labelNode->count() && $valueNode->count()) {
                $label = strtolower(trim($labelNode->text()));

                if (strpos($label, strtolower($targetLabel)) !== false) {
                    $value = trim($valueNode->text());
                }
            }
        });

        return $value;
    }


    private function extractAdditionalInformation(Crawler $crawler): ?array
    {
        $info = [];

        // Flipkart specification section (table rows)
        $rows = $crawler->filter('div.QZKsWF table.n7infM tr');

        if ($rows->count() == 0) {
            return null;
        }

        $rows->each(function (Crawler $row) use (&$info) {

            // Key (left column)
            $keyNode = $row->filter('td')->eq(0);
            // Value (right column)
            $valueNode = $row->filter('td')->eq(1);

            if ($keyNode->count() == 0 || $valueNode->count() == 0) {
                return;
            }

            $key = trim(preg_replace('/\s+/', ' ', $keyNode->text('')));

            // Value inside li > text
            $li = $valueNode->filter('li')->first();
            $value = $li->count()
                ? trim(preg_replace('/\s+/', ' ', $li->text()))
                : trim(preg_replace('/\s+/', ' ', $valueNode->text()));

            if ($key && $value) {
                $info[$key] = $value;
            }
        });

        return !empty($info) ? $info : null;
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

        // Flipkart rating counts inside ul.lpfPv5
        $nodes = $crawler->filter('ul.lpfPv5 li .MDKzf4');

        if ($nodes->count() >= 5) {

            $values = [];

            $nodes->each(function (Crawler $node) use (&$values) {
                $text = trim($node->text());
                // Clean number (remove comma)
                $num = intval(str_replace(',', '', $text));
                $values[] = $num;
            });

            if (count($values) >= 5) {
                // Already in 5★ → 1★ order
                $ratings['rating_5_star_percent'] = $values[0];
                $ratings['rating_4_star_percent'] = $values[1];
                $ratings['rating_3_star_percent'] = $values[2];
                $ratings['rating_2_star_percent'] = $values[3];
                $ratings['rating_1_star_percent'] = $values[4];
            }
        }

        return $ratings;
    }
   

}
