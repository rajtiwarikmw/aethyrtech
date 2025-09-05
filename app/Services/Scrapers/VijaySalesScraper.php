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

            $priceData = $this->extractPrices($crawler);
            $data['price'] = $priceData['price'];
            $data['sale_price'] = $priceData['sale_price'];

            $data['offers'] = $this->extractOffers($crawler);
            $data['inventory_status'] = $this->extractAvailability($crawler);

            $ratingData = $this->extractRatingAndReviews($crawler);
            $data['rating'] = $ratingData['rating'];
            $data['review_count'] = $ratingData['review_count'];

            $brandData = $this->extractBrandAndModel($crawler);
            $data['brand'] = $brandData['brand'];
            $data['model_name'] = $brandData['model'];

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
            'h1.title'
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

        $priceSelectors = [
            '.price-current',
            '.special-price',
            '.price-final',
            '.price .amount'
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

        $originalPriceSelectors = [
            '.price-old',
            '.regular-price',
            '.price-was'
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

        if (!$prices['price'] && $prices['sale_price']) {
            $prices['price'] = $prices['sale_price'];
            $prices['sale_price'] = null;
        }

        return $prices;
    }

    private function extractOffers(Crawler $crawler): ?string
    {
        $offers = [];

        $crawler->filter('.discount-percent, .offer-text, .promotion')->each(function (Crawler $node) use (&$offers) {
            $text = $this->cleanText($node->text());
            if ($text) {
                $offers[] = $text;
            }
        });

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

        $ratingSelectors = [
            '.rating-value',
            '.star-rating',
            '.review-rating'
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
            '.review-count',
            '.reviews-count',
            '.total-reviews'
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

    private function extractBrandAndModel(Crawler $crawler): array
    {
        $data = ['brand' => null, 'model' => null];

        $title = $this->extractProductName($crawler);
        if ($title) {
            $brands = ['HP', 'Dell', 'Lenovo', 'ASUS', 'Acer', 'Apple', 'MSI', 'Samsung', 'LG', 'Sony', 'Toshiba'];

            foreach ($brands as $brand) {
                if (stripos($title, $brand) !== false) {
                    $data['brand'] = $brand;
                    break;
                }
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

        $crawler->filter('.product-image img, .gallery-image img, .main-image img')->each(function (Crawler $node) use (&$images) {
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
}
