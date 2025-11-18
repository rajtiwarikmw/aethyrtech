<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReviewController extends Controller
{
    /**
     * Display reviews listing with filters
     */
    public function index(Request $request)
    {
        $query = Review::with('product');

        // Apply filters
        if ($request->filled('platform')) {
            $query->where('platform', $request->platform);
        }

        if ($request->filled('brand')) {
            $query->whereHas('product', function($q) use ($request) {
                $q->where('brand', $request->brand);
            });
        }

        if ($request->filled('sku')) {
            $query->whereHas('product', function($q) use ($request) {
                $q->where('sku', $request->sku);
            });
        }

        if ($request->filled('rating')) {
            $query->where('rating', $request->rating);
        }

        if ($request->filled('has_images')) {
            if ($request->has_images === 'yes') {
                $query->whereNotNull('review_images')
                      ->where('review_images', '!=', '[]')
                      ->where('review_images', '!=', 'null');
            } else {
                $query->where(function($q) {
                    $q->whereNull('review_images')
                      ->orWhere('review_images', '[]')
                      ->orWhere('review_images', 'null');
                });
            }
        }

        if ($request->filled('verified_purchase')) {
            $query->where('verified_purchase', $request->verified_purchase === 'yes');
        }

        // Sentiment filter (based on rating, not database column)
        if ($request->filled('sentiment')) {
            switch ($request->sentiment) {
                case 'positive':
                    $query->where('rating', '>=', 4);
                    break;
                case 'critical':
                    $query->where('rating', '<=', 2);
                    break;
                case 'neutral':
                    $query->whereBetween('rating', [2.1, 3.9]);
                    break;
            }
        }

        // Keyword search in review text
        if ($request->filled('keyword')) {
            $keyword = $request->keyword;
            $query->where(function($q) use ($keyword) {
                $q->where('review_text', 'like', "%{$keyword}%")
                  ->orWhere('review_title', 'like', "%{$keyword}%");
            });
        }

        // Has videos filter
        if ($request->filled('has_videos')) {
            if ($request->has_videos === 'yes') {
                $query->whereNotNull('video_urls')
                      ->where('video_urls', '!=', '[]')
                      ->where('video_urls', '!=', 'null');
            } else {
                $query->where(function($q) {
                    $q->whereNull('video_urls')
                      ->orWhere('video_urls', '[]')
                      ->orWhere('video_urls', 'null');
                });
            }
        }

        if ($request->filled('date_from')) {
            $query->whereDate('review_date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('review_date', '<=', $request->date_to);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('review_text', 'like', "%{$search}%")
                  ->orWhere('review_title', 'like', "%{$search}%")
                  ->orWhere('reviewer_name', 'like', "%{$search}%");
            });
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'review_date');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Get filter options
        $platforms = Review::select('platform')->distinct()->pluck('platform');
        $brands = Product::select('brand')->distinct()->whereNotNull('brand')->pluck('brand');
        $ratings = [1, 2, 3, 4, 5];

        // Paginate
        $reviews = $query->paginate(50);

        // Get count
        $totalCount = $query->count();

        // Get statistics
        $stats = [
            'total_reviews' => Review::count(),
            'with_images' => Review::whereNotNull('review_images')
                ->where('review_images', '!=', '[]')
                ->where('review_images', '!=', 'null')
                ->count(),
            'verified_purchases' => Review::where('verified_purchase', true)->count(),
            'average_rating' => round(Review::avg('rating'), 2),
        ];

        return view('admin.reviews.index', compact(
            'reviews',
            'platforms',
            'brands',
            'ratings',
            'totalCount',
            'stats'
        ));
    }

    /**
     * Show review details
     */
    public function show(Review $review)
    {
        $review->load('product');

        return view('admin.reviews.show', compact('review'));
    }

    /**
     * Delete review
     */
    public function destroy(Review $review)
    {
        $review->delete();

        return redirect()->route('admin.reviews.index')
            ->with('success', 'Review deleted successfully');
    }

    /**
     * Bulk delete reviews
     */
    public function bulkDelete(Request $request)
    {
        $request->validate([
            'review_ids' => 'required|array',
            'review_ids.*' => 'exists:reviews,id',
        ]);

        Review::whereIn('id', $request->review_ids)->delete();

        return response()->json([
            'success' => true,
            'message' => count($request->review_ids) . ' reviews deleted successfully',
        ]);
    }

    /**
     * Export reviews to CSV
     */
    public function export(Request $request)
    {
        $query = Review::with('product');

        // Apply same filters as index
        if ($request->filled('platform')) {
            $query->where('platform', $request->platform);
        }

        if ($request->filled('brand')) {
            $query->whereHas('product', function($q) use ($request) {
                $q->where('brand', $request->brand);
            });
        }

        if ($request->filled('sku')) {
            $query->whereHas('product', function($q) use ($request) {
                $q->where('sku', $request->sku);
            });
        }

        if ($request->filled('rating')) {
            $query->where('rating', $request->rating);
        }

        $reviews = $query->get();

        $filename = 'reviews_' . date('Y-m-d_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function() use ($reviews) {
            $file = fopen('php://output', 'w');
            
            // Headers
            fputcsv($file, [
                'ID', 'Platform', 'Brand', 'SKU', 'Product Title', 'Reviewer Name',
                'Rating', 'Review Title', 'Review Text', 'Review Date', 'Verified Purchase',
                'Helpful Count', 'Has Images', 'Created At'
            ]);

            // Data
            foreach ($reviews as $review) {
                $hasImages = $review->review_images && 
                            $review->review_images != '[]' && 
                            $review->review_images != 'null' ? 'Yes' : 'No';

                fputcsv($file, [
                    $review->id,
                    $review->platform,
                    $review->product->brand ?? 'N/A',
                    $review->product->sku ?? 'N/A',
                    $review->product->title ?? 'N/A',
                    $review->reviewer_name,
                    $review->rating,
                    $review->review_title,
                    $review->review_text,
                    $review->review_date,
                    $review->verified_purchase ? 'Yes' : 'No',
                    $review->helpful_count,
                    $hasImages,
                    $review->created_at,
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
