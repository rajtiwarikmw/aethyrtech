<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'review_id',
        'reviewer_name',
        'reviewer_profile_url',
        'rating',
        'review_title',
        'review_text',
        'review_date',
        'verified_purchase',
        'helpful_count',
        'review_images',
        'variant_info',
        'review_url',
    ];

    protected $casts = [
        'rating' => 'decimal:2',
        'helpful_count' => 'integer',
        'verified_purchase' => 'boolean',
        'review_images' => 'array',
        'review_date' => 'date',
    ];

    /**
     * Get the product that owns the review
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Find review by product and review ID
     */
    public static function findByProductAndReviewId(int $productId, string $reviewId): ?self
    {
        return self::where('product_id', $productId)
            ->where('review_id', $reviewId)
            ->first();
    }

    /**
     * Check if review data has changed
     */
    public function hasDataChanged(array $newData): bool
    {
        $fieldsToCheck = [
            'reviewer_name',
            'rating',
            'review_title',
            'review_text',
            'helpful_count',
            'review_images',
        ];

        foreach ($fieldsToCheck as $field) {
            if (isset($newData[$field])) {
                $currentValue = $this->getAttribute($field);
                $newValue = $newData[$field];

                // Handle JSON fields
                if ($field === 'review_images') {
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
     * Update review data if changed
     */
    public function updateIfChanged(array $newData): bool
    {
        if ($this->hasDataChanged($newData)) {
            $this->update($newData);
            return true;
        }

        return false;
    }
}
