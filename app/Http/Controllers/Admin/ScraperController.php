<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ScraperRun;
use App\Models\ScrapingUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ScraperController extends Controller
{
    /**
     * Display scraper dashboard
     */
    public function index()
    {
        $platforms = ['amazon', 'flipkart', 'vijaysales', 'croma', 'reliancedigital', 'blinkit', 'bigbasket'];

        // Get recent scraper runs
        $recentRuns = ScraperRun::with('triggeredBy')
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();

        // Get platform statistics
        $platformStats = [];
        foreach ($platforms as $platform) {
            $lastRun = ScraperRun::where('platform', $platform)
                ->where('status', 'completed')
                ->latest('completed_at')
                ->first();

            $nextScheduled = ScrapingUrl::where('platform', $platform)
                ->whereNotNull('next_scheduled_run')
                ->orderBy('next_scheduled_run', 'asc')
                ->first();

            $platformStats[$platform] = [
                'last_run' => $lastRun,
                'next_scheduled' => $nextScheduled,
                'is_running' => ScraperRun::where('platform', $platform)
                    ->where('status', 'running')
                    ->exists(),
            ];
        }

        // Get overall statistics
        $stats = [
            'total_runs' => ScraperRun::count(),
            'successful_runs' => ScraperRun::where('status', 'completed')->count(),
            'failed_runs' => ScraperRun::where('status', 'failed')->count(),
            'running_now' => ScraperRun::where('status', 'running')->count(),
            'total_products_scraped' => ScraperRun::sum('products_scraped'),
        ];

        return view('admin.scraper.index', compact(
            'platforms',
            'recentRuns',
            'platformStats',
            'stats'
        ));
    }

    /**
     * Trigger manual scraper run
     */
    public function runScraper(Request $request)
    {
        $request->validate([
            'platform' => 'required|string|in:amazon,flipkart,vijaysales,croma,reliancedigital,blinkit,bigbasket,all',
            'type' => 'required|string|in:products,reviews,rankings',
            'limit' => 'nullable|integer|min:1|max:1000',
        ]);

        $platform = $request->platform;
        $type = $request->type;
        $limit = $request->limit ?? 100;

        // Check if scraper is already running for this platform
        $isRunning = ScraperRun::where('platform', $platform)
            ->where('status', 'running')
            ->exists();

        if ($isRunning) {
            return response()->json([
                'success' => false,
                'message' => "Scraper is already running for {$platform}",
            ], 409);
        }

        // Create scraper run record
        $scraperRun = ScraperRun::create([
            'platform' => $platform,
            'type' => 'manual',
            'status' => 'pending',
            'triggered_by' => Auth::id(),
        ]);

        // Run scraper in background
        try {
            $command = $this->getScraperCommand($platform, $type, $limit);
            
            // Mark as running
            $scraperRun->markAsRunning();

            // Execute command asynchronously
            $this->runCommandAsync($command, $scraperRun);

            return response()->json([
                'success' => true,
                'message' => "Scraper started successfully for {$platform}",
                'scraper_run_id' => $scraperRun->id,
            ]);

        } catch (\Exception $e) {
            $scraperRun->markAsFailed($e->getMessage());
            
            Log::error("Failed to start scraper", [
                'platform' => $platform,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to start scraper: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get scraper command based on platform and type
     */
    protected function getScraperCommand($platform, $type, $limit)
    {
        switch ($type) {
            case 'products':
                if ($platform === 'all') {
                    return "scraper:run all --limit={$limit}";
                }
                return "scraper:run {$platform} --limit={$limit}";

            case 'reviews':
                if ($platform === 'all') {
                    return "scraper:reviews-platform all --limit={$limit}";
                }
                return "scraper:reviews-platform {$platform} --limit={$limit}";

            case 'rankings':
                if ($platform === 'all') {
                    return "scraper:rankings all";
                }
                return "scraper:rankings {$platform}";

            default:
                throw new \Exception("Invalid scraper type: {$type}");
        }
    }

    /**
     * Run command asynchronously
     */
    protected function runCommandAsync($command, $scraperRun)
    {
        // For Linux/Unix systems
        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            $fullCommand = sprintf(
                'php %s/artisan %s > /dev/null 2>&1 & echo $!',
                base_path(),
                $command
            );
            exec($fullCommand, $output);
            
            // Store process ID
            $pid = isset($output[0]) ? (int)$output[0] : null;
            
        } else {
            // For Windows
            $fullCommand = sprintf(
                'start /B php %s/artisan %s',
                base_path(),
                $command
            );
            pclose(popen($fullCommand, 'r'));
        }

        // Note: The actual completion will be handled by the scraper command itself
        // which should update the ScraperRun status when done
    }

    /**
     * Get scraper run status (AJAX)
     */
    public function getStatus($id)
    {
        $scraperRun = ScraperRun::findOrFail($id);

        return response()->json([
            'status' => $scraperRun->status,
            'products_scraped' => $scraperRun->products_scraped,
            'products_added' => $scraperRun->products_added,
            'products_updated' => $scraperRun->products_updated,
            'errors_count' => $scraperRun->errors_count,
            'duration' => $scraperRun->duration_human,
            'started_at' => $scraperRun->started_at?->format('Y-m-d H:i:s'),
            'completed_at' => $scraperRun->completed_at?->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * View scraper run details
     */
    public function show($id)
    {
        $scraperRun = ScraperRun::with('triggeredBy')->findOrFail($id);

        return view('admin.scraper.show', compact('scraperRun'));
    }

    /**
     * View scraper run history
     */
    public function history(Request $request)
    {
        $query = ScraperRun::with('triggeredBy');

        // Apply filters
        if ($request->filled('platform')) {
            $query->where('platform', $request->platform);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Sorting
        $query->orderBy('created_at', 'desc');

        // Paginate
        $runs = $query->paginate(50);

        // Get filter options
        $platforms = ScraperRun::select('platform')->distinct()->pluck('platform');

        return view('admin.scraper.history', compact('runs', 'platforms'));
    }

    /**
     * Stop running scraper
     */
    public function stop($id)
    {
        $scraperRun = ScraperRun::findOrFail($id);

        if ($scraperRun->status !== 'running') {
            return response()->json([
                'success' => false,
                'message' => 'Scraper is not running',
            ], 400);
        }

        // Mark as failed (stopped by user)
        $scraperRun->markAsFailed('Stopped by user');

        return response()->json([
            'success' => true,
            'message' => 'Scraper stopped successfully',
        ]);
    }

    /**
     * Delete scraper run record
     */
    public function destroy($id)
    {
        $scraperRun = ScraperRun::findOrFail($id);
        $scraperRun->delete();

        return redirect()->route('admin.scraper.history')
            ->with('success', 'Scraper run record deleted successfully');
    }
}
