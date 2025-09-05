<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class DataSanitizer
{
    /**
     * Sanitize and validate product data
     */
    public static function sanitizeProductData(array $data): array
    {
        $sanitized = [];

        // Required fields
        $sanitized['platform'] = self::sanitizeString($data['platform'] ?? '');
        $sanitized['sku'] = self::sanitizeString($data['sku'] ?? '');
        $sanitized['title'] = self::sanitizeString($data['title'] ?? '');

        // Optional text fields
        $sanitized['description'] = self::sanitizeText($data['description'] ?? null);
        $sanitized['offers'] = self::sanitizeText($data['offers'] ?? null);
        $sanitized['inventory_status'] = self::sanitizeString($data['inventory_status'] ?? null);
        $sanitized['brand'] = self::sanitizeString($data['brand'] ?? null);
        $sanitized['model_name'] = self::sanitizeString($data['model_name'] ?? null);
        $sanitized['color'] = self::sanitizeString($data['color'] ?? null);
        $sanitized['weight'] = self::sanitizeString($data['weight'] ?? null);
        $sanitized['dimensions'] = self::sanitizeString($data['dimensions'] ?? null);
        $sanitized['product_url'] = self::sanitizeUrl($data['product_url'] ?? null);
        $sanitized['category'] = self::sanitizeString($data['category'] ?? null);
        $sanitized['currency_code'] = self::sanitizeString($data['currency_code'] ?? null);

        // Numeric fields
        $sanitized['price'] = self::sanitizePrice($data['price'] ?? null);
        $sanitized['sale_price'] = self::sanitizePrice($data['sale_price'] ?? null);
        $sanitized['rating'] = self::sanitizeRating($data['rating'] ?? null);
        $sanitized['review_count'] = self::sanitizeInteger($data['review_count'] ?? 0);

        // Array fields
        $sanitized['image_urls'] = self::sanitizeUrlArray($data['image_urls'] ?? []);
        $sanitized['video_urls'] = self::sanitizeUrlArray($data['video_urls'] ?? []);
        $sanitized['technical_details'] = self::sanitizeArray($data['technical_details'] ?? null);
        $sanitized['additional_information'] = self::sanitizeArray($data['additional_information'] ?? null);

        // Boolean fields
        $sanitized['is_active'] = isset($data['is_active']) ? (bool) $data['is_active'] : true;
        $sanitized['bestseller'] = !empty($data['bestseller']);
        $sanitized['amazon_choice'] = !empty($data['amazon_choice']);


        // Remove empty values
        return array_filter($sanitized, function ($value) {
            return $value !== null && $value !== '';
        });
    }

    /**
     * Sanitize string field
     */
    public static function sanitizeString(?string $value, int $maxLength = null): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Remove HTML tags and normalize whitespace
        $sanitized = trim(preg_replace('/\s+/', ' ', strip_tags($value)));

        // Remove special characters that might cause issues
        $sanitized = preg_replace('/[^\p{L}\p{N}\s\-_.,()&\/]/u', '', $sanitized);

        if ($maxLength && strlen($sanitized) > $maxLength) {
            $sanitized = substr($sanitized, 0, $maxLength);
        }

        return $sanitized ?: null;
    }

    /**
     * Sanitize text field (allows more characters)
     */
    public static function sanitizeText(?string $value, int $maxLength = null): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Remove HTML tags but preserve line breaks
        $sanitized = strip_tags($value);
        $sanitized = trim(preg_replace('/\s+/', ' ', $sanitized));

        if ($maxLength && strlen($sanitized) > $maxLength) {
            $sanitized = substr($sanitized, 0, $maxLength);
        }

        return $sanitized ?: null;
    }

    /**
     * Sanitize URL
     */
    public static function sanitizeUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $url = trim($url);

        // Add protocol if missing
        if (!preg_match('/^https?:\/\//', $url)) {
            $url = 'https://' . $url;
        }

        // Validate URL format
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }

        return null;
    }

    /**
     * Sanitize price value
     */
    public static function sanitizePrice($price): ?float
    {
        if ($price === null || $price === '') {
            return null;
        }

        // Handle string prices
        if (is_string($price)) {
            // Remove currency symbols and commas
            $price = preg_replace('/[^\d.,]/', '', $price);
            $price = str_replace(',', '', $price);
        }

        $price = (float) $price;

        // Validate price range
        $priceRange = config('scraper.validation.price_range', ['min' => 1000, 'max' => 1000000]);

        if ($price < $priceRange['min'] || $price > $priceRange['max']) {
            Log::warning('Price out of valid range', ['price' => $price]);
            return null;
        }

        return $price;
    }

    /**
     * Sanitize rating value
     */
    public static function sanitizeRating($rating): ?float
    {
        if ($rating === null || $rating === '') {
            return null;
        }

        if (is_string($rating)) {
            // Extract numeric rating from string
            preg_match('/(\d+\.?\d*)/', $rating, $matches);
            $rating = isset($matches[1]) ? (float) $matches[1] : null;
        } else {
            $rating = (float) $rating;
        }

        // Validate rating range (0-5)
        if ($rating !== null && ($rating < 0 || $rating > 5)) {
            Log::warning('Rating out of valid range', ['rating' => $rating]);
            return null;
        }

        return $rating;
    }

    /**
     * Sanitize integer value
     */
    public static function sanitizeInteger($value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (is_string($value)) {
            // Remove non-numeric characters
            $value = preg_replace('/[^\d]/', '', $value);
        }

        return (int) $value;
    }

    /**
     * Sanitize array field
     */
    public static function sanitizeArray($value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        // अगर string मिली → JSON decode या split
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            } else {
                // comma, semicolon, pipe से split
                $value = preg_split('/[,;|]/', $value);
            }
        }

        if (!is_array($value)) {
            return null;
        }

        $sanitized = [];
        foreach ($value as $key => $item) {

            $cleanKey = is_string($key) ? self::sanitizeString($key) : $key;

            if (is_array($item)) {
                $cleanVal = self::sanitizeArray($item); // recursive
            } else {
                $cleanVal = self::sanitizeString((string) $item);
            }

            if ($cleanVal !== null && $cleanVal !== '') {
                $sanitized[$cleanKey] = $cleanVal;
            }
        }

        return !empty($sanitized) ? $sanitized : null;
    }


    /**
     * Sanitize array of URLs
     */
    public static function sanitizeUrlArray($urls): ?array
    {
        if (!is_array($urls) || empty($urls)) {
            return null;
        }

        $sanitized = [];
        foreach ($urls as $url) {
            $cleanUrl = self::sanitizeUrl($url);
            if ($cleanUrl) {
                $sanitized[] = $cleanUrl;
            }
        }

        return !empty($sanitized) ? $sanitized : null;
    }

    /**
     * Extract SKU from URL or text
     */
    public static function extractSku(string $text, string $platform): ?string
    {
        $patterns = [
            'amazon' => [
                '/\/dp\/([A-Z0-9]{10})/',
                '/\/product\/([A-Z0-9]+)/',
                '/asin[=:]([A-Z0-9]{10})/i'
            ],
            'flipkart' => [
                '/\/p\/([a-zA-Z0-9]+)/',
                '/pid=([A-Z0-9]+)/',
                '/\/([A-Z0-9]{16})/'
            ],
            'default' => [
                '/product[_-]?id[=:]([A-Z0-9]+)/i',
                '/sku[=:]([A-Z0-9]+)/i',
                '/id[=:]([A-Z0-9]+)/i'
            ]
        ];

        $platformPatterns = $patterns[$platform] ?? $patterns['default'];

        foreach ($platformPatterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * Normalize brand name
     */
    public static function normalizeBrand(?string $brand): ?string
    {
        if (!$brand) {
            return null;
        }

        $brand = self::sanitizeString($brand);

        // Common brand name mappings
        $brandMappings = [
            'hp' => 'HP',
            'dell' => 'Dell',
            'lenovo' => 'Lenovo',
            'asus' => 'ASUS',
            'acer' => 'Acer',
            'apple' => 'Apple',
            'msi' => 'MSI',
            'samsung' => 'Samsung',
            'lg' => 'LG',
            'sony' => 'Sony',
            'toshiba' => 'Toshiba',
            'fujitsu' => 'Fujitsu',
            'alienware' => 'Alienware',
            'razer' => 'Razer'
        ];

        $lowerBrand = strtolower($brand);
        return $brandMappings[$lowerBrand] ?? ucfirst($brand);
    }

    /**
     * Extract specifications from text
     */
    public static function extractSpecifications(string $text): array
    {
        $specs = [];

        // RAM extraction
        if (preg_match('/(\d+)\s*GB\s*(RAM|Memory)/i', $text, $matches)) {
            $specs['ram'] = $matches[1] . 'GB';
        }



        return $specs;
    }
}
