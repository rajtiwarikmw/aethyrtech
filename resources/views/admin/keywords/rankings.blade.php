@extends('layouts.admin')

@section('title', 'Keyword Rankings')

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-6">
            <h1 class="h3 mb-0">Rankings for: <span class="text-primary">{{ $keyword->keyword }}</span></h1>
            <p class="text-muted mb-0">
                <span class="badge bg-primary">{{ ucfirst($keyword->platform) }}</span>
                @if($keyword->status == 'active')
                    <span class="badge bg-success">Active</span>
                @else
                    <span class="badge bg-secondary">Inactive</span>
                @endif
            </p>
        </div>
        <div class="col-md-6 text-end">
            <a href="{{ route('admin.keywords.edit', $keyword) }}" class="btn btn-warning">
                <i class="fas fa-edit"></i> Edit Keyword
            </a>
            <a href="{{ route('admin.keywords.index') }}" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Keywords
            </a>
        </div>
    </div>

    {{-- Statistics Cards --}}
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <h6 class="text-muted mb-2">Total Rankings</h6>
                    <h2 class="mb-0">{{ number_format($rankings->total()) }}</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <h6 class="text-muted mb-2">Unique Products</h6>
                    <h2 class="mb-0">{{ number_format($rankings->pluck('product_id')->unique()->count()) }}</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <h6 class="text-muted mb-2">Avg Position</h6>
                    <h2 class="mb-0">{{ number_format($rankings->avg('position'), 1) }}</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <h6 class="text-muted mb-2">Last Tracked</h6>
                    <h2 class="mb-0 small">{{ $rankings->first() ? $rankings->first()->created_at->diffForHumans() : 'Never' }}</h2>
                </div>
            </div>
        </div>
    </div>

    {{-- Filters --}}
    <div class="card mb-4">
        <div class="card-body">
            <form action="{{ route('admin.keywords.rankings', $keyword) }}" method="GET" class="row g-3">
                <div class="col-md-3">
                    <label for="sku" class="form-label">Product SKU</label>
                    <input type="text" class="form-control" id="sku" name="sku" 
                           value="{{ request('sku') }}" placeholder="Filter by SKU">
                </div>

                <div class="col-md-2">
                    <label for="position_min" class="form-label">Min Position</label>
                    <input type="number" class="form-control" id="position_min" name="position_min" 
                           value="{{ request('position_min') }}" placeholder="e.g., 1">
                </div>

                <div class="col-md-2">
                    <label for="position_max" class="form-label">Max Position</label>
                    <input type="number" class="form-control" id="position_max" name="position_max" 
                           value="{{ request('position_max') }}" placeholder="e.g., 20">
                </div>

                <div class="col-md-2">
                    <label for="page" class="form-label">Page</label>
                    <select class="form-select" id="page" name="page">
                        <option value="">All Pages</option>
                        @for($i = 1; $i <= 5; $i++)
                            <option value="{{ $i }}" {{ request('page') == $i ? 'selected' : '' }}>Page {{ $i }}</option>
                        @endfor
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">&nbsp;</label>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- Rankings Table --}}
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-chart-line"></i> Rankings ({{ number_format($rankings->total()) }} total)</h5>
            <div>
                <a href="{{ route('admin.keywords.rankings', array_merge(request()->all(), ['export' => 'csv', 'keyword' => $keyword->id])) }}" 
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
                            <th>Product SKU</th>
                            <th>Product Title</th>
                            <th>Position</th>
                            <th>Page</th>
                            <th>Date Tracked</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rankings as $ranking)
                            <tr>
                                <td><code>{{ $ranking->sku }}</code></td>
                                <td>
                                    @if($ranking->product)
                                        {{ \Str::limit($ranking->product->title, 50) }}
                                    @else
                                        <span class="text-muted">Product not found</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge bg-primary">#{{ $ranking->position }}</span>
                                </td>
                                <td>Page {{ $ranking->page }}</td>
                                <td>{{ $ranking->created_at->format('d-m-Y H:i') }}</td>
                                <td>
                                    @if($ranking->product)
                                        <a href="{{ route('admin.products.show', $ranking->product) }}" 
                                           class="btn btn-sm btn-primary" title="View Product">
                                            <i class="fas fa-box"></i>
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center py-4">
                                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                    <p class="text-muted mb-0">No rankings found for this keyword</p>
                                    <p class="text-muted small">Try running the ranking scraper</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Pagination --}}
            @if($rankings->hasPages())
                <div class="d-flex justify-content-center mt-4">
                    {{ $rankings->appends(request()->query())->links() }}
                </div>
            @endif
        </div>
    </div>

    {{-- Position Distribution Chart --}}
    @if($rankings->count() > 0)
    <div class="row mt-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-chart-bar"></i> Position Distribution</h5>
                </div>
                <div class="card-body">
                    @php
                        $positionGroups = [
                            '1-10' => 0,
                            '11-20' => 0,
                            '21-30' => 0,
                            '31-40' => 0,
                            '41-50' => 0,
                            '50+' => 0,
                        ];
                        
                        foreach($rankings as $ranking) {
                            $pos = $ranking->position;
                            if($pos <= 10) $positionGroups['1-10']++;
                            elseif($pos <= 20) $positionGroups['11-20']++;
                            elseif($pos <= 30) $positionGroups['21-30']++;
                            elseif($pos <= 40) $positionGroups['31-40']++;
                            elseif($pos <= 50) $positionGroups['41-50']++;
                            else $positionGroups['50+']++;
                        }
                    @endphp

                    <div class="row">
                        @foreach($positionGroups as $range => $count)
                            <div class="col-md-2">
                                <div class="card text-center">
                                    <div class="card-body">
                                        <h6 class="text-muted">Position {{ $range }}</h6>
                                        <h3>{{ $count }}</h3>
                                        <small class="text-muted">{{ $rankings->count() > 0 ? round(($count / $rankings->count()) * 100, 1) : 0 }}%</small>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif
</div>
@endsection
