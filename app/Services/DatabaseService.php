<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ScrapingLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DatabaseService
{
    /**
     * Save or update Product data
     */
    public function saveOrUpdateProduct(array $data): array
    {
        $result = [
            'action' => 'none',
            'product' => null,
            'changed' => false
        ];

        try {
            DB::beginTransaction();

            // Validate required fields
            $this->validateProductData($data);

            // Find existing Product
            $existingProduct = Product::findByPlatformAndSku($data['platform'], $data['sku']);

            if ($existingProduct) {
                // Update existing Product
                $changed = $existingProduct->updateIfChanged($data);
                $result = [
                    'action' => 'updated',
                    'product' => $existingProduct->fresh(),
                    'changed' => $changed
                ];
            } else {
                // Create new Product
                $product = Product::create($data);
                $result = [
                    'action' => 'created',
                    'product' => $product,
                    'changed' => true
                ];
            }

            DB::commit();
            return $result;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to save Product data', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            throw $e;
        }
    }

    /**
     * Validate product data before saving
     */
    protected function validateProductData(array $data): void
    {
        $requiredFields = config('scraper.validation.required_fields', ['sku', 'title', 'platform']);

        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                throw new \InvalidArgumentException("Required field '{$field}' is missing or empty");
            }
        }

        // Validate price range
        if (isset($data['price']) && $data['price'] !== null) {
            $priceRange = config('scraper.validation.price_range');
            if ($data['price'] < $priceRange['min'] || $data['price'] > $priceRange['max']) {
                Log::warning('Price out of expected range', [
                    'price' => $data['price'],
                    'sku' => $data['sku'],
                    'platform' => $data['platform']
                ]);
            }
        }

        // Validate text field lengths
        $maxLengths = [
            'title' => config('scraper.validation.max_title_length', 500),
            'description' => config('scraper.validation.max_description_length', 5000),
            'brand' => config('scraper.validation.max_brand_length', 100),
            'model_name' => config('scraper.validation.max_model_length', 200)
        ];

        foreach ($maxLengths as $field => $maxLength) {
            if (isset($data[$field]) && strlen($data[$field]) > $maxLength) {
                $data[$field] = substr($data[$field], 0, $maxLength);
                Log::warning("Truncated field '{$field}' to {$maxLength} characters", [
                    'sku' => $data['sku'],
                    'platform' => $data['platform']
                ]);
            }
        }
    }

    /**
     * Deactivate old products for a platform
     */
    public function deactivateOldProducts(string $platform, \DateTime $cutoffTime): int
    {
        try {
            $count = Product::where('platform', $platform)
                ->where('is_active', true)
                ->where('scraped_date', '<', $cutoffTime)
                ->update(['is_active' => false]);

            if ($count > 0) {
                Log::info("Deactivated {$count} old products for platform: {$platform}");
            }

            return $count;
        } catch (\Exception $e) {
            Log::error('Failed to deactivate old products', [
                'platform' => $platform,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get platform statistics
     */
    public function getPlatformStats(string $platform): array
    {
        try {
            $stats = [
                'total_products' => Product::platform($platform)->count(),
                'active_products' => Product::platform($platform)->active()->count(),
                'inactive_products' => Product::platform($platform)->where('is_active', false)->count(),
                'avg_price' => Product::platform($platform)->active()->avg('price'),
                'min_price' => Product::platform($platform)->active()->min('price'),
                'max_price' => Product::platform($platform)->active()->max('price'),
                'brands_count' => Product::platform($platform)->active()->distinct('brand')->count(),
                'last_scrape' => Product::platform($platform)->max('scraped_date'),
                'products_with_rating' => Product::platform($platform)->active()->whereNotNull('rating')->count(),
                'avg_rating' => Product::platform($platform)->active()->whereNotNull('rating')->avg('rating')
            ];

            return $stats;
        } catch (\Exception $e) {
            Log::error('Failed to get platform stats', [
                'platform' => $platform,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Get recent scraping logs
     */
    public function getRecentScrapingLogs(int $days = 7, ?string $platform = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = ScrapingLog::recent($days)
            ->orderBy('created_at', 'desc');

        if ($platform) {
            $query->platform($platform);
        }

        return $query->get();
    }

    /**
     * Clean up old scraping logs
     */
    public function cleanupOldLogs(int $retentionDays = 30): int
    {
        try {
            $cutoffDate = now()->subDays($retentionDays);

            $count = ScrapingLog::where('created_at', '<', $cutoffDate)->delete();

            if ($count > 0) {
                Log::info("Cleaned up {$count} old scraping logs");
            }

            return $count;
        } catch (\Exception $e) {
            Log::error('Failed to cleanup old logs', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get duplicate products (same SKU across platforms)
     */
    public function getDuplicateProducts(): \Illuminate\Database\Eloquent\Collection
    {
        return Product::select('sku', DB::raw('COUNT(*) as count'), DB::raw('GROUP_CONCAT(platform) as platforms'))
            ->groupBy('sku')
            ->having('count', '>', 1)
            ->get();
    }

    /**
     * Get products without essential data
     */
    public function getIncompleteProducts(string $platform = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = Product::where(function ($q) {
            $q->whereNull('price')
                ->orWhereNull('brand')
                ->orWhereNull('title')
                ->orWhere('title', '')
                ->orWhere('description', '');
        });

        if ($platform) {
            $query->platform($platform);
        }

        return $query->active()->get();
    }

    /**
     * Update product images
     */
    public function updateProductImages(int $productId, array $imageUrls): bool
    {
        try {
            $product = Product::findOrFail($productId);
            $product->update(['image_urls' => $imageUrls]);
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to update product images', [
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Bulk update product status
     */
    public function bulkUpdateStatus(array $productIds, bool $isActive): int
    {
        try {
            return Product::whereIn('id', $productIds)
                ->update(['is_active' => $isActive]);
        } catch (\Exception $e) {
            Log::error('Failed to bulk update product status', [
                'product_ids' => $productIds,
                'is_active' => $isActive,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get platform performance metrics
     */
    public function getPlatformPerformance(int $days = 30): array
    {
        $platforms = config('scraper.platforms', []);
        $performance = [];

        foreach ($platforms as $platformKey => $platformConfig) {
            $successRate = ScrapingLog::getSuccessRate($platformKey, $days);
            $recentLogs = $this->getRecentScrapingLogs($days, $platformKey);

            $performance[$platformKey] = [
                'name' => $platformConfig['name'],
                'success_rate' => $successRate,
                'total_runs' => $recentLogs->count(),
                'successful_runs' => $recentLogs->where('status', 'completed')->count(),
                'failed_runs' => $recentLogs->where('status', 'failed')->count(),
                'avg_duration' => $recentLogs->where('status', 'completed')->avg('duration_seconds'),
                'last_run' => $recentLogs->first()?->created_at,
                'total_products' => $this->getPlatformStats($platformKey)['total_products'] ?? 0
            ];
        }

        return $performance;
    }
}
