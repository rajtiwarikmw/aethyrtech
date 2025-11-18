<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Keyword;
use App\Models\Product;
use Illuminate\Http\Request;

class KeywordController extends Controller
{
    /**
     * Display keywords listing
     */
    public function index(Request $request)
    {
        $query = Keyword::query();

        // Apply filters
        if ($request->filled('platform')) {
            $query->where('platform', $request->platform);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status === 'active');
        }

        if ($request->filled('search')) {
            $query->where('keyword', 'like', '%' . $request->search . '%');
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Get filter options
        $platforms = Keyword::select('platform')->distinct()->pluck('platform');

        // Paginate
        $keywords = $query->withCount('rankings')->paginate(50);

        // Get count
        $totalCount = $query->count();

        // Get statistics
        $stats = [
            'total_keywords' => Keyword::count(),
            'active_keywords' => Keyword::where('status', true)->count(),
            'inactive_keywords' => Keyword::where('status', false)->count(),
            'total_rankings' => \App\Models\ProductRanking::count(),
        ];

        return view('admin.keywords.index', compact(
            'keywords',
            'platforms',
            'totalCount',
            'stats'
        ));
    }

    /**
     * Show create keyword form
     */
    public function create()
    {
        $platforms = ['amazon', 'flipkart', 'vijaysales', 'croma', 'reliancedigital', 'blinkit', 'bigbasket'];
        $brands = Product::select('brand')->distinct()->whereNotNull('brand')->pluck('brand');

        return view('admin.keywords.create', compact('platforms', 'brands'));
    }

    /**
     * Store new keyword
     */
    public function store(Request $request)
    {
        $request->validate([
            'platform' => 'required|string|max:50',
            'keyword' => 'required|string|max:255',
            'status' => 'required|boolean',
        ]);

        // Check for duplicates
        $exists = Keyword::where('platform', $request->platform)
            ->where('keyword', $request->keyword)
            ->exists();

        if ($exists) {
            return back()->withErrors([
                'keyword' => 'This keyword already exists for the selected platform.'
            ])->withInput();
        }

        Keyword::create([
            'platform' => $request->platform,
            'keyword' => $request->keyword,
            'status' => $request->status,
        ]);

        return redirect()->route('admin.keywords.index')
            ->with('success', 'Keyword created successfully');
    }

    /**
     * Show edit keyword form
     */
    public function edit(Keyword $keyword)
    {
        $platforms = ['amazon', 'flipkart', 'vijaysales', 'croma', 'reliancedigital', 'blinkit', 'bigbasket'];

        return view('admin.keywords.edit', compact('keyword', 'platforms'));
    }

    /**
     * Update keyword
     */
    public function update(Request $request, Keyword $keyword)
    {
        $request->validate([
            'platform' => 'required|string|max:50',
            'keyword' => 'required|string|max:255',
            'status' => 'required|boolean',
        ]);

        // Check for duplicates (excluding current keyword)
        $exists = Keyword::where('platform', $request->platform)
            ->where('keyword', $request->keyword)
            ->where('id', '!=', $keyword->id)
            ->exists();

        if ($exists) {
            return back()->withErrors([
                'keyword' => 'This keyword already exists for the selected platform.'
            ])->withInput();
        }

        $keyword->update([
            'platform' => $request->platform,
            'keyword' => $request->keyword,
            'status' => $request->status,
        ]);

        return redirect()->route('admin.keywords.index')
            ->with('success', 'Keyword updated successfully');
    }

    /**
     * Delete keyword
     */
    public function destroy(Keyword $keyword)
    {
        $keyword->delete();

        return redirect()->route('admin.keywords.index')
            ->with('success', 'Keyword deleted successfully');
    }

    /**
     * Bulk create keywords
     */
    public function bulkCreate(Request $request)
    {
        $request->validate([
            'platform' => 'required|string|max:50',
            'keywords' => 'required|string',
            'status' => 'required|boolean',
        ]);

        // Split keywords by newline
        $keywords = array_filter(array_map('trim', explode("\n", $request->keywords)));

        $created = 0;
        $skipped = 0;

        foreach ($keywords as $keywordText) {
            // Check if already exists
            $exists = Keyword::where('platform', $request->platform)
                ->where('keyword', $keywordText)
                ->exists();

            if (!$exists) {
                Keyword::create([
                    'platform' => $request->platform,
                    'keyword' => $keywordText,
                    'status' => $request->status,
                ]);
                $created++;
            } else {
                $skipped++;
            }
        }

        $message = "Created {$created} keywords.";
        if ($skipped > 0) {
            $message .= " Skipped {$skipped} duplicates.";
        }

        return redirect()->route('admin.keywords.index')
            ->with('success', $message);
    }

    /**
     * Bulk update status
     */
    public function bulkUpdateStatus(Request $request)
    {
        $request->validate([
            'keyword_ids' => 'required|array',
            'keyword_ids.*' => 'exists:keywords,id',
            'status' => 'required|boolean',
        ]);

        Keyword::whereIn('id', $request->keyword_ids)
            ->update(['status' => $request->status]);

        return response()->json([
            'success' => true,
            'message' => count($request->keyword_ids) . ' keywords updated successfully',
        ]);
    }

    /**
     * Bulk delete keywords
     */
    public function bulkDelete(Request $request)
    {
        $request->validate([
            'keyword_ids' => 'required|array',
            'keyword_ids.*' => 'exists:keywords,id',
        ]);

        Keyword::whereIn('id', $request->keyword_ids)->delete();

        return response()->json([
            'success' => true,
            'message' => count($request->keyword_ids) . ' keywords deleted successfully',
        ]);
    }

    /**
     * View rankings for a keyword
     */
    public function rankings(Keyword $keyword, Request $request)
    {
        $rankings = $keyword->rankings()
            ->with('product')
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        return view('admin.keywords.rankings', compact('keyword', 'rankings'));
    }

    /**
     * Export keywords to CSV
     */
    public function export(Request $request)
    {
        $query = Keyword::query();

        // Apply same filters as index
        if ($request->filled('platform')) {
            $query->where('platform', $request->platform);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status === 'active');
        }

        $keywords = $query->get();

        $filename = 'keywords_' . date('Y-m-d_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function() use ($keywords) {
            $file = fopen('php://output', 'w');
            
            // Headers
            fputcsv($file, [
                'ID', 'Platform', 'Keyword', 'Status', 'Created At', 'Updated At'
            ]);

            // Data
            foreach ($keywords as $keyword) {
                fputcsv($file, [
                    $keyword->id,
                    $keyword->platform,
                    $keyword->keyword,
                    $keyword->status ? 'Active' : 'Inactive',
                    $keyword->created_at,
                    $keyword->updated_at,
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
