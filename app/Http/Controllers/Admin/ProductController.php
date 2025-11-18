<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Review;
use App\Models\ProductRanking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    /**
     * Display product listing with enhanced filters
     */
    public function index(Request $request)
    {
        $query = Product::query();

        // Get available scrape dates for filter dropdown
        $scrapeDates = Product::select(DB::raw('DATE(scraped_date) as scrape_date'))
            ->whereNotNull('scraped_date')
            ->groupBy('scrape_date')
            ->orderBy('scrape_date', 'desc')
            ->pluck('scrape_date');

        // Apply filters
        if ($request->filled('platform')) {
            $query->where('platform', $request->platform);
        }

        if ($request->filled('brand')) {
            $query->where('brand', $request->brand);
        }

        if ($request->filled('include_exclude')) {
            $query->where('include_exclude', $request->include_exclude);
        } else {
            // Default: show only included products
            $query->where('include_exclude', 'include');
        }

        if ($request->filled('scrape_date')) {
            $query->whereDate('last_scraped_at', $request->scrape_date);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%")
                  ->orWhere('brand', 'like', "%{$search}%");
            });
        }

        // Default sorting: Last Scraped Date (Descending)
        $sortBy = $request->get('sort_by', 'scraped_date');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Get unique platforms and brands for filters
        $platforms = Product::select('platform')->distinct()->pluck('platform');
        $brands = Product::select('brand')->distinct()->whereNotNull('brand')->pluck('brand');

        // Paginate results
        $products = $query->with(['reviews', 'rankings'])->paginate(50);

        // Get count for current filters
        $totalCount = $query->count();

        return view('admin.products.index', compact(
            'products',
            'platforms',
            'brands',
            'scrapeDates',
            'totalCount'
        ));
    }

    /**
     * Update include/exclude status
     */
    public function updateIncludeExclude(Request $request, Product $product)
    {
        $request->validate([
            'include_exclude' => 'required|in:include,exclude',
        ]);

        $product->update([
            'include_exclude' => $request->include_exclude,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Product status updated successfully',
        ]);
    }

    /**
     * Bulk update include/exclude status
     */
    public function bulkUpdateIncludeExclude(Request $request)
    {
        $request->validate([
            'product_ids' => 'required|array',
            'product_ids.*' => 'exists:products,id',
            'include_exclude' => 'required|in:include,exclude',
        ]);

        Product::whereIn('id', $request->product_ids)
            ->update(['include_exclude' => $request->include_exclude]);

        return response()->json([
            'success' => true,
            'message' => count($request->product_ids) . ' products updated successfully',
        ]);
    }

    /**
     * Show product details
     */
    public function show(Product $product)
    {
        $product->load(['reviews', 'rankings.keyword']);

        return view('admin.products.show', compact('product'));
    }

    /**
     * Get reviews for a specific product (AJAX)
     */
    public function getReviews(Product $product, Request $request)
    {
        $reviews = $product->reviews()
            ->orderBy('review_date', 'desc')
            ->paginate(20);

        if ($request->ajax()) {
            return response()->json([
                'reviews' => $reviews,
            ]);
        }

        return view('admin.products.reviews', compact('product', 'reviews'));
    }

    /**
     * Get rankings for a specific product (AJAX)
     */
    public function getRankings(Product $product, Request $request)
    {
        $rankings = $product->rankings()
            ->with('keyword')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        if ($request->ajax()) {
            return response()->json([
                'rankings' => $rankings,
            ]);
        }

        return view('admin.products.rankings', compact('product', 'rankings'));
    }

    /**
     * Export products to CSV
     */
    public function export(Request $request)
    {
        $query = Product::query();

        // Apply same filters as index
        if ($request->filled('platform')) {
            $query->where('platform', $request->platform);
        }

        if ($request->filled('brand')) {
            $query->where('brand', $request->brand);
        }

        if ($request->filled('include_exclude')) {
            $query->where('include_exclude', $request->include_exclude);
        }

        if ($request->filled('scrape_date')) {
            $query->whereDate('last_scraped_at', $request->scrape_date);
        }

        $products = $query->get();

        $filename = 'products_' . date('Y-m-d_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function() use ($products) {
            $file = fopen('php://output', 'w');
            
            // Headers
            fputcsv($file, [
                'ID', 'Platform', 'SKU', 'Title', 'Brand', 'Price', 'Sale Price',
                'Rating', 'Reviews Count', 'Include/Exclude', 'Last Scraped', 'Created At'
            ]);

            // Data
            foreach ($products as $product) {
                fputcsv($file, [
                    $product->id,
                    $product->platform,
                    $product->sku,
                    $product->title,
                    $product->brand,
                    $product->price,
                    $product->sale_price,
                    $product->rating,
                    $product->reviews_count,
                    $product->include_exclude,
                    $product->last_scraped_at,
                    $product->created_at,
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
