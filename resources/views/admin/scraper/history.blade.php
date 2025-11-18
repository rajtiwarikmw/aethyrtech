@extends('layouts.admin')

@section('title', 'Scraper History')

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-6">
            <h1 class="h3 mb-0">Scraper History</h1>
            <p class="text-muted mb-0">View all past scraper runs and their results</p>
        </div>
        <div class="col-md-6 text-end">
            <a href="{{ route('admin.scraper.index') }}" class="btn btn-primary">
                <i class="fas fa-spider"></i> Scraper Dashboard
            </a>
        </div>
    </div>

    {{-- Statistics Cards --}}
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <h6 class="text-muted mb-2">Total Runs</h6>
                    <h2 class="mb-0">{{ number_format($runs->total()) }}</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <h6 class="text-muted mb-2">Completed</h6>
                    <h2 class="mb-0 text-success">{{ number_format($runs->where('status', 'completed')->count()) }}</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <h6 class="text-muted mb-2">Running</h6>
                    <h2 class="mb-0 text-warning">{{ number_format($runs->where('status', 'running')->count()) }}</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <h6 class="text-muted mb-2">Failed</h6>
                    <h2 class="mb-0 text-danger">{{ number_format($runs->where('status', 'failed')->count()) }}</h2>
                </div>
            </div>
        </div>
    </div>

    {{-- Filters --}}
    <div class="card mb-4">
        <div class="card-body">
            <form action="{{ route('admin.scraper.history') }}" method="GET" class="row g-3">
                <div class="col-md-3">
                    <label for="platform" class="form-label">Platform</label>
                    <select class="form-select" id="platform" name="platform">
                        <option value="">All Platforms</option>
                        <option value="amazon" {{ request('platform') == 'amazon' ? 'selected' : '' }}>Amazon</option>
                        <option value="flipkart" {{ request('platform') == 'flipkart' ? 'selected' : '' }}>Flipkart</option>
                        <option value="vijaysales" {{ request('platform') == 'vijaysales' ? 'selected' : '' }}>VijaySales</option>
                        <option value="croma" {{ request('platform') == 'croma' ? 'selected' : '' }}>Croma</option>
                        <option value="reliancedigital" {{ request('platform') == 'reliancedigital' ? 'selected' : '' }}>Reliance Digital</option>
                        <option value="blinkit" {{ request('platform') == 'blinkit' ? 'selected' : '' }}>Blinkit</option>
                        <option value="bigbasket" {{ request('platform') == 'bigbasket' ? 'selected' : '' }}>BigBasket</option>
                    </select>
                </div>

                <div class="col-md-2">
                    <label for="type" class="form-label">Type</label>
                    <select class="form-select" id="type" name="type">
                        <option value="">All Types</option>
                        <option value="products" {{ request('type') == 'products' ? 'selected' : '' }}>Products</option>
                        <option value="reviews" {{ request('type') == 'reviews' ? 'selected' : '' }}>Reviews</option>
                        <option value="rankings" {{ request('type') == 'rankings' ? 'selected' : '' }}>Rankings</option>
                    </select>
                </div>

                <div class="col-md-2">
                    <label for="status" class="form-label">Status</label>
                    <select class="form-select" id="status" name="status">
                        <option value="">All Status</option>
                        <option value="running" {{ request('status') == 'running' ? 'selected' : '' }}>Running</option>
                        <option value="completed" {{ request('status') == 'completed' ? 'selected' : '' }}>Completed</option>
                        <option value="failed" {{ request('status') == 'failed' ? 'selected' : '' }}>Failed</option>
                    </select>
                </div>

                <div class="col-md-2">
                    <label for="date_from" class="form-label">From Date</label>
                    <input type="date" class="form-control" id="date_from" name="date_from" 
                           value="{{ request('date_from') }}">
                </div>

                <div class="col-md-2">
                    <label for="date_to" class="form-label">To Date</label>
                    <input type="date" class="form-control" id="date_to" name="date_to" 
                           value="{{ request('date_to') }}">
                </div>

                <div class="col-md-1">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-filter"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- History Table --}}
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-history"></i> Scraper Runs ({{ number_format($runs->total()) }} total)</h5>
            <div>
                <a href="{{ route('admin.scraper.history', array_merge(request()->all(), ['export' => 'csv'])) }}" 
                   class="btn btn-sm btn-success">
                    <i class="fas fa-download"></i> Export CSV
                </a>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Run ID</th>
                            <th>Platform</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Started</th>
                            <th>Duration</th>
                            <th>Products</th>
                            <th>Success</th>
                            <th>Errors</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($runs as $run)
                            <tr>
                                <td><code>#{{ $run->id }}</code></td>
                                <td><span class="badge bg-primary">{{ ucfirst($run->platform) }}</span></td>
                                <td><span class="badge bg-info">{{ ucfirst($run->type) }}</span></td>
                                <td>
                                    @if($run->status == 'completed')
                                        <span class="badge bg-success">Completed</span>
                                    @elseif($run->status == 'running')
                                        <span class="badge bg-warning">
                                            <i class="fas fa-spinner fa-spin"></i> Running
                                        </span>
                                    @elseif($run->status == 'failed')
                                        <span class="badge bg-danger">Failed</span>
                                    @else
                                        <span class="badge bg-secondary">{{ ucfirst($run->status) }}</span>
                                    @endif
                                </td>
                                <td>
                                    @if($run->started_at)
                                        {{ $run->started_at->format('d-m-Y H:i') }}
                                        <br><small class="text-muted">{{ $run->started_at->diffForHumans() }}</small>
                                    @else
                                        N/A
                                    @endif
                                </td>
                                <td>
                                    @if($run->started_at && $run->completed_at)
                                        {{ $run->started_at->diffInSeconds($run->completed_at) }}s
                                    @elseif($run->started_at)
                                        <span class="text-warning">Ongoing</span>
                                    @else
                                        N/A
                                    @endif
                                </td>
                                <td>{{ number_format($run->products_scraped ?? 0) }}</td>
                                <td class="text-success">{{ number_format($run->success_count ?? 0) }}</td>
                                <td class="text-danger">{{ number_format($run->error_count ?? 0) }}</td>
                                <td>
                                    <a href="{{ route('admin.scraper.show', $run) }}" 
                                       class="btn btn-sm btn-primary" title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    @if($run->status == 'running')
                                        <form action="{{ route('admin.scraper.stop', $run) }}" method="POST" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-danger" title="Stop"
                                                    onclick="return confirm('Are you sure you want to stop this scraper?')">
                                                <i class="fas fa-stop"></i>
                                            </button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="text-center py-4">
                                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                    <p class="text-muted mb-0">No scraper runs found</p>
                                    <p class="text-muted small">Try running a scraper from the dashboard</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Pagination --}}
            @if($runs->hasPages())
                <div class="d-flex justify-content-center mt-4">
                    {{ $runs->appends(request()->query())->links() }}
                </div>
            @endif
        </div>
    </div>

    {{-- Platform Statistics --}}
    @if($runs->count() > 0)
    <div class="row mt-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-chart-pie"></i> Platform Statistics</h5>
                </div>
                <div class="card-body">
                    @php
                        $platformStats = $runs->groupBy('platform')->map(function($group) {
                            return [
                                'total' => $group->count(),
                                'completed' => $group->where('status', 'completed')->count(),
                                'failed' => $group->where('status', 'failed')->count(),
                                'running' => $group->where('status', 'running')->count(),
                            ];
                        });
                    @endphp

                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Platform</th>
                                    <th>Total Runs</th>
                                    <th>Completed</th>
                                    <th>Running</th>
                                    <th>Failed</th>
                                    <th>Success Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($platformStats as $platform => $stats)
                                    <tr>
                                        <td><span class="badge bg-primary">{{ ucfirst($platform) }}</span></td>
                                        <td>{{ $stats['total'] }}</td>
                                        <td class="text-success">{{ $stats['completed'] }}</td>
                                        <td class="text-warning">{{ $stats['running'] }}</td>
                                        <td class="text-danger">{{ $stats['failed'] }}</td>
                                        <td>
                                            @if($stats['total'] > 0)
                                                {{ round(($stats['completed'] / $stats['total']) * 100, 1) }}%
                                            @else
                                                N/A
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
    @endif
</div>
@endsection

@push('scripts')
<script>
// Auto-refresh page every 30 seconds if there are running scrapers
@if($runs->where('status', 'running')->count() > 0)
setTimeout(function() {
    location.reload();
}, 30000);
@endif
</script>
@endpush
