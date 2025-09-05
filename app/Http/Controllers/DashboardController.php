<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\DatabaseService;
use App\Models\Product;
use App\Models\ScrapingLog;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    protected DatabaseService $databaseService;

    public function __construct(DatabaseService $databaseService)
    {
        $this->databaseService = $databaseService;
    }

    /**
     * Show the main dashboard
     */
    public function index(Request $request)
    {
        $days = $request->get('days', 7);

        $data = [
            'overview' => $this->getOverviewStats(),
            'platformPerformance' => $this->databaseService->getPlatformPerformance($days),
            'recentActivity' => $this->databaseService->getRecentScrapingLogs($days)->take(10),
            'chartData' => $this->getChartData($days),
            'systemHealth' => $this->getSystemHealth(),
            'days' => $days
        ];

        return view('dashboard.index', $data);
    }

    /**
     * Show platform-specific details
     */
    public function platform(Request $request, string $platform)
    {
        $days = $request->get('days', 7);

        $platformConfig = config("scraper.platforms.{$platform}");
        if (!$platformConfig) {
            abort(404, 'Platform not found');
        }

        $data = [
            'platform' => $platform,
            'platformName' => $platformConfig['name'],
            'platformConfig' => $platformConfig,
            'stats' => $this->databaseService->getPlatformStats($platform),
            'recentLogs' => $this->databaseService->getRecentScrapingLogs($days, $platform),
            'products' => $this->getPlatformProducts($platform, $request),
            'chartData' => $this->getPlatformChartData($platform, $days),
            'days' => $days
        ];

        return view('dashboard.platform', $data);
    }

    /**
     * Show products listing
     */
    public function products(Request $request)
    {
        $query = Product::query();

        // Apply filters
        if ($request->filled('platform')) {
            $query->where('platform', $request->platform);
        }

        if ($request->filled('brand')) {
            $query->where('brand', $request->brand);
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->status === 'active');
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        // Sorting
        $sortBy = $request->get('sort', 'updated_at');
        $sortOrder = $request->get('order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $products = $query->paginate(50);

        $data = [
            'products' => $products,
            'platforms' => $this->getAvailablePlatforms(),
            'brands' => $this->getAvailableBrands(),
            'filters' => $request->only(['platform', 'brand', 'status', 'search', 'sort', 'order'])
        ];

        return view('dashboard.products', $data);
    }

    /**
     * Show logs listing
     */
    public function logs(Request $request)
    {
        $query = ScrapingLog::query();

        // Apply filters
        if ($request->filled('platform')) {
            $query->where('platform', $request->platform);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('days')) {
            $days = (int) $request->days;
            $query->where('created_at', '>=', now()->subDays($days));
        }

        $logs = $query->orderBy('created_at', 'desc')->paginate(25);

        $data = [
            'logs' => $logs,
            'platforms' => $this->getAvailablePlatforms(),
            'filters' => $request->only(['platform', 'status', 'days'])
        ];

        return view('dashboard.logs', $data);
    }

    /**
     * API endpoint for real-time stats
     */
    public function apiStats(Request $request)
    {
        $days = $request->get('days', 7);

        return response()->json([
            'overview' => $this->getOverviewStats(),
            'platformPerformance' => $this->databaseService->getPlatformPerformance($days),
            'systemHealth' => $this->getSystemHealth(),
            'timestamp' => now()->toISOString()
        ]);
    }

    /**
     * Get overview statistics
     */
    protected function getOverviewStats(): array
    {
        return [
            'totalProducts' => Product::count(),
            'activeProducts' => Product::where('is_active', true)->count(),
            'totalPlatforms' => count(config('scraper.platforms', [])),
            'recentScrapes' => ScrapingLog::where('created_at', '>=', now()->subDays(7))->count(),
            'successfulScrapes' => ScrapingLog::where('status', 'completed')
                ->where('created_at', '>=', now()->subDays(7))
                ->count(),
            'avgPrice' => Product::where('is_active', true)->avg('price'),
            'topBrand' => Product::where('is_active', true)
                ->whereNotNull('brand')
                ->groupBy('brand')
                ->selectRaw('brand, COUNT(*) as count')
                ->orderBy('count', 'desc')
                ->first()?->brand
        ];
    }

    /**
     * Get chart data for dashboard
     */
    protected function getChartData(int $days): array
    {
        // Daily scraping activity
        $dailyActivity = ScrapingLog::selectRaw('DATE(created_at) as date, COUNT(*) as count, status')
            ->where('created_at', '>=', now()->subDays($days))
            ->groupBy('date', 'status')
            ->orderBy('date')
            ->get()
            ->groupBy('date');

        // Platform distribution
        $platformDistribution = Product::where('is_active', true)
            ->groupBy('platform')
            ->selectRaw('platform, COUNT(*) as count')
            ->get();

        // Price distribution
        $priceRanges = [
            '< 30k' => Product::where('is_active', true)->where('price', '<', 30000)->count(),
            '30k-50k' => Product::where('is_active', true)->whereBetween('price', [30000, 50000])->count(),
            '50k-80k' => Product::where('is_active', true)->whereBetween('price', [50000, 80000])->count(),
            '80k-120k' => Product::where('is_active', true)->whereBetween('price', [80000, 120000])->count(),
            '> 120k' => Product::where('is_active', true)->where('price', '>', 120000)->count(),
        ];

        return [
            'dailyActivity' => $dailyActivity,
            'platformDistribution' => $platformDistribution,
            'priceRanges' => $priceRanges
        ];
    }

    /**
     * Get platform-specific chart data
     */
    protected function getPlatformChartData(string $platform, int $days): array
    {
        // Daily product changes
        $dailyChanges = ScrapingLog::where('platform', $platform)
            ->selectRaw('DATE(created_at) as date, SUM(products_added) as added, SUM(products_updated) as updated')
            ->where('created_at', '>=', now()->subDays($days))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Brand distribution for this platform
        $brandDistribution = Product::where('platform', $platform)
            ->where('is_active', true)
            ->whereNotNull('brand')
            ->groupBy('brand')
            ->selectRaw('brand, COUNT(*) as count')
            ->orderBy('count', 'desc')
            ->limit(10)
            ->get();

        return [
            'dailyChanges' => $dailyChanges,
            'brandDistribution' => $brandDistribution
        ];
    }

    /**
     * Get system health indicators
     */
    protected function getSystemHealth(): array
    {
        $health = [
            'database' => 'healthy',
            'recentActivity' => 'healthy',
            'dataFreshness' => 'healthy',
            'errorRate' => 'healthy'
        ];

        try {
            // Check database connection
            DB::connection()->getPdo();
        } catch (\Exception $e) {
            $health['database'] = 'error';
        }

        // Check recent activity
        $recentActivity = ScrapingLog::where('created_at', '>=', now()->subHours(48))->count();
        if ($recentActivity === 0) {
            $health['recentActivity'] = 'warning';
        }

        // Check data freshness
        $freshData = Product::where('scraped_date', '>=', now()->subDays(3))->count();
        $totalData = Product::count();
        $freshPercentage = $totalData > 0 ? ($freshData / $totalData) * 100 : 0;

        if ($freshPercentage < 50) {
            $health['dataFreshness'] = 'error';
        } elseif ($freshPercentage < 80) {
            $health['dataFreshness'] = 'warning';
        }

        // Check error rate
        $recentLogs = ScrapingLog::where('created_at', '>=', now()->subDays(7))->count();
        $failedLogs = ScrapingLog::where('status', 'failed')
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        $errorRate = $recentLogs > 0 ? ($failedLogs / $recentLogs) * 100 : 0;

        if ($errorRate > 30) {
            $health['errorRate'] = 'error';
        } elseif ($errorRate > 10) {
            $health['errorRate'] = 'warning';
        }

        return $health;
    }

    /**
     * Get platform products with pagination
     */
    protected function getPlatformProducts(string $platform, Request $request)
    {
        $query = Product::where('platform', $platform);

        if ($request->filled('status')) {
            $query->where('is_active', $request->status === 'active');
        }

        return $query->orderBy('updated_at', 'desc')->paginate(20);
    }

    /**
     * Get available platforms
     */
    protected function getAvailablePlatforms(): array
    {
        return Product::distinct('platform')->pluck('platform')->toArray();
    }

    /**
     * Get available brands
     */
    protected function getAvailableBrands(): array
    {
        return Product::whereNotNull('brand')
            ->distinct('brand')
            ->orderBy('brand')
            ->pluck('brand')
            ->toArray();
    }
}
