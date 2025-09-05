<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class ScrapingLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'platform',
        'status',
        'products_found',
        'products_updated',
        'products_added',
        'products_deactivated',
        'errors_count',
        'error_message',
        'error_details',
        'started_at',
        'completed_at',
        'duration_seconds',
        'summary'
    ];

    protected $casts = [
        'products_found' => 'integer',
        'products_updated' => 'integer',
        'products_added' => 'integer',
        'products_deactivated' => 'integer',
        'errors_count' => 'integer',
        'error_details' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'duration_seconds' => 'integer'
    ];

    /**
     * Scope to filter by platform
     */
    public function scopePlatform(Builder $query, string $platform): Builder
    {
        return $query->where('platform', $platform);
    }

    /**
     * Scope to filter by status
     */
    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to get recent logs
     */
    public function scopeRecent(Builder $query, int $days = 7): Builder
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * Start a new scraping session
     */
    public static function startSession(string $platform): self
    {
        return self::create([
            'platform' => $platform,
            'status' => 'started',
            'started_at' => now()
        ]);
    }

    /**
     * Complete the scraping session
     */
    public function complete(array $stats = []): void
    {
        $completedAt = now();
        $duration = $this->started_at ? $completedAt->diffInSeconds($this->started_at) : null;

        $updateData = array_merge($stats, [
            'status' => 'completed',
            'completed_at' => $completedAt,
            'duration_seconds' => $duration
        ]);

        $this->update($updateData);
    }

    /**
     * Mark session as failed
     */
    public function fail(string $errorMessage, array $errorDetails = [], array $stats = []): void
    {
        $completedAt = now();
        $duration = $this->started_at ? $completedAt->diffInSeconds($this->started_at) : null;

        $updateData = array_merge($stats, [
            'status' => 'failed',
            'error_message' => $errorMessage,
            'error_details' => $errorDetails,
            'completed_at' => $completedAt,
            'duration_seconds' => $duration,
            'errors_count' => ($this->errors_count ?? 0) + 1
        ]);

        $this->update($updateData);
    }

    /**
     * Add error to the session
     */
    public function addError(string $errorMessage, array $errorDetails = []): void
    {
        $this->increment('errors_count');
        
        $currentErrors = $this->error_details ?? [];
        $currentErrors[] = [
            'message' => $errorMessage,
            'details' => $errorDetails,
            'timestamp' => now()->toISOString()
        ];

        $this->update([
            'error_details' => $currentErrors,
            'error_message' => $errorMessage // Keep the latest error message
        ]);
    }

    /**
     * Update session statistics
     */
    public function updateStats(array $stats): void
    {
        $this->update($stats);
    }

    /**
     * Get formatted duration
     */
    public function getFormattedDurationAttribute(): string
    {
        if (!$this->duration_seconds) {
            return 'N/A';
        }

        $hours = floor($this->duration_seconds / 3600);
        $minutes = floor(($this->duration_seconds % 3600) / 60);
        $seconds = $this->duration_seconds % 60;

        if ($hours > 0) {
            return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
        }

        return sprintf('%02d:%02d', $minutes, $seconds);
    }

    /**
     * Get success rate for the platform
     */
    public static function getSuccessRate(string $platform, int $days = 30): float
    {
        $total = self::platform($platform)
                     ->where('created_at', '>=', now()->subDays($days))
                     ->count();

        if ($total === 0) {
            return 0;
        }

        $successful = self::platform($platform)
                          ->status('completed')
                          ->where('created_at', '>=', now()->subDays($days))
                          ->count();

        return round(($successful / $total) * 100, 2);
    }
}

