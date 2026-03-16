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
        $this->useJavaScript = true; // Use Browsershot as primary mechanism
        $this->paginationConfig = [
            'type' => 'regular',
            'max_pages' => 100,
            'page_param' => 'page',
            'has_next_selector' => '._1LKTO3:last-child:not(._34Gtpf)',
            'max_consecutive_errors' => 500,
            'delay_between_pages' => [4, 8], // Increased delays to avoid rate limiting
            'retry_failed_pages' => true,
            'max_retries_per_page' => 3
        ];

        $this->defaultHeaders = [];
    }

    public function __construct()
    {
        parent::__construct('flipkart');
    }

    /**
     * Override scrape method to use Browsershot as primary mechanism
     * Flipkart has aggressive anti-scraping measures, so we use a real browser
     */
    public function scrape(array $categoryUrls): void
    {
        $this->scrapingLog = \App\Models\ScrapingLog::startSession($this->platform);

        try {
            Log::info("Starting Flipkart scraping with Browsershot", [
                'platform' => $this->platform,
                'categories' => count($categoryUrls)
            ]);

            foreach ($categoryUrls as $categoryUrl) {
                // Use Browsershot directly as primary mechanism
                $this->scrapeCategoryWithBrowser($categoryUrl);

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
     * Override randomDelay with longer delays for Flipkart
     * Flipkart has aggressive rate limiting, so we use longer delays
     */
    protected function randomDelay(): void
    {
        $delay = rand(5, 10);
        Log::debug("Random delay applied for Flipkart", ['delay_seconds' => $delay]);
        sleep($delay);
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
            '.v1zwn21k.v1zwn26',
            '.v1zwn21k.v1zwn26 p'
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

        /*
        * SALE PRICE
        */
        $salePriceSelectors = [
            '.v1zwn21k.v1zwn20',                    // new Flipkart UI
            '[class*="v1zwn21k"][class*="v1zwn20"]',
            '.Nx9bqj.CxhGGd',                      // older UI
            '.hZ3P6w.bnqy13',
            'div[class*="v1zwn21k"][class*="v1zwn20"]',
            'span[class*="Nx9bqj"]'
        ];

        foreach ($salePriceSelectors as $selector) {
            $element = $crawler->filter($selector)->first();

            if ($element->count()) {
                $priceText = $this->cleanText($element->text());
                $price = $this->extractPrice($priceText);

                if ($price) {
                    $prices["sale_price"] = $price;
                    break;
                }
            }
        }

        /*
        * ORIGINAL PRICE (MRP)
        */
        $mrpPriceSelectors = [
            '.v1zwn21l.v1zwn21',                      // new Flipkart UI
            '[class*="v1zwn21l"][style*="line-through"]',
            'div[style*="line-through"]',
            'span[style*="line-through"]',
            '.hl05eU .yRaY8j',                        // older UI
            '.kRYCnD.yHYOcc'
        ];

        foreach ($mrpPriceSelectors as $selector) {
            $element = $crawler->filter($selector)->first();

            if ($element->count()) {
                $priceText = $this->cleanText($element->text());
                $price = $this->extractPrice($priceText);

                if ($price) {
                    $prices["price"] = $price;
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

        $selectors = [
            '.v1zwn21z.v1zwn20',          // new Flipkart discount %
            '[class*="v1zwn21z"][class*="v1zwn20"]',
            'div[class*="v1zwn21"][class*="20"]', // fallback
        ];

        foreach ($selectors as $selector) {

            $elements = $crawler->filter($selector);

            foreach ($elements as $node) {

                $element = new Crawler($node);
                $text = trim($element->text());

                // Only accept values containing %
                if (preg_match('/\d+\s?%/', $text)) {
                    $offers[] = $this->cleanText($text);
                }
            }

            if (!empty($offers)) {
                break;
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

        /*
        * Rating selectors
        */
        $ratingSelectors = [
            '.css-146c3p1',                     // new Flipkart rating
            '.asbjxx .css-146c3p1',
            'div[class*="rating"] span',
            'span[class*="rating"]'
        ];

        foreach ($ratingSelectors as $selector) {
            $elements = $crawler->filter($selector);

            foreach ($elements as $node) {

                $text = trim((new Crawler($node))->text());

                if (preg_match('/^\d\.\d$/', $text)) {
                    $data['rating'] = (float)$text;
                    break 2;
                }
            }
        }

        /*
        * Review selectors
        */
        $reviewSelectors = [
            'a[href*="ratings-reviews"] .css-146c3p1',
            'a[href*="ratings-reviews"]',
            '.css-146c3p1'
        ];

        foreach ($reviewSelectors as $selector) {

            $elements = $crawler->filter($selector);

            foreach ($elements as $node) {

                $text = trim((new Crawler($node))->text());

                if (preg_match('/\|\s*([\d,]+)/', $text, $match)) {
                    $data['review_count'] = (int)str_replace(',', '', $match[1]);
                    break 2;
                }

                if (preg_match('/([\d,]+)\s*Reviews?/', $text, $match)) {
                    $data['review_count'] = (int)str_replace(',', '', $match[1]);
                    break 2;
                }
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

        // lock inside product gallery container
        $crawler->filter('div._1psv1ze36 picture img')->each(function (Crawler $node) use (&$images) {

            $src = $node->attr('src');

            if (!$src) {
                $srcset = $node->attr('srcset');
                if ($srcset) {
                    $parts = explode(',', $srcset);
                    $src = trim(explode(' ', $parts[0])[0]);
                }
            }

            if ($src && str_contains($src, 'rukminim')) {
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

        // New Flipkart layout
        $crawler->filter('.grid-formation-dynamic')->each(function (Crawler $row) use (&$brand) {

            if ($brand !== null) {
                return;
            }

            $labelNode = $row->filter('.v1zwn21l.v1zwn27')->first();
            $valueNode = $row->filter('.v1zwn21k.v1zwn26')->first();

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
            $labelNode = $row->filter('.v1zwn21l.v1zwn27')->first();

            if (!$labelNode->count()) {
                return;
            }

            $label = strtolower(trim($labelNode->text()));

            if ($label === 'model name') {

                // value
                $valueNode = $row->filter('.v1zwn21k.v1zwn26')->first();

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

        // Fallback: Try from specifications table
        if (!$colour) {
            $crawler->filter('table.n7infM tr, table._0ZhAN9 tr')->each(function (Crawler $row) use (&$colour) {
                if ($colour !== null) return;
                $cells = $row->filter('td');
                if ($cells->count() >= 2) {
                    $label = strtolower(trim($cells->eq(0)->text()));
                    if (strpos($label, 'color') !== false || strpos($label, 'colour') !== false) {
                        $colour = trim($cells->eq(1)->text());
                    }
                }
            });
        }

        return $colour;
    }





    private function extractItemWeight(Crawler $crawler): ?string
    {
        $weight = null;

        // New Flipkart layout
        $crawler->filter('.grid-formation-dynamic')->each(function (Crawler $row) use (&$weight) {

            if ($weight !== null) {
                return;
            }

            $labelNode = $row->filter('.v1zwn21l.v1zwn27')->first();
            $valueNode = $row->filter('.v1zwn21k.v1zwn26')->first();

            if (!$labelNode->count() || !$valueNode->count()) {
                return;
            }

            $label = strtolower(trim($labelNode->text()));

            if (strpos($label, 'weight') !== false) {
                $weight = trim($valueNode->text());
            }
        });

        // Fallback: old Flipkart specification table
        if (!$weight) {
            $crawler->filter('table.n7infM tr, table._0ZhAN9 tr')->each(function (Crawler $row) use (&$weight) {

                if ($weight !== null) return;

                $cells = $row->filter('td');

                if ($cells->count() >= 2) {

                    $label = strtolower(trim($cells->eq(0)->text()));

                    if (strpos($label, 'weight') !== false) {

                        $weightNode = $cells->eq(1)->filter('li')->first();

                        if ($weightNode->count()) {
                            $weight = trim($weightNode->text());
                        } else {
                            $weight = trim($cells->eq(1)->text());
                        }
                    }
                }
            });
        }

        return $weight;
    }

    private function extractProductDimensions(Crawler $crawler): ?string
    {
        $dimensions = [];

        // loop through spec rows
        $crawler->filter('.grid-formation-dynamic')->each(function (Crawler $row) use (&$dimensions) {

            $labelNode = $row->filter('.v1zwn21l.v1zwn27')->first();
            $valueNode = $row->filter('.v1zwn21k.v1zwn26')->first();

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

        $crawler->filter('.grid-formation-dynamic')->each(function (Crawler $row) use (&$manufacturer) {

            if ($manufacturer !== null) {
                return;
            }

            $labelNode = $row->filter('.v1zwn21l.v1zwn27')->first();
            $valueNode = $row->filter('.v1zwn21k.v1zwn26')->first();

            if (!$labelNode->count() || !$valueNode->count()) {
                return;
            }

            $label = strtolower(trim($labelNode->text()));

            if (str_contains($label, 'manufacturer')) {
                $manufacturer = trim($valueNode->text());
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
        $node = $crawler->filter('.v1zwn21k.v1zwn25')->first();

        if (!$node->count()) {
            return null;
        }

        $text = trim($node->text());

        // remove "Fulfilled by"
        $text = preg_replace('/Fulfilled by/i', '', $text);

        return $text ? $this->cleanText(trim($text)) : null;
    }

    private function extractDeliveryDate(Crawler $crawler): ?string
    {
        // delivery block inside delivery-page link
        $node = $crawler->filter('a[href*="delivery-page"] .v1zwn21k.v1zwn24')->last();

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
        * New Flipkart UI (2025+)
        */
        $crawler->filter('div.grid-formation')->each(function (Crawler $node) use (&$highlights) {

            $title = $node->filter('.v1zwn21l.v1zwn27')->count() 
                ? trim($node->filter('.v1zwn21l.v1zwn27')->text()) 
                : '';

            $desc = $node->filter('.v1zwn21k.v1zwn25')->count() 
                ? trim($node->filter('.v1zwn21k.v1zwn25')->text()) 
                : '';

            $text = trim($title . ' ' . $desc);

            if ($text !== '') {
                $highlights[] = $text;
            }
        });

        /*
        * Previous Flipkart structure
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
        * Older fallback
        */
        if (empty($highlights)) {
            $crawler->filter('div.xFVion ul li._7eSDEz')->each(function (Crawler $node) use (&$highlights) {
                $text = trim($node->text());
                if ($text !== '') {
                    $highlights[] = $text;
                }
            });
        }

        $highlights = array_unique($highlights);

        return !empty($highlights) ? implode(' | ', $highlights) : null;
    }





    private function extractTechnicalDetails(Crawler $crawler): ?string
    {
        $details = [];

        // Extract from new Flipkart specification layout
        $crawler->filter('div.grid-formation-dynamic')->each(function (Crawler $block) use (&$details) {

            $labelNode = $block->filter('div.v1zwn21l.v1zwn27')->first();
            $valueNode = $block->filter('div.v1zwn21k.v1zwn26')->first();

            if ($labelNode->count() && $valueNode->count()) {

                $label = trim($labelNode->text());
                $value = trim($valueNode->text());

                if ($label && $value) {
                    $details[] = $label . ': ' . $value;
                }
            }
        });

        // Fallback for old Flipkart specification table
        if (empty($details)) {

            $crawler->filter('table.n7infM tr, table._0ZhAN9 tr')->each(function (Crawler $row) use (&$details) {

                $cells = $row->filter('td');

                if ($cells->count() >= 2) {

                    $label = trim($cells->eq(0)->text());
                    $value = trim($cells->eq(1)->text());

                    if ($label && $value) {
                        $details[] = $label . ': ' . $value;
                    }
                }
            });
        }

        return !empty($details) ? implode(' | ', $details) : null;
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
