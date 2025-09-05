<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\DatabaseService;
use App\Models\Product;
use App\Models\ScrapingLog;
use Illuminate\Support\Facades\Log;

class CleanupCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'scraper:cleanup 
                            {--logs=30 : Days to keep scraping logs}
                            {--inactive=90 : Days to keep inactive products}
                            {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     */
    protected $description = 'Clean up old scraping logs and inactive products';

    protected DatabaseService $databaseService;

    public function __construct(DatabaseService $databaseService)
    {
        parent::__construct();
        $this->databaseService = $databaseService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $logRetentionDays = (int) $this->option('logs');
        $inactiveRetentionDays = (int) $this->option('inactive');
        $dryRun = $this->option('dry-run');

        $this->info("Starting cleanup process...");

        if ($dryRun) {
            $this->warn("DRY RUN MODE - No data will be deleted");
        }

        try {
            // Clean up old logs
            $this->cleanupLogs($logRetentionDays, $dryRun);

            // Clean up old inactive products
            $this->cleanupInactiveProducts($inactiveRetentionDays, $dryRun);

            // Show statistics
            $this->showStatistics();

            $this->info("Cleanup completed successfully!");
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Cleanup failed: " . $e->getMessage());
            Log::error("Cleanup command failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return self::FAILURE;
        }
    }

    /**
     * Clean up old scraping logs
     */
    protected function cleanupLogs(int $retentionDays, bool $dryRun): void
    {
        $cutoffDate = now()->subDays($retentionDays);

        $query = ScrapingLog::where('created_at', '<', $cutoffDate);
        $count = $query->count();

        if ($count === 0) {
            $this->info("No old logs to clean up (older than {$retentionDays} days)");
            return;
        }

        if ($dryRun) {
            $this->info("Would delete {$count} scraping logs older than {$retentionDays} days");
        } else {
            $deleted = $query->delete();
            $this->info("Deleted {$deleted} scraping logs older than {$retentionDays} days");

            Log::info("Cleaned up old scraping logs", [
                'deleted_count' => $deleted,
                'retention_days' => $retentionDays
            ]);
        }
    }

    /**
     * Clean up old inactive products
     */
    protected function cleanupInactiveProducts(int $retentionDays, bool $dryRun): void
    {
        $cutoffDate = now()->subDays($retentionDays);

        $query = Product::where('is_active', false)
            ->where('updated_at', '<', $cutoffDate);

        $count = $query->count();

        if ($count === 0) {
            $this->info("No old inactive products to clean up (older than {$retentionDays} days)");
            return;
        }

        if ($dryRun) {
            $this->info("Would delete {$count} inactive products older than {$retentionDays} days");
        } else {
            $deleted = $query->delete();
            $this->info("Deleted {$deleted} inactive products older than {$retentionDays} days");

            Log::info("Cleaned up old inactive products", [
                'deleted_count' => $deleted,
                'retention_days' => $retentionDays
            ]);
        }
    }

    /**
     * Show current database statistics
     */
    protected function showStatistics(): void
    {
        $this->newLine();
        $this->info("Current Database Statistics:");
        $this->line("================================");

        // Total products by platform
        $platforms = config('scraper.platforms', []);
        foreach ($platforms as $platformKey => $platformConfig) {
            $stats = $this->databaseService->getPlatformStats($platformKey);
            $this->line(sprintf(
                "%-20s: %d total (%d active, %d inactive)",
                $platformConfig['name'],
                $stats['total_products'] ?? 0,
                $stats['active_products'] ?? 0,
                $stats['inactive_products'] ?? 0
            ));
        }

        $this->newLine();

        // Overall statistics
        $totalProducts = Product::count();
        $activeProducts = Product::where('is_active', true)->count();
        $inactiveProducts = Product::where('is_active', false)->count();
        $totalLogs = ScrapingLog::count();
        $recentLogs = ScrapingLog::where('created_at', '>=', now()->subDays(7))->count();

        $this->line("Overall Statistics:");
        $this->line("- Total Products: {$totalProducts}");
        $this->line("- Active Products: {$activeProducts}");
        $this->line("- Inactive Products: {$inactiveProducts}");
        $this->line("- Total Scraping Logs: {$totalLogs}");
        $this->line("- Recent Logs (7 days): {$recentLogs}");

        // Database size estimation
        $avgProductSize = 2; // KB per product (estimated)
        $avgLogSize = 1; // KB per log (estimated)
        $estimatedSize = ($totalProducts * $avgProductSize) + ($totalLogs * $avgLogSize);

        $this->line("- Estimated DB Size: {$estimatedSize} KB");
    }
}
