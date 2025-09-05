<?php

namespace App\Services\Scrapers;

use App\Services\DataSanitizer;
use Symfony\Component\DomCrawler\Crawler;
use Illuminate\Support\Facades\Log;

class AmazonScraper extends BaseScraper
{
    protected function setupPlatformConfig(): void
    {
        $this->platform = 'amazon';
        $this->useJavaScript = false; // Amazon works with regular HTTP requests
        $this->paginationConfig = [
            'type' => 'regular',
            'max_pages' => 150, // Increased from 100 to handle more pages
            'page_param' => 'page',
            'has_next_selector' => '.a-pagination .a-last:not(.a-disabled)',
            'max_consecutive_errors' => 5, // Allow more errors before stopping
            'delay_between_pages' => [3, 7], // Longer delays to avoid rate limiting
            'retry_failed_pages' => true,
            'max_retries_per_page' => 3
        ];
    }

    public function __construct()
    {
        parent::__construct('amazon');
    }

    /**
     * Extract product URLs from Amazon search/category page
     */
    protected function extractProductUrls(Crawler $crawler, string $categoryUrl): array
    {
        $productUrls = [];

        try {
            // Amazon product links patterns
            $selectors = [
                'div[data-cy="title-recipe"] > a'
            ];

            foreach ($selectors as $selector) {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$productUrls) {
                    $href = $node->attr('href');
                    if ($href) {
                        // Convert relative URLs to absolute
                        if (strpos($href, 'http') !== 0) {
                            $href = 'https://www.amazon.in' . $href;
                        }

                        // Only include product pages
                        if (strpos($href, '/dp/') !== false || strpos($href, '/product/') !== false) {
                            $productUrls[] = $href;
                        }
                    }
                });
            }

            // Remove duplicates and limit results
            $productUrls = array_unique($productUrls);
            $productUrls = array_slice($productUrls, 0, 50); // Limit to 50 products per page

