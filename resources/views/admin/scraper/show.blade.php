@extends('layouts.admin')

@section('title', 'Scraper Run Details')

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-6">
            <h1 class="h3 mb-0">Scraper Run Details</h1>
            <p class="text-muted mb-0">Run ID: #{{ $run->id }}</p>
        </div>
        <div class="col-md-6 text-end">
            <a href="{{ route('admin.scraper.history') }}" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to History
            </a>
            @if($run->status == 'running')
                <form action="{{ route('admin.scraper.stop', $run) }}" method="POST" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-danger" onclick="return confirm('Are you sure you want to stop this scraper?')">
                        <i class="fas fa-stop"></i> Stop Scraper
                    </button>
                </form>
            @endif
        </div>
    </div>

    {{-- Status Alert --}}
    @if($run->status == 'running')
        <div class="alert alert-warning">
            <i class="fas fa-spinner fa-spin"></i> This scraper is currently running...
        </div>
    @elseif($run->status == 'completed')
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> This scraper run completed successfully!
        </div>
    @elseif($run->status == 'failed')
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i> This scraper run failed. Check error details below.
        </div>
    @endif

    <div class="row">
        <div class="col-md-8">
            {{-- Run Information --}}
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-info-circle"></i> Run Information</h5>
                </div>
                <div class="card-body">
                    <table class="table table-bordered">
                        <tr>
                            <th width="200">Run ID</th>
                            <td><code>#{{ $run->id }}</code></td>
                        </tr>
                        <tr>
                            <th>Platform</th>
                            <td><span class="badge bg-primary">{{ ucfirst($run->platform) }}</span></td>
                        </tr>
                        <tr>
                            <th>Type</th>
                            <td><span class="badge bg-info">{{ ucfirst($run->type) }}</span></td>
                        </tr>
                        <tr>
                            <th>Status</th>
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
                        </tr>
                        <tr>
                            <th>Started At</th>
                            <td>{{ $run->started_at ? $run->started_at->format('d-m-Y H:i:s') : 'N/A' }}</td>
                        </tr>
                        <tr>
                            <th>Completed At</th>
                            <td>{{ $run->completed_at ? $run->completed_at->format('d-m-Y H:i:s') : 'N/A' }}</td>
                        </tr>
                        <tr>
                            <th>Duration</th>
                            <td>
                                @if($run->started_at && $run->completed_at)
                                    {{ $run->started_at->diff($run->completed_at)->format('%H:%I:%S') }}
                                @elseif($run->started_at)
                                    {{ $run->started_at->diffForHumans(null, true) }} (ongoing)
                                @else
                                    N/A
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th>Triggered By</th>
                            <td>{{ $run->triggered_by ?? 'System' }}</td>
                        </tr>
                    </table>
                </div>
            </div>

            {{-- Statistics --}}
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-chart-bar"></i> Statistics</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="card text-center">
                                <div class="card-body">
                                    <h6 class="text-muted">Products Scraped</h6>
                                    <h2>{{ number_format($run->products_scraped ?? 0) }}</h2>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card text-center">
                                <div class="card-body">
                                    <h6 class="text-muted">Success Count</h6>
                                    <h2 class="text-success">{{ number_format($run->success_count ?? 0) }}</h2>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card text-center">
                                <div class="card-body">
                                    <h6 class="text-muted">Error Count</h6>
                                    <h2 class="text-danger">{{ number_format($run->error_count ?? 0) }}</h2>
                                </div>
                            </div>
                        </div>
                    </div>

                    @if($run->type == 'reviews')
                        <div class="row mt-3">
                            <div class="col-md-6">
                                <div class="card text-center">
                                    <div class="card-body">
                                        <h6 class="text-muted">Reviews Found</h6>
                                        <h3>{{ number_format($run->reviews_found ?? 0) }}</h3>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card text-center">
                                    <div class="card-body">
                                        <h6 class="text-muted">Reviews Added</h6>
                                        <h3>{{ number_format($run->reviews_added ?? 0) }}</h3>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endif

                    @if($run->type == 'rankings')
                        <div class="row mt-3">
                            <div class="col-md-6">
                                <div class="card text-center">
                                    <div class="card-body">
                                        <h6 class="text-muted">Keywords Processed</h6>
                                        <h3>{{ number_format($run->keywords_processed ?? 0) }}</h3>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card text-center">
                                    <div class="card-body">
                                        <h6 class="text-muted">Rankings Recorded</h6>
                                        <h3>{{ number_format($run->rankings_recorded ?? 0) }}</h3>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Error Details --}}
            @if($run->error_message)
            <div class="card mb-4">
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0"><i class="fas fa-exclamation-triangle"></i> Error Details</h5>
                </div>
                <div class="card-body">
                    <pre class="bg-light p-3 rounded">{{ $run->error_message }}</pre>
                </div>
            </div>
            @endif

            {{-- Logs --}}
            @if($run->logs)
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-file-alt"></i> Execution Logs</h5>
                </div>
                <div class="card-body">
                    <pre class="bg-dark text-light p-3 rounded" style="max-height: 400px; overflow-y: auto;">{{ $run->logs }}</pre>
                </div>
            </div>
            @endif
        </div>

        <div class="col-md-4">
            {{-- Quick Summary --}}
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-tachometer-alt"></i> Quick Summary</h5>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2">
                        <span>Platform:</span>
                        <strong>{{ ucfirst($run->platform) }}</strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Type:</span>
                        <strong>{{ ucfirst($run->type) }}</strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Status:</span>
                        <strong>{{ ucfirst($run->status) }}</strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Duration:</span>
                        <strong>
                            @if($run->started_at && $run->completed_at)
                                {{ $run->started_at->diffInSeconds($run->completed_at) }}s
                            @else
                                N/A
                            @endif
                        </strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Success Rate:</span>
                        <strong>
                            @if($run->products_scraped > 0)
                                {{ round((($run->success_count ?? 0) / $run->products_scraped) * 100, 1) }}%
                            @else
                                N/A
                            @endif
                        </strong>
                    </div>
                </div>
            </div>

            {{-- Actions --}}
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-bolt"></i> Actions</h5>
                </div>
                <div class="card-body">
                    @if($run->status == 'running')
                        <form action="{{ route('admin.scraper.stop', $run) }}" method="POST">
                            @csrf
                            <button type="submit" class="btn btn-danger btn-sm w-100 mb-2" 
                                    onclick="return confirm('Are you sure you want to stop this scraper?')">
                                <i class="fas fa-stop"></i> Stop Scraper
                            </button>
                        </form>
                    @endif

                    @if($run->status == 'completed' || $run->status == 'failed')
                        <form action="{{ route('admin.scraper.run') }}" method="POST">
                            @csrf
                            <input type="hidden" name="platform" value="{{ $run->platform }}">
                            <input type="hidden" name="type" value="{{ $run->type }}">
                            <button type="submit" class="btn btn-primary btn-sm w-100 mb-2">
                                <i class="fas fa-redo"></i> Run Again
                            </button>
                        </form>
                    @endif

                    <a href="{{ route('admin.scraper.history') }}" class="btn btn-secondary btn-sm w-100">
                        <i class="fas fa-history"></i> View All Runs
                    </a>
                </div>
            </div>

            {{-- Related Links --}}
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-link"></i> Related Links</h5>
                </div>
                <div class="card-body">
                    @if($run->type == 'products')
                        <a href="{{ route('admin.products.index', ['platform' => $run->platform]) }}" 
                           class="btn btn-info btn-sm w-100 mb-2">
                            <i class="fas fa-box"></i> View Products
                        </a>
                    @endif

                    @if($run->type == 'reviews')
                        <a href="{{ route('admin.reviews.index', ['platform' => $run->platform]) }}" 
                           class="btn btn-info btn-sm w-100 mb-2">
                            <i class="fas fa-comments"></i> View Reviews
                        </a>
                    @endif

                    @if($run->type == 'rankings')
                        <a href="{{ route('admin.keywords.index', ['platform' => $run->platform]) }}" 
                           class="btn btn-info btn-sm w-100 mb-2">
                            <i class="fas fa-key"></i> View Keywords
                        </a>
                    @endif

                    <a href="{{ route('admin.scraper.index') }}" class="btn btn-success btn-sm w-100">
                        <i class="fas fa-spider"></i> Scraper Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
@if($run->status == 'running')
<script>
// Auto-refresh page every 10 seconds if scraper is running
setTimeout(function() {
    location.reload();
}, 10000);
</script>
@endif
@endpush
