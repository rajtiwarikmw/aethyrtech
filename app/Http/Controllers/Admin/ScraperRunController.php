<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ScraperRun;
use App\Models\Product;
use App\Models\Review;
use App\Models\ProductRanking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScraperRunController extends Controller
{
    /**
     * Display listing of scraper runs (history)
     */
    public function index(Request $request)
    {
        $query = ScraperRun::query();

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

        // Filter by scraper ID
        if ($request->filled('scraper_id')) {
            $query->where('scraper_id', $request->scraper_id);
        }

        // Filter by date range
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $runs = $query->with('configuration', 'user')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        // Get unique values for filters
        $platforms = ScraperRun::distinct()->pluck('platform');
        $categories = ScraperRun::distinct()->pluck('category');
        $tags = ScraperRun::distinct()->whereNotNull('tag')->pluck('tag');
        $scraperIds = ScraperRun::distinct()->pluck('scraper_id');

        // Statistics
        $stats = [
            'total_runs' => ScraperRun::count(),
            'completed' => ScraperRun::where('status', 'completed')->count(),
            'failed' => ScraperRun::where('status', 'failed')->count(),
            'running' => ScraperRun::where('status', 'running')->count(),
        ];

        return view('admin.scraper-runs.index', compact(
            'runs',
            'platforms',
            'categories',
            'tags',
            'scraperIds',
            'stats'
        ));
    }

    /**
     * Show scraper run details
     */
    public function show(ScraperRun $scraperRun)
    {
        $scraperRun->load('configuration', 'user');

        // Get counts
        $counts = [
            'products' => $scraperRun->products()->count(),
            'reviews' => $scraperRun->reviews()->count(),
            'rankings' => $scraperRun->rankings()->count(),
        ];

        // Get sample data
        $sampleProducts = $scraperRun->products()->limit(10)->get();
        $sampleReviews = $scraperRun->reviews()->limit(10)->get();
        $sampleRankings = $scraperRun->rankings()->limit(10)->get();

        return view('admin.scraper-runs.show', compact(
            'scraperRun',
            'counts',
            'sampleProducts',
            'sampleReviews',
            'sampleRankings'
        ));
    }

    /**
     * Compare two scraper runs
     */
    public function compare(Request $request)
    {
        $request->validate([
            'scraper_id_1' => 'required|exists:scraper_runs,scraper_id',
            'scraper_id_2' => 'required|exists:scraper_runs,scraper_id',
        ]);

        $run1 = ScraperRun::where('scraper_id', $request->scraper_id_1)->firstOrFail();
        $run2 = ScraperRun::where('scraper_id', $request->scraper_id_2)->firstOrFail();

        // Compare products
        $products1 = $run1->products()->pluck('sku')->toArray();
        $products2 = $run2->products()->pluck('sku')->toArray();

        $productsComparison = [
            'total_run1' => count($products1),
            'total_run2' => count($products2),
            'common' => count(array_intersect($products1, $products2)),
            'only_in_run1' => count(array_diff($products1, $products2)),
            'only_in_run2' => count(array_diff($products2, $products1)),
        ];

        // Compare prices for common products
        $commonSkus = array_intersect($products1, $products2);
        $priceChanges = [];
        
        foreach (array_slice($commonSkus, 0, 50) as $sku) {
            $product1 = $run1->products()->where('sku', $sku)->first();
            $product2 = $run2->products()->where('sku', $sku)->first();

            if ($product1 && $product2 && $product1->price != $product2->price) {
                $priceChanges[] = [
                    'sku' => $sku,
                    'title' => $product1->title,
                    'price_run1' => $product1->price,
                    'price_run2' => $product2->price,
                    'difference' => $product2->price - $product1->price,
                    'percentage' => $product1->price > 0 ? 
                        round((($product2->price - $product1->price) / $product1->price) * 100, 2) : 0,
                ];
            }
        }

        // Compare reviews
        $reviewsComparison = [
            'total_run1' => $run1->reviews()->count(),
            'total_run2' => $run2->reviews()->count(),
            'difference' => $run2->reviews()->count() - $run1->reviews()->count(),
        ];

        // Compare rankings
        $rankingsComparison = [
            'total_run1' => $run1->rankings()->count(),
            'total_run2' => $run2->rankings()->count(),
            'difference' => $run2->rankings()->count() - $run1->rankings()->count(),
        ];

        return view('admin.scraper-runs.compare', compact(
            'run1',
            'run2',
            'productsComparison',
            'priceChanges',
            'reviewsComparison',
            'rankingsComparison'
        ));
    }

    /**
     * Show comparison form
     */
    public function compareForm(Request $request)
    {
        // Get runs for selection
        $query = ScraperRun::query()->where('status', 'completed');

        // Pre-filter by platform/category if provided
        if ($request->filled('platform')) {
            $query->where('platform', $request->platform);
        }
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        $runs = $query->orderBy('created_at', 'desc')->get();

        // Group by category for easier selection
        $runsByCategory = $runs->groupBy('category');

        return view('admin.scraper-runs.compare-form', compact('runs', 'runsByCategory'));
    }

    /**
     * Export scraper run data
     */
    public function export(ScraperRun $scraperRun, $type)
    {
        $filename = "{$scraperRun->scraper_id}_{$type}_" . date('Ymd_His') . ".csv";

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function() use ($scraperRun, $type) {
            $file = fopen('php://output', 'w');

            if ($type === 'products') {
                // Export products
                fputcsv($file, ['SKU', 'Title', 'Brand', 'Price', 'Sale Price', 'Rating', 'Review Count', 'URL']);
                
                $scraperRun->products()->chunk(100, function ($products) use ($file) {
                    foreach ($products as $product) {
                        fputcsv($file, [
                            $product->sku,
                            $product->title,
                            $product->brand,
                            $product->price,
                            $product->sale_price,
                            $product->rating,
                            $product->review_count,
                            $product->product_url,
                        ]);
                    }
                });
            } elseif ($type === 'reviews') {
                // Export reviews
                fputcsv($file, ['Product SKU', 'Reviewer', 'Rating', 'Title', 'Text', 'Date', 'Verified']);
                
                $scraperRun->reviews()->chunk(100, function ($reviews) use ($file) {
                    foreach ($reviews as $review) {
                        fputcsv($file, [
                            $review->product->sku ?? 'N/A',
                            $review->reviewer_name,
                            $review->rating,
                            $review->review_title,
                            $review->review_text,
                            $review->review_date,
                            $review->verified_purchase ? 'Yes' : 'No',
                        ]);
                    }
                });
            } elseif ($type === 'rankings') {
                // Export rankings
                fputcsv($file, ['SKU', 'Keyword', 'Position', 'Page']);
                
                $scraperRun->rankings()->chunk(100, function ($rankings) use ($file) {
                    foreach ($rankings as $ranking) {
                        fputcsv($file, [
                            $ranking->sku,
                            $ranking->keyword->keyword ?? 'N/A',
                            $ranking->position,
                            $ranking->page,
                        ]);
                    }
                });
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
