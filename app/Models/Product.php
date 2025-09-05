<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'platform',
        'sku',
        'title',
        'description',
        'currency_code',
        'price',
        'sale_price',
        'offers',
        'category',
        'inventory_status',
        'rating',
        'review_count',
        'brand',
        'model_name',
        'color',
        'image_urls',
        'video_urls',
        'product_url',
        'is_active',
        'scraped_date',
        // New detailed specifications
        'manufacturer',
        'weight',
        'dimensions',
        // Detailed offers
        'detailed_offers',
        'cashback_offers',
        'emi_offers',
        'is_prime',
        'is_sponsored',
        'bank_offers',
        'partner_offers',
        // Additional product information
        'technical_details',
        'additional_information',
        'seller_name',
        'product_badge',
        'amazon_choice',
        'bestseller'
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'rating' => 'decimal:2',
        'review_count' => 'integer',
        'processor_count' => 'integer',
        'image_urls' => 'array',
        'video_urls' => 'array',
        'detailed_offers' => 'array',
        'technical_details' => 'array',
        'additional_information' => 'array',
        'is_active' => 'boolean',
        'amazon_choice' => 'boolean',
        'bestseller' => 'boolean',
        'scraped_date' => 'datetime'
    ];

    /**
     * Scope to filter by platform
     */
    public function scopePlatform(Builder $query, string $platform): Builder
    {
        return $query->where('platform', $platform);
    }

    /**
     * Scope to filter active products
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to filter by brand
     */
    public function scopeBrand(Builder $query, string $brand): Builder
    {
        return $query->where('brand', $brand);
    }

    /**
     * Find Product by platform and SKU
     */
    public static function findByPlatformAndSku(string $platform, string $sku): ?self
    {
        return self::where('platform', $platform)
            ->where('sku', $sku)
            ->first();
    }

    /**
     * Get the effective price (sale price if available, otherwise regular price)
     */
    public function getEffectivePriceAttribute(): ?float
    {
        return $this->sale_price ?? $this->price;
    }

    /**
     * Get the discount percentage
     */
    public function getDiscountPercentageAttribute(): ?float
    {
        if (!$this->price || !$this->sale_price || $this->sale_price >= $this->price) {
            return null;
        }

        return round((($this->price - $this->sale_price) / $this->price) * 100, 2);
    }

    /**
     * Get the primary image URL
     */
    public function getPrimaryImageAttribute(): ?string
    {
        return $this->image_urls[0] ?? null;
    }

    /**
     * Check if product data has changed
     */
    public function hasDataChanged(array $newData): bool
    {
        $fieldsToCheck = [
            'title',
            'description',
            'price',
            'sale_price',
            'offers',
            'inventory_status',
            'rating',
            'review_count',
            'brand',
            'model_name',
            'color',
            'image_urls',
            'video_urls'
        ];

        foreach ($fieldsToCheck as $field) {
            if (isset($newData[$field])) {
                $currentValue = $this->getAttribute($field);
                $newValue = $newData[$field];

                // Handle JSON fields
                if (in_array($field, ['image_urls', 'video_urls'])) {
                    $currentValue = is_array($currentValue) ? $currentValue : json_decode($currentValue, true);
                    $newValue = is_array($newValue) ? $newValue : json_decode($newValue, true);
                }

                if ($currentValue != $newValue) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Update product data if changed
     */
    public function updateIfChanged(array $newData): bool
    {
        if ($this->hasDataChanged($newData)) {
            $newData['scraped_date'] = now();
            $this->update($newData);
            return true;
        }

        // Update only the scraped_date timestamp
        $this->update(['scraped_date' => now()]);
        return false;
    }
}
