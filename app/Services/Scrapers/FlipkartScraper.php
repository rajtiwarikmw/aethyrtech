<?php

namespace App\Services\Scrapers;

use App\Services\DataSanitizer;
use Symfony\Component\DomCrawler\Crawler;
use Illuminate\Support\Facades\Log;

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
            'delay_between_pages' => [5, 12], // Increased delays to avoid detection
            'max_consecutive_errors' => 2, // Reduced to trigger browser fallback faster
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
    // public function scrape(array $categoryUrls): array
    // {
    //     $this->stats = [
    //         'products_found' => 0,
    //         'products_updated' => 0,
    //         'products_added' => 0,
    //         'products_deactivated' => 0,
    //         'errors_count' => 0
    //     ];

    //     Log::info("Starting Flipkart scraping with HTTP requests", [
    //         'platform' => $this->platform,
    //         'categories' => count($categoryUrls)
    //     ]);

    //     $httpSuccess = false;

    //     foreach ($categoryUrls as $categoryUrl) {
    //         // Try HTTP first
    //         if ($this->tryHttpScraping($categoryUrl)) {
    //             $httpSuccess = true;
    //         } else {
    //             // If HTTP fails, switch to browser automation
    //             Log::warning("HTTP scraping failed for Flipkart, switching to browser automation");
    //             $this->useJavaScript = true;
    //             $this->scrapeCategoryWithBrowser($categoryUrl);
    //         }

    //         if ($this->isExecutionTimeLimitReached()) {
    //             break;
    //         }
    //     }

    //     return $this->stats;
    // }

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
                'a.VJA3rP', 
                'a.wjcEIp',
                'a.GnxRXv',
                'a.pIpigb',
                'a.fb4uj3'

            ];

            foreach ($selectors as $selector) {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$productUrls) {
                    $href = $node->attr('href');
                    if ($href) {
                        // Convert relative URLs to absolute
                        if (strpos($href, 'http') !== 0) {
                            $href = 'https://www.flipkart.com' . $href;
                        }
                        $href = explode('?', $href)[0];
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

            $data['product_url'] = $productUrl;

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
        if (preg_match('/\/p\/([a-zA-Z0-9]+)/', $url, $matches)) {
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
            // Exact match for your HTML
            'h1._6EBuvT span',
            '.VU-ZEz',
            'h1._6EBuvT',

            // Flipkart common selectors
            '.B_NuCI',
            '._35KyD6',
            '.x2Jnpn',
            '.yhZ1nd',
            'h1.yhZ1nd',
            'span.B_NuCI',

            // Generic
            'h1 span',
            'h1',
            '[class*="title"]',
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


     
    private function extractCurrencyCode(Crawler $crawler): ?string
    {
        // Possible currency symbol nodes (Amazon + Flipkart + fallback)
        $selectors = [
            '.Nx9bqj.CxhGGd',
            '.yRaY8j.A6+E6v'
        ];

        $symbol = null;

        foreach ($selectors as $selector) {
            $node = $crawler->filter($selector)->first();
            if ($node->count() > 0) {
                // Flipkart prices include ₹ in the text, so extract first char
                $text = trim($node->text());
                $symbol = mb_substr($text, 0, 1); 
                break;
            }
        }

        if (!$symbol) {
            return null;
        }

        // Map symbols to ISO currency codes
        $currencyMap = [
            '₹' => 'INR',
            '$' => 'USD',
            '£' => 'GBP',
            '€' => 'EUR',
            '¥' => 'JPY',
            '₩' => 'KRW',
            '₽' => 'RUB',
            '₫' => 'VND',
            '฿' => 'THB',
            '₦' => 'NGN',
        ];

        return $currencyMap[$symbol] ?? $symbol; // fallback to raw symbol
    }


    /**
     * Extract offers and discounts
     */
    private function extractOffers(Crawler $crawler): ?string
    {
        $offers = [];

        // Discount percentage
        $discount = $crawler->filter('.HQe8jr.rASMtN')->first();
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
            '.XQDdHH',
            '.MKiFS6'
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
            '.Wphh3N span',
            '.PvbNMB'
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

        // Flipkart color variants (each li > a)
        $crawler->filter('div.IcJh2W ul.UqCsru li a')->each(function (Crawler $node) use (&$variants) {
            $href = $node->attr('href') ?: '';

            if (empty($href)) {
                return;
            }

            // Match SKU from /p/{sku}
            if (preg_match('#/p/([^/?#]+)#', $href, $m)) {
                $variants[] = $m[1];
            }
            // Or fallback to pid= if structure is different
            elseif (preg_match('/[?&]pid=([^&]+)/', $href, $m2)) {
                $variants[] = $m2[1];
            }
        });

        // Remove duplicates and empty values
        $variants = array_filter(array_unique($variants));

        // Return comma-separated SKUs or null
        return !empty($variants) ? implode(',', $variants) : null;
    }

    // Product Attributes
    private function extractBrand(Crawler $crawler): ?string
    {
        $brand = null;

        // Flipkart product specification table
        $crawler->filter('table.n7infM tr, table._0ZhAN9 tr')->each(function (Crawler $row) use (&$brand) {

            // Get all cells
            $cells = $row->filter('td');
            if ($cells->count() < 2) {
                return;
            }

            // Check first cell for "Brand"
            $label = strtolower(trim($cells->eq(0)->text()));
            if (strpos($label, 'brand') !== false) {

                // New format → <ul><li class="DW2bnL">HP</li></ul>
                $brandNode = $cells->eq(1)->filter('li')->first();
                if ($brandNode->count() > 0) {
                    $brand = trim($brandNode->text());
                    return;
                }

                // Old format fallback → plain text
                $brand = trim($cells->eq(1)->text());
            }
        });

        return $brand;
    }


    private function extractModelName(Crawler $crawler): ?string
    {
        $model = null;

        // Flipkart style table
        $crawler->filter('table.n7infM tr, table._0ZhAN9 tr')->each(function (Crawler $row) use (&$model) {
            $cells = $row->filter('td');
            if ($cells->count() >= 2) {
                $label = strtolower(trim($cells->eq(0)->text()));
                if (strpos($label, 'model name') !== false) {
                    // Model value inside <ul><li>
                    $modelText = $cells->eq(1)->filter('li')->first();
                    if ($modelText->count() > 0) {
                        $model = trim($modelText->text());
                    } else {
                        $model = trim($cells->eq(1)->text());
                    }
                }
            }
        });

        return $model;
    }

    private function extractColour(Crawler $crawler): ?string
    {
        $colourList = [];
        $colour = null;

        try {
            
            $crawler->filter('div.IcJh2W ul.UqCsru li div.wpbaaT')->each(function (Crawler $node) use (&$colourList) {
                $text = trim($node->text());
                if (!empty($text)) {
                    $colourList[] = $text;
                }
            });

            $crawler->filter('div.WGBwfw ul.hSEbzK li .V3Zflw')->each(function (Crawler $node) use (&$colourList) {
                $text = trim($node->text());
                if (!empty($text)) {
                    $colourList[] = $text;
                }
            });
            if (!empty($colourList)) {
                $colour = implode(', ', array_unique($colourList));
            }

            if (!$colour) {
                $crawler->filter('table.n7infM tr, table._0ZhAN9 tr')->each(function (Crawler $row) use (&$colour) {
                    $cells = $row->filter('td');
                    if ($cells->count() >= 2) {
                        $label = strtolower(trim($cells->eq(0)->text()));
                        if (strpos($label, 'color') !== false || strpos($label, 'colour') !== false) {

                            // If inside <ul><li>
                            $liNode = $cells->eq(1)->filter('li')->first();
                            if ($liNode->count() > 0) {
                                $colour = trim($liNode->text());
                            } else {
                                $colour = trim($cells->eq(1)->text());
                            }
                        }
                    }
                });
            }

        } catch (\Exception $e) {
            Log::warning('Colour extraction failed', ['error' => $e->getMessage()]);
        }

        return $colour ? $this->cleanText($colour) : null;
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

        $crawler->filter('table.n7infM tr, table._0ZhAN9 tr')->each(function (Crawler $row) use (&$dimensions) {
            $cells = $row->filter('td');
            if ($cells->count() >= 2) {
                $label = strtolower(trim($cells->eq(0)->text()));
                $valueNode = $cells->eq(1)->filter('li')->first();
                $value = $valueNode->count() > 0 ? trim($valueNode->text()) : trim($cells->eq(1)->text());

                if (strpos($label, 'height') !== false) {
                    $dimensions['height'] = $value;
                } elseif (strpos($label, 'width') !== false) {
                    $dimensions['width'] = $value;
                } elseif (strpos($label, 'depth') !== false) {
                    $dimensions['depth'] = $value;
                }
            }
        });

        return !empty($dimensions) ? implode(' x ', $dimensions) : null; // e.g., "19.7 cm x 37.5 cm x 34.7 cm"
    }

    private function extractManufacturer(Crawler $crawler): ?string
    {
        // Look for <ul><li> containing manufacturer info
        $liNode = $crawler->filter('ul li.H+ugqS')->first();

        if ($liNode->count() > 0) {
            return $this->cleanText($liNode->text());
        }

        return null;
    }

    private function extractVideoUrls(Crawler $crawler): ?array
    {
        $videoUrls = [];
        // Look for video elements on the page
        $crawler->filter("video source, video")->each(function (Crawler $node) use (&$videoUrls) {
            $src = $node->attr("src");
            if ($src) {
                $videoUrls[] = $src;
            }
        });

        // Look for video links in specific sections (e.g., product gallery)
        $crawler->filter("#altImages .videoThumbnail img")->each(function (Crawler $node) use (&$videoUrls) {
            $dataVideoUrl = $node->attr("data-video-url");
            if ($dataVideoUrl) {
                $videoUrls[] = $dataVideoUrl;
            }
        });

        // Look for embedded video iframes (e.g., YouTube)
        $crawler->filter("iframe[src*='youtube.com'], iframe[src*='player.vimeo.com']")->each(function (Crawler $node) use (&$videoUrls) {
            $src = $node->attr("src");
            if ($src) {
                $videoUrls[] = $src;
            }
        });

        return !empty($videoUrls) ? array_unique($videoUrls) : null;
    }

    private function extractCategory(Crawler $crawler): ?string
    {
        $categories = [];

        // Target all breadcrumb links
        $crawler->filter('div._7dPnhA > div.r2CdBx > a.R0cyWM, div.IHPEN6 > a.zEfFJL')->each(function (Crawler $node) use (&$categories) {
            $text = trim($node->text());
            if ($text) {
                $categories[] = $text;
            }
        });

        // Remove first (Home) and last (product) if there are enough items
        if (count($categories) > 2) {
            array_shift($categories); // remove first
            array_pop($categories);   // remove last
        }

        return !empty($categories) ? implode(", ", $categories) : null;
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
        // Flipkart delivery date — inside span.Y8v7Fl
        $node = $crawler->filter('div.hVvnXm span.Y8v7Fl, div.lCyzqo span.fDDafj')->first();

        if ($node->count() > 0) {
            $date = trim($node->text());
            if (!empty($date)) {
                return $this->cleanText($date);
            }
        }

        return null;
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

        // New Flipkart structure
        $crawler->filter('div.iNKhRz ul li.LS5qY1')->each(function (Crawler $node) use (&$highlights) {
            $text = trim($node->text());
            if (!empty($text)) {
                $highlights[] = $text;
            }
        });

        // Old Flipkart Highlights (fallback)
        if (empty($highlights)) {
            $crawler->filter('div.xFVion ul li._7eSDEz')->each(function (Crawler $node) use (&$highlights) {
                $text = trim($node->text());
                if (!empty($text)) {
                    $highlights[] = $text;
                }
            });
        }

        // Additional fallback (rare old pages)
        if (empty($highlights)) {
            $crawler->filter('div._2MYpT0 ul li')->each(function (Crawler $node) use (&$highlights) {
                $text = trim($node->text());
                if (!empty($text)) {
                    $highlights[] = $text;
                }
            });
        }

        return !empty($highlights) ? implode('. ', $highlights) : null;
    }


    private function extractTechnicalDetails(Crawler $crawler): ?array
    {
        $details = [];

        // Flipkart specifications structure
        $selector = 'table.n7infM tr';

        if ($crawler->filter($selector)->count()) {
            $crawler->filter($selector)->each(function (Crawler $node) use (&$details) {

                $key = $node->filter('td')->eq(0)->text('');
                $valueNode = $node->filter('td')->eq(1);

                // Value may be inside <li>
                if ($valueNode->filter('li')->count() > 0) {
                    $value = trim($valueNode->filter('li')->first()->text(''));
                } else {
                    $value = trim($valueNode->text(''));
                }

                // Clean and store
                $key   = trim(preg_replace('/\s+/', ' ', $key));
                $value = trim(preg_replace('/\s+/', ' ', $value));

                if ($key && $value) {
                    $details[$key] = $value;
                }
            });
        }

        return !empty($details) ? $details : null;
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
