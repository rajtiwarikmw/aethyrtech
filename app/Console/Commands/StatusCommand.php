<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\DatabaseService;
use App\Models\Product;
use App\Models\ScrapingLog;
use Illuminate\Support\Facades\DB;

class StatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'scraper:status 
                            {--platform= : Show status for specific platform}
                            {--days=7 : Number of days to analyze}
                            {--detailed : Show detailed statistics}';

    /**
     * The console command description.
     */
    protected $description = 'Show scraper system status and statistics';

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
        $platform = $this->option('platform');
        $days = (int) $this->option('days');
        $detailed = $this->option('detailed');

        $this->info("Product Scraper System Status");
        $this->line("============================");

        try {
            if ($platform) {
                $this->showPlatformStatus($platform, $days, $detailed);
            } else {
                $this->showOverallStatus($days, $detailed);
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to get status: " . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Show overall system status
     */
    protected function showOverallStatus(int $days, bool $detailed): void
    {
        // System health check
        $this->showSystemHealth();

        // Platform performance
        $this->showPlatformPerformance($days);

        // Recent activity
        $this->showRecentActivity($days);

        if ($detailed) {
            $this->showDetailedStatistics($days);
        }
    }

    /**
     * Show specific platform status
     */
    protected function showPlatformStatus(string $platform, int $days, bool $detailed): void
    {
        $platformConfig = config("scraper.platforms.{$platform}");
        if (!$platformConfig) {
            $this->error("Unknown platform: {$platform}");
            return;
        }

        $this->info("Platform: {$platformConfig['name']}");
        $this->line("Platform Key: {$platform}");
        $this->newLine();

        // Platform statistics
        $stats = $this->databaseService->getPlatformStats($platform);
        $this->showPlatformStats($stats);

        // Recent logs
        $recentLogs = $this->databaseService->getRecentScrapingLogs($days, $platform);
        $this->showPlatformLogs($recentLogs, $days);

        if ($detailed) {
            $this->showPlatformDetails($platform, $days);
        }
    }

    /**
     * Show system health indicators
     */
    protected function showSystemHealth(): void
    {
        $this->info("System Health:");
        $this->line("==============");

        // Database connection
        try {
            DB::connection()->getPdo();
            $this->line("✓ Database: Connected");
        } catch (\Exception $e) {
            $this->line("✗ Database: Connection failed");
        }

        // Recent scraping activity
        $recentActivity = ScrapingLog::where('created_at', '>=', now()->subHours(48))->count();
        if ($recentActivity > 0) {
            $this->line("✓ Recent Activity: {$recentActivity} scraping sessions in last 48 hours");
        } else {
            $this->line("⚠ Recent Activity: No scraping activity in last 48 hours");
        }

        // Data freshness
        $freshData = Product::where('scraped_date', '>=', now()->subDays(3))->count();
        $totalData = Product::count();
        $freshPercentage = $totalData > 0 ? round(($freshData / $totalData) * 100, 1) : 0;

        if ($freshPercentage > 80) {
            $this->line("✓ Data Freshness: {$freshPercentage}% of data is fresh (< 3 days old)");
        } elseif ($freshPercentage > 50) {
            $this->line("⚠ Data Freshness: {$freshPercentage}% of data is fresh (< 3 days old)");
        } else {
            $this->line("✗ Data Freshness: {$freshPercentage}% of data is fresh (< 3 days old)");
        }

        $this->newLine();
    }

    /**
     * Show platform performance summary
     */
    protected function showPlatformPerformance(int $days): void
    {
        $this->info("Platform Performance (Last {$days} days):");
        $this->line("==========================================");

        $performance = $this->databaseService->getPlatformPerformance($days);

        $headers = ['Platform', 'Success Rate', 'Total Runs', 'Products', 'Last Run'];
        $rows = [];

        foreach ($performance as $platformKey => $data) {
            $lastRun = $data['last_run'] ? $data['last_run']->format('Y-m-d H:i') : 'Never';
            $successRate = $data['success_rate'] . '%';

            $rows[] = [
                $data['name'],
                $successRate,
                $data['total_runs'],
                $data['total_products'],
                $lastRun
            ];
        }

        $this->table($headers, $rows);
        $this->newLine();
    }

    /**
     * Show recent activity
     */
    protected function showRecentActivity(int $days): void
    {
        $this->info("Recent Activity (Last {$days} days):");
        $this->line("====================================");

        $recentLogs = $this->databaseService->getRecentScrapingLogs($days);

        if ($recentLogs->isEmpty()) {
            $this->line("No recent activity found.");
            $this->newLine();
            return;
        }

        $headers = ['Date', 'Platform', 'Status', 'Products', 'Duration', 'Errors'];
        $rows = [];

        foreach ($recentLogs->take(10) as $log) {
            $rows[] = [
                $log->created_at->format('Y-m-d H:i'),
                ucfirst($log->platform),
                ucfirst($log->status),
                $log->products_found ?? 0,
                $log->formatted_duration ?? 'N/A',
                $log->errors_count ?? 0
            ];
        }

        $this->table($headers, $rows);
        $this->newLine();
    }

    /**
     * Show detailed statistics
     */
    protected function showDetailedStatistics(int $days): void
    {
        $this->info("Detailed Statistics:");
        $this->line("===================");

        // Product statistics
        $totalProducts = Product::count();
        $activeProducts = Product::where('is_active', true)->count();
        $productsWithImages = Product::whereNotNull('image_urls')->count();
        $productsWithRatings = Product::whereNotNull('rating')->count();

        $this->line("Product Statistics:");
        $this->line("- Total Products: {$totalProducts}");
        $this->line("- Active Products: {$activeProducts}");
        $this->line("- Products with Images: {$productsWithImages}");
        $this->line("- Products with Ratings: {$productsWithRatings}");

        // Price statistics
        $avgPrice = Product::where('is_active', true)->avg('price');
        $minPrice = Product::where('is_active', true)->min('price');
        $maxPrice = Product::where('is_active', true)->max('price');

        $this->newLine();
        $this->line("Price Statistics:");
        $this->line("- Average Price: ₹" . number_format($avgPrice ?? 0, 2));
        $this->line("- Minimum Price: ₹" . number_format($minPrice ?? 0, 2));
        $this->line("- Maximum Price: ₹" . number_format($maxPrice ?? 0, 2));

        // Brand distribution
        $topBrands = Product::where('is_active', true)
            ->whereNotNull('brand')
            ->groupBy('brand')
            ->selectRaw('brand, COUNT(*) as count')
            ->orderBy('count', 'desc')
            ->limit(5)
            ->get();

        $this->newLine();
        $this->line("Top Brands:");
        foreach ($topBrands as $brand) {
            $this->line("- {$brand->brand}: {$brand->count} products");
        }

        $this->newLine();
    }

    /**
     * Show platform-specific statistics
     */
    protected function showPlatformStats(array $stats): void
    {
        $this->line("Product Statistics:");
        $this->line("- Total Products: " . ($stats['total_products'] ?? 0));
        $this->line("- Active Products: " . ($stats['active_products'] ?? 0));
        $this->line("- Inactive Products: " . ($stats['inactive_products'] ?? 0));
        $this->line("- Average Price: ₹" . number_format($stats['avg_price'] ?? 0, 2));
        $this->line("- Price Range: ₹" . number_format($stats['min_price'] ?? 0, 2) . " - ₹" . number_format($stats['max_price'] ?? 0, 2));
        $this->line("- Unique Brands: " . ($stats['brands_count'] ?? 0));
        $this->line("- Products with Ratings: " . ($stats['products_with_rating'] ?? 0));
        $this->line("- Average Rating: " . number_format($stats['avg_rating'] ?? 0, 2));
        $this->line("- Last Scraped: " . ($stats['last_scrape'] ? $stats['last_scrape']->format('Y-m-d H:i:s') : 'Never'));
        $this->newLine();
    }

    /**
     * Show platform logs
     */
    protected function showPlatformLogs($logs, int $days): void
    {
        $this->line("Recent Scraping Sessions (Last {$days} days):");

        if ($logs->isEmpty()) {
            $this->line("No recent scraping sessions found.");
            return;
        }

        $headers = ['Date', 'Status', 'Found', 'Added', 'Updated', 'Errors', 'Duration'];
        $rows = [];

        foreach ($logs as $log) {
            $rows[] = [
                $log->created_at->format('Y-m-d H:i'),
                ucfirst($log->status),
                $log->products_found ?? 0,
                $log->products_added ?? 0,
                $log->products_updated ?? 0,
                $log->errors_count ?? 0,
                $log->formatted_duration ?? 'N/A'
            ];
        }

        $this->table($headers, $rows);
    }

    /**
     * Show detailed platform information
     */
    protected function showPlatformDetails(string $platform, int $days): void
    {
        $this->newLine();
        $this->line("Platform Configuration:");

        $config = config("scraper.platforms.{$platform}");
        $this->line("- Base URL: " . $config['base_url']);
        $this->line("- Category URLs: " . count($config['category_urls']));

        foreach ($config['category_urls'] as $i => $url) {
            $this->line("  " . ($i + 1) . ". {$url}");
        }
    }
}
