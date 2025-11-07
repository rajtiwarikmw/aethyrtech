<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Keyword extends Model
{
    use HasFactory;

    protected $fillable = [
        'platform',
        'keyword',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    /**
     * Get the rankings for this keyword
     */
    public function rankings(): HasMany
    {
        return $this->hasMany(ProductRanking::class);
    }

    /**
     * Get active keywords for a platform
     */
    public static function getActiveForPlatform(string $platform)
    {
        return self::where('platform', $platform)
            ->where('status', true)
            ->get();
    }

    /**
     * Find or create keyword
     */
    public static function findOrCreateKeyword(string $platform, string $keyword): self
    {
        return self::firstOrCreate(
            ['platform' => $platform, 'keyword' => $keyword],
            ['status' => true]
        );
    }
}
