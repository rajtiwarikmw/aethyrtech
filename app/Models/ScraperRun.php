<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScraperRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'platform',
        'type',
        'status',
        'products_scraped',
        'products_added',
        'products_updated',
        'errors_count',
        'error_message',
        'started_at',
        'completed_at',
        'duration_seconds',
        'triggered_by',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Get the user who triggered this scraper run
     */
    public function triggeredBy()
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    /**
     * Scope for manual runs
     */
    public function scopeManual($query)
    {
        return $query->where('type', 'manual');
    }

    /**
     * Scope for scheduled runs
     */
    public function scopeScheduled($query)
    {
        return $query->where('type', 'scheduled');
    }

    /**
     * Scope for specific platform
     */
    public function scopePlatform($query, $platform)
    {
        return $query->where('platform', $platform);
    }

    /**
     * Scope for specific status
     */
    public function scopeStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Get duration in human readable format
     */
    public function getDurationHumanAttribute()
    {
        if (!$this->duration_seconds) {
            return 'N/A';
        }

        $minutes = floor($this->duration_seconds / 60);
        $seconds = $this->duration_seconds % 60;

        if ($minutes > 0) {
            return "{$minutes}m {$seconds}s";
        }

        return "{$seconds}s";
    }

    /**
     * Mark as running
     */
    public function markAsRunning()
    {
        $this->update([
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    /**
     * Mark as completed
     */
    public function markAsCompleted(array $stats = [])
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
            'duration_seconds' => now()->diffInSeconds($this->started_at),
            'products_scraped' => $stats['products_found'] ?? 0,
            'products_added' => $stats['products_added'] ?? 0,
            'products_updated' => $stats['products_updated'] ?? 0,
            'errors_count' => $stats['errors_count'] ?? 0,
        ]);
    }

    /**
     * Mark as failed
     */
    public function markAsFailed($errorMessage)
    {
        $this->update([
            'status' => 'failed',
            'completed_at' => now(),
            'duration_seconds' => $this->started_at ? now()->diffInSeconds($this->started_at) : null,
            'error_message' => $errorMessage,
        ]);
    }
}
