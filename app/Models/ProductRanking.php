<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductRanking extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'sku',
        'keyword_id',
        'position',
        'page',
    ];

    protected $casts = [
        'position' => 'integer',
        'page' => 'integer',
    ];

    /**
     * Get the product that owns this ranking
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the keyword for this ranking
     */
    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class);
    }

    /**
     * Get latest ranking for a product and keyword
     */
    public static function getLatestRanking(int $productId, int $keywordId)
    {
        return self::where('product_id', $productId)
            ->where('keyword_id', $keywordId)
            ->latest()
            ->first();
    }

    /**
     * Get ranking history for a product and keyword
     */
    public static function getRankingHistory(int $productId, int $keywordId, int $days = 30)
    {
        return self::where('product_id', $productId)
            ->where('keyword_id', $keywordId)
            ->where('created_at', '>=', now()->subDays($days))
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Record a new ranking
     */
    public static function recordRanking(array $data): self
    {
        return self::create($data);
    }
}
