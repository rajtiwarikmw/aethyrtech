<?php

namespace App\Http\Controllers;

use App\Models\ScrapingUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ScrapingUrlController extends Controller
{
    /**
     * Display the URL management page
     */
    public function index(Request $request)
    {
        $platform = $request->get('platform', 'all');
        $status = $request->get('status', 'all');

        $query = ScrapingUrl::query()->orderBy('created_at', 'desc');

        if ($platform !== 'all') {
            $query->where('platform', $platform);
        }

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $urls = $query->paginate(50);

        $stats = [
            'total' => ScrapingUrl::count(),
            'pending' => ScrapingUrl::where('status', 'pending')->count(),
            'processing' => ScrapingUrl::where('status', 'processing')->count(),
            'completed' => ScrapingUrl::where('status', 'completed')->count(),
            'failed' => ScrapingUrl::where('status', 'failed')->count(),
        ];

        return view('admin.scraping-urls.index', compact('urls', 'stats', 'platform', 'status'));
    }

    /**
     * Show form to add new URLs
     */
    public function create()
    {
        return view('admin.scraping-urls.create');
    }

    /**
     * Store new URLs
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'platform' => 'required|in:amazon,flipkart,vijaysales,croma,reliancedigital,blinkit,bigbasket',
            'urls' => 'required|string',
            'priority' => 'nullable|integer|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $platform = $request->input('platform');
        $urlsText = $request->input('urls');
        $priority = $request->input('priority', 0);

        // Split URLs by newline
        $urls = array_filter(array_map('trim', explode("\n", $urlsText)));

        $count = ScrapingUrl::addUrls($platform, $urls, $priority);

        return redirect()->route('admin.scraping-urls.index')
            ->with('success', "Added {$count} new URLs for {$platform}");
    }

    /**
     * Retry failed URL
     */
    public function retry($id)
    {
        $scrapingUrl = ScrapingUrl::findOrFail($id);
        $scrapingUrl->resetForRetry();

        return redirect()->back()
            ->with('success', 'URL reset for retry');
    }

    /**
     * Delete URL
     */
    public function destroy($id)
    {
        $scrapingUrl = ScrapingUrl::findOrFail($id);
        $scrapingUrl->delete();

        return redirect()->back()
            ->with('success', 'URL deleted successfully');
    }

    /**
     * Bulk delete URLs
     */
    public function bulkDelete(Request $request)
    {
        $ids = $request->input('ids', []);
        
        if (empty($ids)) {
            return redirect()->back()
                ->with('error', 'No URLs selected');
        }

        ScrapingUrl::whereIn('id', $ids)->delete();

        return redirect()->back()
            ->with('success', 'Deleted ' . count($ids) . ' URLs');
    }

    /**
     * Bulk retry URLs
     */
    public function bulkRetry(Request $request)
    {
        $ids = $request->input('ids', []);
        
        if (empty($ids)) {
            return redirect()->back()
                ->with('error', 'No URLs selected');
        }

        ScrapingUrl::whereIn('id', $ids)->update([
            'status' => 'pending',
            'error_message' => null,
        ]);

        return redirect()->back()
            ->with('success', 'Reset ' . count($ids) . ' URLs for retry');
    }
}