            Log::info("Extracted {count} product URLs from Amazon category page", [
                'count' => count($productUrls),
                'category_url' => $categoryUrl
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to extract product URLs from Amazon", [
                'error' => $e->getMessage(),
                'category_url' => $categoryUrl
            ]);
        }

        return $productUrls;
    }

    /**
     * Extract product data from Amazon product page
     */
    protected function extractProductData(Crawler $crawler, string $productUrl): array
    {
        try {
            $data = [];

            // Extract SKU from URL
            $data["sku"] = $this->extractSkuFromUrl($productUrl);
            if (!$data["sku"]) {
                Log::warning("Could not extract SKU from Amazon URL: {$productUrl}");
                return [];
            }

            $data["product_url"] = $productUrl;
            $data["asin"] = $data["sku"]; // ASIN is the SKU for Amazon

            // Product Attributes
            $data["title"] = $this->extractProductName($crawler);
            $data["description"] = $this->extractDescription($crawler);
            $data["brand"] = $this->extractBrand($crawler);
            $data["model_name"] = $this->extractModelName($crawler);
            $data["color"] = $this->extractColour($crawler);
            $data["weight"] = $this->extractItemWeight($crawler);
            $data["dimensions"] = $this->extractProductDimensions($crawler);
            $data["manufacturer"] = $this->extractManufacturer($crawler);
            $data["image_urls"] = $this->extractImages($crawler); // Renamed from image_urls
            $data["video_urls"] = $this->extractVideoUrls($crawler);
            $data["category"] = $this->extractCategory($crawler);
            $data["bsr"] = $this->extractBSR($crawler);
            $data["release_date"] = $this->extractReleaseDate($crawler);
            $data["technical_details"] = $this->extractTechnicalDetails($crawler);
            $data["additional_information"] = $this->extractAdditionalInformation($crawler);

            // Amazon Price Attributes
            $priceData = $this->extractPrices($crawler);
            $data["price"] = $priceData["price"];
            $data["sale_price"] = $priceData["sale_price"];
            $data["currency_code"] = $this->extractCurrencyCode($crawler);


            // Ratings Attributes
            $ratingData = $this->extractRatingAndReviews($crawler);
            $data["rating"] = $ratingData["rating"];
            $data["review_count"] = $ratingData["review_count"];

            // product Specific Specs (from previous implementation, ensure mapping)
            $specs = $this->extractSpecifications($crawler); // This extracts generic product specs
            $data = array_merge($data, $specs);

            $data["offers"] = $this->extractOffers($crawler); // Generic offers string
            $data["inventory_status"] = $this->extractAvailability($crawler);
            $data["amazon_choice"] = $this->extractBestSellerBadge($crawler);
            $data["bestseller"] = $this->extractAmazonsChoiceBadge($crawler);

            $data["variation_attributes"] = $this->extractVariationAttributes($crawler);

            // Sanitize all data
            $data = DataSanitizer::sanitizeProductData($data);

            Log::debug("Extracted Amazon product data", [
                "sku" => $data["sku"],
                "title" => $data["title"] ?? "N/A"
            ]);

            return $data;
        } catch (\Exception $e) {
            Log::error("Failed to extract Amazon product data", [
                "url" => $productUrl,
                "error" => $e->getMessage(),
                "trace" => $e->getTraceAsString()
            ]);
            return [];
        }
    }

    /**
     * Extract SKU from Amazon URL
     */
    private function extractSkuFromUrl(string $url): ?string
    {
        // Amazon ASIN pattern
        if (preg_match('/\/dp\/([A-Z0-9]{10})/', $url, $matches)) {
            return $matches[1];
        }

        if (preg_match('/\/product\/([A-Z0-9]+)/', $url, $matches)) {
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
            '#productTitle',                   // Amazon’s main ID
            'h1#title span#productTitle',      // More explicit fallback
            '.product-title',
            'h1.a-size-large span'
        ];

        foreach ($selectors as $selector) {
            $element = $crawler->filter($selector);
            if ($element->count() > 0) {
                $text = $this->cleanText($element->text());

                // Remove duplicate whitespace & newlines
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

        // Feature bullets
        $crawler->filter('#feature-bullets ul li span')->each(function (Crawler $node) use (&$descriptions) {
            $text = $this->cleanText($node->text());
            if ($text && strlen($text) > 10) {
                $descriptions[] = $text;
            }
        });

        // Product description
        $productDesc = $crawler->filter('#productDescription p')->first();
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

        // Current price (sale price)
        $salePriceSelectors = [
            ".a-price-whole", // Main price
            "#priceblock_dealprice",
            "#priceblock_ourprice",
            ".a-price-current .a-offscreen",
            ".a-price .a-offscreen"
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
            ".a-price.a-text-price .a-offscreen", // Strikethrough price
            "#priceblock_listprice",
            ".a-text-strike"
        ];

        foreach ($mrpPriceSelectors as $selector) {
            $element = $crawler->filter($selector)->first();
            if ($element->count() > 0) {
                $priceText = $this->cleanText($element->text());
                $price = $this->extractPrice($priceText);
                if ($price) {
                    $prices["price"] = $price; // This is the regular price
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
    
    private function extractCurrencyCode(Crawler $crawler): ?string
    {
        // Target the price symbol inside corePriceDisplay or priceToPay
        $priceSymbolNode = $crawler->filter('#corePriceDisplay_desktop_feature_div .a-price-symbol')->first();

        if ($priceSymbolNode->count() > 0) {
            $symbol = trim($priceSymbolNode->text());

            // Map common symbols to ISO currency codes
            $currencyMap = [
                '₹' => 'INR',
                '$' => 'USD',
                '£' => 'GBP',
                '€' => 'EUR',
                '¥' => 'JPY',
                '₩' => 'KRW',
            ];

            return $currencyMap[$symbol] ?? $symbol; // fallback to symbol if unknown
        }

        return null;
    }

    /**
     * Extract offers and discounts
     */
    private function extractOffers(Crawler $crawler): ?string
    {
        $offers = [];

        // Discount percentage
        $discount = $crawler->filter('.savingsPercentage')->first();
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
            '#availability span',
            '#availability .a-color-success',
            '#availability .a-color-state',
            '.a-color-price'
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
            '.a-icon-alt',
            '[data-hook="average-star-rating"] .a-icon-alt',
            '.a-star-medium .a-icon-alt'
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
            '[data-hook="total-review-count"]',
            '#acrCustomerReviewText',
            '.a-link-normal'
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
     * Extract technical specifications
     */
    private function extractSpecifications(Crawler $crawler): array
    {
        $specs = [];

        // Extract from tech specs table
        $crawler->filter('#productDetails_techSpec_section_1 tr, #productDetails_detailBullets_sections1 tr')->each(function (Crawler $row) use (&$specs) {
            $cells = $row->filter('td');
            if ($cells->count() >= 2) {
                $label = strtolower($this->cleanText($cells->eq(0)->text()));
                $value = $this->cleanText($cells->eq(1)->text());

                if (strpos($label, 'color') !== false || strpos($label, 'colour') !== false) {
                    $specs['color'] = $value;
                }
            }
        });

        // Extract from feature bullets if specs table not found
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

        // 1. Main product image(s)
        $crawler->filter('#landingImage, #imgTagWrapperId img, .a-dynamic-image')->each(function (Crawler $node) use (&$images) {
            $src = $node->attr('data-old-hires') ?: $node->attr('data-src') ?: $node->attr('src');
            if ($src && strpos($src, 'http') === 0) {
                $images[] = $src;
            }
        });

        // 2. Gallery images under main image container
        $crawler->filter('div#main-image-container ul.a-unordered-list li.image.item img')->each(function (Crawler $node) use (&$images) {
            $src = $node->attr('data-old-hires') ?: $node->attr('data-src') ?: $node->attr('src');
            if ($src && strpos($src, 'http') === 0) {
                $images[] = $src;
            }
        });

        // 3. Alternative thumbnails
        $crawler->filter('.imageThumb img, .a-button-thumbnail img')->each(function (Crawler $node) use (&$images) {
            $src = $node->attr('data-old-hires') ?: $node->attr('data-src') ?: $node->attr('src');
            if ($src && strpos($src, 'http') === 0) {
                $images[] = $src;
            }
        });

        // Return unique image URLs, or null if empty
        return !empty($images) ? array_values(array_unique($images)) : null;
    }




    /**
     * ===================================================================
     * Comprehensive Data Extraction Methods
     * ===================================================================
     */

    // Product Attributes
    private function extractBrand(Crawler $crawler): ?string
    {

        $row = $crawler->filter('tr.po-brand');
        if ($row->count() > 0) {
            $cells = $row->filter('td');
            if ($cells->count() >= 2) {
                return $this->cleanText($cells->eq(1)->text());
            }
        }

        return null;
    }
    private function extractModelName(Crawler $crawler): ?string
    {
        $row = $crawler->filter('tr.po-model_name');
        if ($row->count() > 0) {
            $cells = $row->filter('td');
            if ($cells->count() >= 2) {
                return $this->cleanText($cells->eq(1)->text());
            }
        }

        return null;
    }
    private function extractColour(Crawler $crawler): ?string
    {
        $row = $crawler->filter('tr.po-color');
        if ($row->count() > 0) {
            $cells = $row->filter('td');
            if ($cells->count() >= 2) {
                return $this->cleanText($cells->eq(1)->text());
            }
        }

        return null;
    }
    private function extractItemWeight(Crawler $crawler): ?string
    {
        $row = $crawler->filter('tr.po-item_weight');
        if ($row->count() > 0) {
            $cells = $row->filter('td');
            if ($cells->count() >= 2) {
                return $this->cleanText($cells->eq(1)->text());
            }
        }

        return null;
    }
    private function extractProductDimensions(Crawler $crawler): ?string
    {
        $row = $crawler->filter('tr.po-item_depth_width_height');
        if ($row->count() > 0) {
            $cells = $row->filter('td');
            if ($cells->count() >= 2) {
                return $this->cleanText($cells->eq(1)->text());
            }
        }

        return null;
    }




    private function extractManufacturer(Crawler $crawler): ?string
    {
        $row = $crawler->filter('#productDetails_techSpec_section_1 tr')->reduce(function (Crawler $row) {
            $header = trim($row->filter('th')->text(''));
            return stripos($header, 'Manufacturer') !== false;
        })->first();

        if ($row->count() > 0) {
            return $this->cleanText($row->filter('td')->text(''));
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
        $selectors = [
            "#wayfinding-breadcrumbs_feature_div ul li a", //  Matches the Amazon breadcrumb links
            "#nav-subnav a",
            ".a-breadcrumb-text"
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

        return !empty($categories) ? implode(" > ", $categories) : null;
    }

    private function extractBSR(Crawler $crawler): ?int
    {
        $selectors = [
            '#SalesRank .value',
            '#productDetails_detailBullets_sections1 li:contains("Best Sellers Rank")',
            '#detailBullets_feature_div li:contains("Best Sellers Rank")'
        ];

        foreach ($selectors as $selector) {
            $element = $crawler->filter($selector)->first();
            if ($element->count() > 0) {
                $text = $this->cleanText($element->text());
                if (preg_match('/#([\d,]+)/', $text, $matches)) {
                    return (int) str_replace(',', '', $matches[1]);
                }
            }
        }

        return null;
    }
    private function extractReleaseDate(Crawler $crawler): ?string
    {
        $selectors = [
            '#productDetails_techSpec_section_1 tr:contains("Date First Available") td:nth-child(2)',
            '#detailBullets_feature_div li:contains("Date First Available")',
            '#productDetails_techSpec_section_1 tr:contains("Release date") td:nth-child(2)',
            '#detailBullets_feature_div li:contains("Release date")'
        ];

        foreach ($selectors as $selector) {
            $element = $crawler->filter($selector)->first();
            if ($element->count() > 0) {
                $text = $this->cleanText($element->text());
                // Attempt to parse various date formats
                if (preg_match('/\b(\d{1,2}\s(?:January|February|March|April|May|June|July|August|September|October|November|December)\s\d{4})\b/i', $text, $matches)) {
                    return date('Y-m-d', strtotime($matches[1]));
                } elseif (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $text, $matches)) {
                    return $matches[1];
                } elseif (preg_match('/\b(?:January|February|March|April|May|June|July|August|September|October|November|December)\s\d{1,2},\s\d{4}\b/', $text, $matches)) {
                    return date('Y-m-d', strtotime($matches[0]));
                }
            }
        }

        return null;
    }
    
    
    private function extractTechnicalDetails(Crawler $crawler): ?array
    {
        $details = [];

        if ($crawler->filter('#productDetails_techSpec_section_1 tr')->count()) {
            $crawler->filter('#productDetails_techSpec_section_1 tr')->each(function (Crawler $node) use (&$details) {
                $key = trim(preg_replace('/\s+/', ' ', $node->filter('th')->text('')));
                $value = trim(preg_replace('/\s+/', ' ', $node->filter('td')->text('')));
                
                // Normalize keys & values
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

        if ($crawler->filter('#productDetails_detailBullets_sections1 tr')->count()) {
            $crawler->filter('#productDetails_detailBullets_sections1 tr')->each(function (Crawler $node) use (&$info) {
                $key = trim(preg_replace('/\s+/', ' ', $node->filter('th')->text('')));
                
                // Extract value: strip tags but keep text inside links/lists
                $value = trim(preg_replace('/\s+/', ' ', $node->filter('td')->text('')));
                
                if ($key && $value) {
                    $info[$key] = $value;
                }
            });
        }

        return !empty($info) ? $info : null;
    }




    private function extractVariationAttributes(Crawler $crawler): ?array
    {
        $attributes = [];

        // Color / Size swatches
        $crawler->filter('#inline-twister-expander-content-color_name li.dimension-value-list-item-square-image, #variation_size_name li.swatch-variation')->each(function (Crawler $node) use (&$attributes) {

            $name = null;
            $image = null;
            $asin = null;

            // ASIN
            $asin = $node->attr('data-asin') ?: null;

            // Variation value from the swatch text
            $nameNode = $node->filter('.a-button-text');
            if ($nameNode->count() > 0) {
                $name = $this->cleanText($nameNode->text());
            }

            // Swatch image
            $imgNode = $node->filter('img');
            if ($imgNode->count() > 0) {
                $image = $imgNode->attr('src') ?: $imgNode->attr('data-src');
            }

            if ($name) {
                $attributes[] = [
                    'name' => $name,
                    'image' => $image,
                    'asin' => $asin
                ];
            }
        });

        return !empty($attributes) ? $attributes : null;
    }


    private function extractIsPrime(Crawler $crawler): bool
    {
        if (
            $crawler->filter(".a-icon-prime, #prime-badge")->count() > 0 ||
            strpos($crawler->html(), "Prime") !== false
        ) {
            return true;
        }
        return false;
    }

    private function extractAmazonsChoiceBadge(Crawler $crawler): ?string
    {
        $badge = $crawler->filter('div.mvt-ac-badge-wrapper span.mvt-ac-badge-rectangle span.a-size-small')->first();

        if ($badge->count()) {
            return trim($badge->text());
        }

        return null;
    }
    private function extractBestSellerBadge(Crawler $crawler): ?string
    {
        $badge = $crawler->filter('div.zg-bf-badge-wrapper a.badge-link span.mvt-best-seller-badge')->first();
        
        if ($badge->count()) {
            return trim($badge->text());
        }

        return null;
    }

    

}
