<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScraperConfiguration extends Model
{
    use HasFactory;

    protected $fillable = [
        'platform',
        'category',
        'tag',
        'category_url',
        'description',
        'status',
        'total_runs',
        'last_run_at',
        'last_scraper_id',
    ];

    protected $casts = [
        'last_run_at' => 'datetime',
        'total_runs' => 'integer',
    ];

    /**
     * Get all scraper runs for this configuration
     */
    public function scraperRuns()
    {
        return $this->hasMany(ScraperRun::class, 'configuration_id');
    }

    /**
     * Get the last scraper run
     */
    public function lastRun()
    {
        return $this->hasOne(ScraperRun::class, 'configuration_id')->latest();
    }

    /**
     * Get products scraped by this configuration
     */
    public function products()
    {
        return $this->hasManyThrough(
            Product::class,
            ScraperRun::class,
            'configuration_id', // Foreign key on scraper_runs
            'scraper_id', // Foreign key on products
            'id', // Local key on scraper_configurations
            'scraper_id' // Local key on scraper_runs
        );
    }

    /**
     * Scope: Active configurations
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope: By platform
     */
    public function scopePlatform($query, $platform)
    {
        return $query->where('platform', $platform);
    }

    /**
     * Scope: By category
     */
    public function scopeCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Get statistics for this configuration
     */
    public function getStatistics()
    {
        $runs = $this->scraperRuns;
        
        return [
            'total_runs' => $runs->count(),
            'successful_runs' => $runs->where('status', 'completed')->count(),
            'failed_runs' => $runs->where('status', 'failed')->count(),
            'total_products' => $runs->sum('products_scraped'),
            'total_reviews' => $runs->sum('reviews_scraped'),
            'total_rankings' => $runs->sum('rankings_scraped'),
            'avg_duration' => $runs->where('status', 'completed')->avg('duration_seconds'),
            'last_run_date' => $this->last_run_at?->format('Y-m-d H:i:s'),
        ];
    }
}
