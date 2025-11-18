@extends('layouts.admin')

@section('title', 'Dashboard')

@section('content')
<div class="container-fluid">
    <h1 class="h3 mb-4">Dashboard</h1>

    {{-- Statistics Cards --}}
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card text-white bg-primary">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="card-title mb-0">Total Products</h6>
                            <h2 class="mb-0">{{ number_format(\App\Models\Product::count()) }}</h2>
                        </div>
                        <div>
                            <i class="fas fa-box fa-3x opacity-50"></i>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-transparent border-top-0">
                    <a href="{{ route('admin.products.index') }}" class="text-white text-decoration-none">
                        View all <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card text-white bg-success">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="card-title mb-0">Total Reviews</h6>
                            <h2 class="mb-0">{{ number_format(\App\Models\Review::count()) }}</h2>
                        </div>
                        <div>
                            <i class="fas fa-comments fa-3x opacity-50"></i>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-transparent border-top-0">
                    <a href="{{ route('admin.reviews.index') }}" class="text-white text-decoration-none">
                        View all <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card text-white bg-warning">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="card-title mb-0">Total Keywords</h6>
                            <h2 class="mb-0">{{ number_format(\App\Models\Keyword::count()) }}</h2>
                        </div>
                        <div>
                            <i class="fas fa-key fa-3x opacity-50"></i>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-transparent border-top-0">
                    <a href="{{ route('admin.keywords.index') }}" class="text-white text-decoration-none">
                        View all <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card text-white bg-info">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="card-title mb-0">Total Rankings</h6>
                            <h2 class="mb-0">{{ number_format(\App\Models\ProductRanking::count()) }}</h2>
                        </div>
                        <div>
                            <i class="fas fa-chart-line fa-3x opacity-50"></i>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-transparent border-top-0">
                    <a href="{{ route('admin.scraper.index') }}" class="text-white text-decoration-none">
                        View scraper <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>

    {{-- Platform Statistics --}}
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-chart-bar"></i> Products by Platform</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Platform</th>
                                    <th>Total Products</th>
                                    <th>Total Reviews</th>
                                    <th>Total Rankings</th>
                                    <th>Last Scraped</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php
                                    $platforms = ['amazon', 'flipkart', 'vijaysales', 'croma', 'reliancedigital', 'blinkit', 'bigbasket'];
                                @endphp
                                @foreach($platforms as $platform)
                                    @php
                                        $productCount = \App\Models\Product::where('platform', $platform)->count();
                                        $reviewCount = \App\Models\Review::where('platform', $platform)->count();
                                        $rankingCount = \App\Models\ProductRanking::where('platform', $platform)->count();
                                        $lastScraped = \App\Models\Product::where('platform', $platform)
                                            ->whereNotNull('last_scraped_at')
                                            ->orderBy('last_scraped_at', 'desc')
                                            ->first();
                                    @endphp
                                    <tr>
                                        <td><span class="badge bg-primary">{{ ucfirst($platform) }}</span></td>
                                        <td>{{ number_format($productCount) }}</td>
                                        <td>{{ number_format($reviewCount) }}</td>
                                        <td>{{ number_format($rankingCount) }}</td>
                                        <td>
                                            @if($lastScraped && $lastScraped->last_scraped_at)
                                                {{ $lastScraped->last_scraped_at->format('d-m-Y H:i') }}
                                                <small class="text-muted">({{ $lastScraped->last_scraped_at->diffForHumans() }})</small>
                                            @else
                                                <span class="text-muted">Never</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Recent Scraper Runs --}}
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-history"></i> Recent Scraper Runs</h5>
                    <a href="{{ route('admin.scraper.history') }}" class="btn btn-sm btn-primary">
                        View All
                    </a>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Platform</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Products</th>
                                    <th>Duration</th>
                                    <th>Started At</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php
                                    $recentRuns = \App\Models\ScraperRun::orderBy('created_at', 'desc')->limit(10)->get();
                                @endphp
                                @forelse($recentRuns as $run)
                                    <tr>
                                        <td><span class="badge bg-primary">{{ ucfirst($run->platform) }}</span></td>
                                        <td><span class="badge bg-info">{{ ucfirst($run->type) }}</span></td>
                                        <td>
                                            @if($run->status == 'completed')
                                                <span class="badge bg-success">Completed</span>
                                            @elseif($run->status == 'running')
                                                <span class="badge bg-warning">Running</span>
                                            @elseif($run->status == 'failed')
                                                <span class="badge bg-danger">Failed</span>
                                            @else
                                                <span class="badge bg-secondary">{{ ucfirst($run->status) }}</span>
                                            @endif
                                        </td>
                                        <td>{{ $run->products_scraped ?? 0 }}</td>
                                        <td>{{ $run->duration_human ?? 'N/A' }}</td>
                                        <td>{{ $run->started_at ? $run->started_at->format('d-m-Y H:i') : 'N/A' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center py-4 text-muted">
                                            No scraper runs yet
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Quick Actions --}}
    <div class="row mt-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-bolt"></i> Quick Actions</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <a href="{{ route('admin.scraper.index') }}" class="btn btn-primary btn-lg w-100">
                                <i class="fas fa-play"></i><br>
                                Run Scraper
                            </a>
                        </div>
                        <div class="col-md-3 mb-3">
                            <a href="{{ route('admin.keywords.create') }}" class="btn btn-success btn-lg w-100">
                                <i class="fas fa-plus"></i><br>
                                Add Keyword
                            </a>
                        </div>
                        <div class="col-md-3 mb-3">
                            <a href="{{ route('admin.products.index') }}" class="btn btn-info btn-lg w-100">
                                <i class="fas fa-box"></i><br>
                                View Products
                            </a>
                        </div>
                        <div class="col-md-3 mb-3">
                            <a href="{{ route('admin.reviews.index') }}" class="btn btn-warning btn-lg w-100">
                                <i class="fas fa-comments"></i><br>
                                View Reviews
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
