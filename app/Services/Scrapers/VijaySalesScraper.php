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
            'max_pages' => 100,
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
                '.product-item-link',
                '.product-name a',
                '.item-title a',
                '.product-title a',
                '.product-item .product-item-photo a',
                '.product-item-info a',
                '.product-item-details a',
                '.item-info a',
                '.product-card a',
                '.product-wrapper a',
                'a[href*="/product/"]',
                'a[href*="/p/"]',
                '.item a',
                '.product a',
                '.listing-item a',
                '.grid-item a'
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
            $data['sku'] = $this->extractSkuFromUrl($productUrl) ?: $this->extractSkuFromPage($crawler);
            if (!$data['sku']) {
                Log::warning("Could not extract SKU from VijaySales URL: {$productUrl}");
                return [];
            }

            $data['product_url'] = $productUrl;
            $data['title'] = $this->extractProductName($crawler);
            $data['description'] = $this->extractDescription($crawler);
            $data["brand"] = $this->extractBrand($crawler);
            $data["model_name"] = $this->extractModelName($crawler);
            $data['highlights'] = $this->extractHighlights($crawler);
            $data["technical_details"] = $this->extractTechnicalDetails($crawler);            

            $priceData = $this->extractPrices($crawler);
            $data['price'] = $priceData['price'];
            $data['sale_price'] = $priceData['sale_price'];

            $data['offers'] = $this->extractOffers($crawler);
            $data['inventory_status'] = $this->extractAvailability($crawler);

            $ratingData = $this->extractRatingAndReviews($crawler);
            $data['rating'] = $ratingData['rating'];
            $data['review_count'] = $ratingData['review_count'];

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
        if (preg_match('/\/([a-zA-Z0-9\-]+)\.html/', $url, $matches)) {
            return $matches[1];
        }
        return null;
    }

    private function extractSkuFromPage(Crawler $crawler): ?string
    {
        $selectors = [
            '[data-product-id]',
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
        $prices = ['price' => null, 'sale_price' => null];

        // Sale price (current price shown on site) - try multiple selectors
        $priceSelectors = [
            '.product__price--offer-wrapper .product__price--price[data-final-price]',
            '.product__price--price[data-final-price]',
            '[data-final-price]',
            '.product__price--price',
            '.price-final',
            '.final-price',
            '.offer-price',
            '.selling-price',
            '[class*="price"][class*="offer"]',
            '[class*="final"][class*="price"]',
        ];

        foreach ($priceSelectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $text = $element->first()->text();
                    $price = $this->extractPrice($text);
                    if ($price && $price > 0) {
                        $prices['sale_price'] = $price;
                        Log::debug("Extracted VijaySales sale price using selector: {$selector}", ['price' => $price]);
                        break;
                    }
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        // Original MRP price - try multiple selectors
        $originalPriceSelectors = [
            '.product__price--offer-wrapper .product__price--mrp span[data-mrp]',
            '.product__price--mrp span[data-mrp]',
            '[data-mrp]',
            '.product__price--mrp',
            '.price-mrp',
            '.mrp-price',
            '.original-price',
            '[class*="mrp"]',
            '[class*="original"][class*="price"]',
        ];

        foreach ($originalPriceSelectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $text = $element->first()->text();
                    $price = $this->extractPrice($text);
                    if ($price && $price > 0) {
                        $prices['price'] = $price;
                        Log::debug("Extracted VijaySales MRP using selector: {$selector}", ['price' => $price]);
                        break;
                    }
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        // If we only got sale_price, use it as price too
        if (!$prices['price'] && $prices['sale_price']) {
            $prices['price'] = $prices['sale_price'];
        }

        // If we only got price, use it as sale_price too
        if (!$prices['sale_price'] && $prices['price']) {
            $prices['sale_price'] = $prices['price'];
        }

        if (!$prices['price'] && !$prices['sale_price']) {
            Log::warning("Failed to extract VijaySales prices with any selector");
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
        $availabilitySelectors = [
            '.stock-status',
            '.availability',
            '.in-stock',
            '.out-of-stock'
        ];

        foreach ($availabilitySelectors as $selector) {
            $element = $crawler->filter($selector)->first();
            if ($element->count() > 0) {
                return $this->cleanText($element->text());
            }
        }

        return 'In Stock';
    }

    private function extractRatingAndReviews(Crawler $crawler): array
    {
        $data = ['rating' => null, 'review_count' => 0];

        // Extract rating from data-rating-summary
        $ratingElement = $crawler->filter('.product__title--reviews-star')->first();
        if ($ratingElement->count() > 0) {
            $data['rating'] = (float) $ratingElement->attr('data-rating-summary');
        }

        // Extract review count from span text
        $reviewElement = $crawler->filter('.product__title--stats span')->first();
        if ($reviewElement->count() > 0) {
            $data['review_count'] = $this->extractReviewCount($reviewElement->text());
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
    
    private function extractHighlights(Crawler $crawler): ?string
    {
        $highlights = [];

        // Loop through each <li> inside the key features list
        $crawler->filter('.product__keyfeatures--list li')->each(function (Crawler $node) use (&$highlights) {
            $text = trim($node->text());
            if ($text) {
                $highlights[] = $text;
            }
        });

        return !empty($highlights) ? implode('. ', $highlights) : null;
    }




}
