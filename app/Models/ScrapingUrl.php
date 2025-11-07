<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScrapingUrl extends Model
{
    use HasFactory;

    protected $fillable = [
        'platform',
        'url',
        'status',
        'priority',
        'last_scraped_at',
        'error_message',
        'retry_count',
    ];

    protected $casts = [
        'priority' => 'integer',
        'retry_count' => 'integer',
        'last_scraped_at' => 'datetime',
    ];

    /**
     * Get pending URLs for scraping
     */
    public static function getPendingUrls(string $platform = null, int $limit = null)
    {
        $query = self::where('status', 'pending')
            ->orderBy('priority', 'desc')
            ->orderBy('created_at', 'asc');

        if ($platform) {
            $query->where('platform', $platform);
        }

        if ($limit) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * Mark URL as processing
     */
    public function markAsProcessing(): void
    {
        $this->update(['status' => 'processing']);
    }

    /**
     * Mark URL as completed
     */
    public function markAsCompleted(): void
    {
        $this->update([
            'status' => 'completed',
            'last_scraped_at' => now(),
            'error_message' => null,
        ]);
    }

    /**
     * Mark URL as failed
     */
    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
            'retry_count' => $this->retry_count + 1,
        ]);
    }

    /**
     * Reset URL for retry
     */
    public function resetForRetry(): void
    {
        $this->update([
            'status' => 'pending',
            'error_message' => null,
        ]);
    }

    /**
     * Add multiple URLs
     */
    public static function addUrls(string $platform, array $urls, int $priority = 0): int
    {
        $count = 0;
        foreach ($urls as $url) {
            $url = trim($url);
            if (empty($url)) {
                continue;
            }

            // Check if URL already exists
            $exists = self::where('platform', $platform)
                ->where('url', $url)
                ->exists();

            if (!$exists) {
                self::create([
                    'platform' => $platform,
                    'url' => $url,
                    'priority' => $priority,
                    'status' => 'pending',
                ]);
                $count++;
            }
        }

        return $count;
    }
}
