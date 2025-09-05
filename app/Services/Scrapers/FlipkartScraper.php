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
    public function scrape(array $categoryUrls): array
    {
        $this->stats = [
            'products_found' => 0,
            'products_updated' => 0,
            'products_added' => 0,
            'products_deactivated' => 0,
            'errors_count' => 0
        ];

        Log::info("Starting Flipkart scraping with HTTP requests", [
            'platform' => $this->platform,
            'categories' => count($categoryUrls)
        ]);

        $httpSuccess = false;

        foreach ($categoryUrls as $categoryUrl) {
            // Try HTTP first
            if ($this->tryHttpScraping($categoryUrl)) {
                $httpSuccess = true;
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

        return $this->stats;
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
                '._1fQZEK a',
                '._4rR01T a',
                '[data-id] a',
                '.s1Q9rs a',
                '._2kHMtA a'
            ];

            foreach ($selectors as $selector) {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$productUrls) {
                    $href = $node->attr('href');
                    if ($href) {
                        // Convert relative URLs to absolute
                        if (strpos($href, 'http') !== 0) {
                            $href = 'https://www.flipkart.com' . $href;
                        }

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

            // Prices
            $priceData = $this->extractPrices($crawler);
            $data['price'] = $priceData['price'];
            $data['sale_price'] = $priceData['sale_price'];

            // Offers
            $data['offers'] = $this->extractOffers($crawler);

            // Availability
            $data['inventory_status'] = $this->extractAvailability($crawler);

            // Rating and reviews
            $ratingData = $this->extractRatingAndReviews($crawler);
            $data['rating'] = $ratingData['rating'];
            $data['review_count'] = $ratingData['review_count'];

            // Brand and model
            $brandData = $this->extractBrandAndModel($crawler);
            $data['brand'] = $brandData['brand'];
            $data['model_name'] = $brandData['model'];

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
            '.B_NuCI',
            '._35KyD6',
            '.x2Jnpn',
            'h1 span',
            '.yhZ1nd'
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
     * Extract product description
     */
    private function extractDescription(Crawler $crawler): ?string
    {
        $descriptions = [];

        // Key features
        $crawler->filter('._1mXcCf li, ._3k-BhJ li')->each(function (Crawler $node) use (&$descriptions) {
            $text = $this->cleanText($node->text());
            if ($text && strlen($text) > 10) {
                $descriptions[] = $text;
            }
        });

        // Product description
        $productDesc = $crawler->filter('._1mXcCf, ._3WHvuP')->first();
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
        $prices = ['price' => null, 'sale_price' => null];

        // Current price selectors
        $priceSelectors = [
            '._30jeq3._16Jk6d',
            '._1_WHN1',
            '._3I9_wc._2p6lqe',
            '._25b18c'
        ];

        foreach ($priceSelectors as $selector) {
            $element = $crawler->filter($selector)->first();
            if ($element->count() > 0) {
                $price = $this->extractPrice($element->text());
                if ($price) {
                    $prices['sale_price'] = $price;
                    break;
                }
            }
        }

        // Original price (if on sale)
        $originalPriceSelectors = [
            '._3I9_wc._27UcVY',
            '._14Nx8E',
            '.yRaY8j'
        ];

        foreach ($originalPriceSelectors as $selector) {
            $element = $crawler->filter($selector)->first();
            if ($element->count() > 0) {
                $price = $this->extractPrice($element->text());
                if ($price) {
                    $prices['price'] = $price;
                    break;
                }
            }
        }

        // If no original price found, use sale price as regular price
        if (!$prices['price'] && $prices['sale_price']) {
            $prices['price'] = $prices['sale_price'];
            $prices['sale_price'] = null;
        }

        return $prices;
    }

    /**
     * Extract offers and discounts
     */
    private function extractOffers(Crawler $crawler): ?string
    {
        $offers = [];

        // Discount percentage
        $discount = $crawler->filter('._3Ay6Sb span')->first();
        if ($discount->count() > 0) {
            $offers[] = $this->cleanText($discount->text());
        }

        // Special offers
        $crawler->filter('._3j4Zjq, ._16FRp0, .yN+eNk')->each(function (Crawler $node) use (&$offers) {
            $text = $this->cleanText($node->text());
            if ($text) {
                $offers[] = $text;
            }
        });

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

        // Rating
        $ratingSelectors = [
            '._3LWZlK',
            '._1lRcqv',
            '.hGSR34'
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
            '._2_R_DZ',
            '._13vcmD',
            '.row._2afbiS'
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
     * Extract brand and model
     */
    private function extractBrandAndModel(Crawler $crawler): array
    {
        $data = ['brand' => null, 'model' => null];

        // Try to extract from product title
        $title = $this->extractProductName($crawler);
        if ($title) {
            // Common Product brands
            $brands = ['HP', 'Dell', 'Lenovo', 'ASUS', 'Acer', 'Apple', 'MSI', 'Samsung', 'LG', 'Sony', 'Toshiba'];

            foreach ($brands as $brand) {
                if (stripos($title, $brand) !== false) {
                    $data['brand'] = $brand;
                    break;
                }
            }
        }

        // Try to extract from specifications table
        $crawler->filter('._1s_Smc tr, ._21lJbe tr')->each(function (Crawler $row) use (&$data) {
            $cells = $row->filter('td');
            if ($cells->count() >= 2) {
                $label = $this->cleanText($cells->eq(0)->text());
                $value = $this->cleanText($cells->eq(1)->text());

                if (stripos($label, 'brand') !== false) {
                    $data['brand'] = $value;
                }
                if (stripos($label, 'model') !== false) {
                    $data['model'] = $value;
                }
            }
        });

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

        // Main product images
        $crawler->filter('._396cs4 img, ._2r_T1I img, .q6DClP img')->each(function (Crawler $node) use (&$images) {
            $src = $node->attr('src') ?: $node->attr('data-src');
            if ($src && strpos($src, 'http') === 0) {
                // Convert to higher resolution if possible
                $src = str_replace('/128/128/', '/832/832/', $src);
                $src = str_replace('/200/200/', '/832/832/', $src);
                $images[] = $src;
            }
        });

        // Alternative image selectors
        if (empty($images)) {
            $crawler->filter('._2_AcLJ img, ._20Gt85 img')->each(function (Crawler $node) use (&$images) {
                $src = $node->attr('src') ?: $node->attr('data-src');
                if ($src && strpos($src, 'http') === 0) {
                    $images[] = $src;
                }
            });
        }

        return !empty($images) ? array_unique($images) : null;
    }

    /**
     * Extract product variants
     */
    private function extractVariants(Crawler $crawler): ?array
    {
        $variants = [];

        // Color variants
        $crawler->filter('._1KOMV6 li, ._3V2wfe li')->each(function (Crawler $node) use (&$variants) {
            $title = $node->attr('title') ?: $node->text();
            if ($title) {
                $variants[] = ['type' => 'color', 'value' => $this->cleanText($title)];
            }
        });

        // Size/configuration variants
        $crawler->filter('._21Ahn- li, ._1fGeJ5 li')->each(function (Crawler $node) use (&$variants) {
            $text = $this->cleanText($node->text());
            if ($text) {
                $variants[] = ['type' => 'configuration', 'value' => $text];
            }
        });

        return !empty($variants) ? $variants : null;
    }
}
