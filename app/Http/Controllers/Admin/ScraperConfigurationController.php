<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ScraperConfiguration;
use App\Models\ScraperRun;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Artisan;

class ScraperConfigurationController extends Controller
{
    /**
     * Display listing of scraper configurations
     */
    public function index(Request $request)
    {
        $query = ScraperConfiguration::query();

        // Filter by platform
        if ($request->filled('platform')) {
            $query->where('platform', $request->platform);
        }

        // Filter by category
        if ($request->filled('category')) {
            $query->where('category', 'like', '%' . $request->category . '%');
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by tag
        if ($request->filled('tag')) {
            $query->where('tag', 'like', '%' . $request->tag . '%');
        }

        $configurations = $query->with('lastRun')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        // Get unique platforms and categories for filters
        $platforms = ScraperConfiguration::distinct()->pluck('platform');
        $categories = ScraperConfiguration::distinct()->pluck('category');
        $tags = ScraperConfiguration::distinct()->whereNotNull('tag')->pluck('tag');

        return view('admin.scraper-config.index', compact(
            'configurations',
            'platforms',
            'categories',
            'tags'
        ));
    }

    /**
     * Show form to create new configuration
     */
    public function create()
    {
        $platforms = ['amazon', 'flipkart', 'vijaysales', 'blinkit', 'croma', 'reliancedigital', 'bigbasket'];
        return view('admin.scraper-config.create', compact('platforms'));
    }

    /**
     * Store new configuration
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'platform' => 'required|string|max:50',
            'category' => 'required|string|max:100',
            'tag' => 'nullable|string|max:100',
            'category_url' => 'required|url',
            'description' => 'nullable|string',
            'status' => 'required|in:active,inactive,paused',
        ]);

        $configuration = ScraperConfiguration::create($validated);

        return redirect()
            ->route('admin.scraper-config.show', $configuration)
            ->with('success', 'Scraper configuration created successfully!');
    }

    /**
     * Show configuration details
     */
    public function show(ScraperConfiguration $configuration)
    {
        $configuration->load(['scraperRuns' => function ($query) {
            $query->latest()->limit(10);
        }]);

        $statistics = $configuration->getStatistics();

        return view('admin.scraper-config.show', compact('configuration', 'statistics'));
    }

    /**
     * Show edit form
     */
    public function edit(ScraperConfiguration $configuration)
    {
        $platforms = ['amazon', 'flipkart', 'vijaysales', 'blinkit', 'croma', 'reliancedigital', 'bigbasket'];
        return view('admin.scraper-config.edit', compact('configuration', 'platforms'));
    }

    /**
     * Update configuration
     */
    public function update(Request $request, ScraperConfiguration $configuration)
    {
        $validated = $request->validate([
            'platform' => 'required|string|max:50',
            'category' => 'required|string|max:100',
            'tag' => 'nullable|string|max:100',
            'category_url' => 'required|url',
            'description' => 'nullable|string',
            'status' => 'required|in:active,inactive,paused',
        ]);

        $configuration->update($validated);

        return redirect()
            ->route('admin.scraper-config.show', $configuration)
            ->with('success', 'Configuration updated successfully!');
    }

    /**
     * Delete configuration
     */
    public function destroy(ScraperConfiguration $configuration)
    {
        $configuration->delete();

        return redirect()
            ->route('admin.scraper-config.index')
            ->with('success', 'Configuration deleted successfully!');
    }

    /**
     * Run scraper for this configuration
     */
    public function run(Request $request, ScraperConfiguration $configuration)
    {
        try {
            // Generate unique 10-digit scraper ID
            $scraperId = ScraperRun::generateScraperId();

            // Create scraper run record
            $scraperRun = ScraperRun::create([
                'scraper_id' => $scraperId,
                'configuration_id' => $configuration->id,
                'platform' => $configuration->platform,
                'category' => $configuration->category,
                'tag' => $configuration->tag,
                'category_url' => $configuration->category_url,
                'description' => $configuration->description,
                'status' => 'pending',
                'triggered_by' => 'manual',
                'user_id' => auth()->id(),
            ]);

            // Mark as started
            $scraperRun->markAsStarted();

            Log::info("Starting scraper run", [
                'scraper_id' => $scraperId,
                'configuration_id' => $configuration->id,
                'platform' => $configuration->platform,
                'category' => $configuration->category,
            ]);

            // Run the scraper command with scraper_id
            // This will be handled by the updated scraper commands
            Artisan::call('scraper:run', [
                'platform' => $configuration->platform,
                '--scraper-id' => $scraperId,
                '--category-url' => $configuration->category_url,
            ]);

            return redirect()
                ->route('admin.scraper-runs.show', $scraperRun)
                ->with('success', "Scraper started! ID: {$scraperId}");

        } catch (\Exception $e) {
            Log::error("Failed to start scraper", [
                'configuration_id' => $configuration->id,
                'error' => $e->getMessage(),
            ]);

            if (isset($scraperRun)) {
                $scraperRun->markAsFailed($e->getMessage());
            }

            return back()->with('error', 'Failed to start scraper: ' . $e->getMessage());
        }
    }

    /**
     * Toggle configuration status
     */
    public function toggleStatus(ScraperConfiguration $configuration)
    {
        $newStatus = $configuration->status === 'active' ? 'inactive' : 'active';
        $configuration->update(['status' => $newStatus]);

        return back()->with('success', "Configuration {$newStatus}!");
    }
}
