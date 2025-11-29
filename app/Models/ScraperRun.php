<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScraperRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'scraper_id',
        'configuration_id',
        'platform',
        'category',
        'tag',
        'category_url',
        'description',
        'status',
        'started_at',
        'completed_at',
        'duration_seconds',
        'products_scraped',
        'reviews_scraped',
        'rankings_scraped',
        'errors_count',
        'error_message',
        'metadata',
        'triggered_by',
        'user_id',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'metadata' => 'array',
        'products_scraped' => 'integer',
        'reviews_scraped' => 'integer',
        'rankings_scraped' => 'integer',
        'errors_count' => 'integer',
        'duration_seconds' => 'integer',
    ];

    /**
     * Generate unique 10-digit scraper ID
     */
    public static function generateScraperId(): string
    {
        do {
            // Generate 10-digit numeric ID
            $scraperId = str_pad(mt_rand(1, 9999999999), 10, '0', STR_PAD_LEFT);
        } while (self::where('scraper_id', $scraperId)->exists());

        return $scraperId;
    }

    /**
     * Get the configuration this run belongs to
     */
    public function configuration()
    {
        return $this->belongsTo(ScraperConfiguration::class, 'configuration_id');
    }

    /**
     * Get the user who triggered this run
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get products scraped in this run
     */
    public function products()
    {
        return $this->hasMany(Product::class, 'scraper_id', 'scraper_id');
    }

    /**
     * Get reviews scraped in this run
     */
    public function reviews()
    {
        return $this->hasMany(Review::class, 'scraper_id', 'scraper_id');
    }

    /**
     * Get rankings scraped in this run
     */
    public function rankings()
    {
        return $this->hasMany(ProductRanking::class, 'scraper_id', 'scraper_id');
    }

    /**
     * Scope: Completed runs
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
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
     * Scope: By date range
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * Scope: Recent runs (last 30 days)
     */
    public function scopeRecent($query, $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * Mark run as started
     */
    public function markAsStarted()
    {
        $this->update([
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    /**
     * Mark run as completed
     */
    public function markAsCompleted(array $stats = [])
    {
        $this->update(array_merge([
            'status' => 'completed',
            'completed_at' => now(),
            'duration_seconds' => $this->started_at ? now()->diffInSeconds($this->started_at) : null,
        ], $stats));

        // Update configuration
        if ($this->configuration) {
            $this->configuration->update([
                'total_runs' => $this->configuration->total_runs + 1,
                'last_run_at' => now(),
                'last_scraper_id' => $this->scraper_id,
            ]);
        }
    }

    /**
     * Mark run as failed
     */
    public function markAsFailed(string $errorMessage = null)
    {
        $this->update([
            'status' => 'failed',
            'completed_at' => now(),
            'duration_seconds' => $this->started_at ? now()->diffInSeconds($this->started_at) : null,
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Get formatted duration
     */
    public function getFormattedDurationAttribute()
    {
        if (!$this->duration_seconds) {
            return 'N/A';
        }

        $hours = floor($this->duration_seconds / 3600);
        $minutes = floor(($this->duration_seconds % 3600) / 60);
        $seconds = $this->duration_seconds % 60;

        if ($hours > 0) {
            return sprintf('%dh %dm %ds', $hours, $minutes, $seconds);
        } elseif ($minutes > 0) {
            return sprintf('%dm %ds', $minutes, $seconds);
        } else {
            return sprintf('%ds', $seconds);
        }
    }

    /**
     * Get success rate percentage
     */
    public function getSuccessRateAttribute()
    {
        $total = $this->products_scraped + $this->reviews_scraped + $this->rankings_scraped;
        if ($total == 0) {
            return 0;
        }

        $errors = $this->errors_count;
        return round((($total - $errors) / $total) * 100, 2);
    }
}
