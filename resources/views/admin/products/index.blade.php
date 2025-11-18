@extends('layouts.admin')

@section('title', 'Products Management')

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-6">
            <h1 class="h3 mb-0">Products Management</h1>
            <p class="text-muted">Total: {{ number_format($totalCount) }} products</p>
        </div>
        <div class="col-md-6 text-end">
            <a href="{{ route('admin.products.export', request()->query()) }}" class="btn btn-success">
                <i class="fas fa-download"></i> Export CSV
            </a>
        </div>
    </div>

    {{-- Filters --}}
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-filter"></i> Filters</h5>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('admin.products.index') }}" id="filterForm">
                <div class="row g-3">
                    {{-- Platform Filter --}}
                    <div class="col-md-3">
                        <label class="form-label">Platform</label>
                        <select name="platform" class="form-select">
                            <option value="">All Platforms</option>
                            @foreach($platforms as $platform)
                                <option value="{{ $platform }}" {{ request('platform') == $platform ? 'selected' : '' }}>
                                    {{ ucfirst($platform) }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Brand Filter --}}
                    <div class="col-md-3">
                        <label class="form-label">Brand</label>
                        <select name="brand" class="form-select">
                            <option value="">All Brands</option>
                            @foreach($brands as $brand)
                                <option value="{{ $brand }}" {{ request('brand') == $brand ? 'selected' : '' }}>
                                    {{ $brand }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Include/Exclude Filter --}}
                    <div class="col-md-2">
                        <label class="form-label">Status</label>
                        <select name="include_exclude" class="form-select">
                            <option value="">All</option>
                            <option value="include" {{ request('include_exclude') == 'include' ? 'selected' : '' }}>Include</option>
                            <option value="exclude" {{ request('include_exclude') == 'exclude' ? 'selected' : '' }}>Exclude</option>
                        </select>
                    </div>

                    {{-- Scrape Date Filter --}}
                    <div class="col-md-3">
                        <label class="form-label">Last Scraped Date</label>
                        <select name="scrape_date" class="form-select">
                            <option value="">All Dates</option>
                            @foreach($scrapeDates as $date)
                                <option value="{{ $date }}" {{ request('scrape_date') == $date ? 'selected' : '' }}>
                                    {{ \Carbon\Carbon::parse($date)->format('d-m-Y') }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Search --}}
                    <div class="col-md-12">
                        <label class="form-label">Search</label>
                        <div class="input-group">
                            <input type="text" name="search" class="form-control" placeholder="Search by title, SKU, or brand..." value="{{ request('search') }}">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> Search
                            </button>
                            <a href="{{ route('admin.products.index') }}" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Clear
                            </a>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- Bulk Actions --}}
    <div class="card mb-3">
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="selectAll">
                        <label class="form-check-label" for="selectAll">
                            Select All
                        </label>
                        <span id="selectedCount" class="ms-2 text-muted">(0 selected)</span>
                    </div>
                </div>
                <div class="col-md-6 text-end">
                    <button type="button" class="btn btn-sm btn-success" onclick="bulkUpdateStatus('include')" disabled id="bulkIncludeBtn">
                        <i class="fas fa-check"></i> Mark as Include
                    </button>
                    <button type="button" class="btn btn-sm btn-warning" onclick="bulkUpdateStatus('exclude')" disabled id="bulkExcludeBtn">
                        <i class="fas fa-times"></i> Mark as Exclude
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Products Table --}}
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th width="30"><input type="checkbox" id="selectAllTable"></th>
                            <th>Platform</th>
                            <th>SKU</th>
                            <th>Title</th>
                            <th>Brand</th>
                            <th>Price</th>
                            <th>Rating</th>
                            <th>Status</th>
                            <th>
                                <a href="{{ route('admin.products.index', array_merge(request()->query(), ['sort_by' => 'last_scraped_at', 'sort_order' => request('sort_order') == 'asc' ? 'desc' : 'asc'])) }}">
                                    Last Scraped
                                    @if(request('sort_by') == 'last_scraped_at')
                                        <i class="fas fa-sort-{{ request('sort_order') == 'asc' ? 'up' : 'down' }}"></i>
                                    @endif
                                </a>
                            </th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($products as $product)
                            <tr>
                                <td><input type="checkbox" class="product-checkbox" value="{{ $product->id }}"></td>
                                <td><span class="badge bg-primary">{{ ucfirst($product->platform) }}</span></td>
                                <td><code>{{ $product->sku }}</code></td>
                                <td>{{ \Str::limit($product->title, 50) }}</td>
                                <td>{{ $product->brand ?? 'N/A' }}</td>
                                <td>₹{{ number_format($product->sale_price ?? $product->price, 2) }}</td>
                                <td>
                                    @if($product->rating)
                                        <span class="badge bg-warning">{{ $product->rating }} ⭐</span>
                                    @else
                                        N/A
                                    @endif
                                </td>
                                <td>
                                    <select class="form-select form-select-sm" onchange="updateProductStatus({{ $product->id }}, this.value)">
                                        <option value="include" {{ $product->include_exclude == 'include' ? 'selected' : '' }}>Include</option>
                                        <option value="exclude" {{ $product->include_exclude == 'exclude' ? 'selected' : '' }}>Exclude</option>
                                    </select>
                                </td>
                                <td>{{ $product->last_scraped_at ? $product->last_scraped_at->format('d-m-Y H:i') : 'Never' }}</td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="{{ route('admin.products.show', $product) }}" class="btn btn-info" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <button type="button" class="btn btn-primary" onclick="viewReviews({{ $product->id }})" title="View Reviews">
                                            <i class="fas fa-comments"></i>
                                            <span class="badge bg-light text-dark">{{ $product->reviews->count() }}</span>
                                        </button>
                                        <button type="button" class="btn btn-success" onclick="viewRankings({{ $product->id }})" title="View Rankings">
                                            <i class="fas fa-chart-line"></i>
                                            <span class="badge bg-light text-dark">{{ $product->rankings->count() }}</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="text-center py-4">
                                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">No products found</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Pagination --}}
            <div class="mt-3">
                {{ $products->links() }}
            </div>
        </div>
    </div>
</div>

{{-- Reviews Modal --}}
<div class="modal fade" id="reviewsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Product Reviews</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="reviewsContent">
                <div class="text-center py-5">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Rankings Modal --}}
<div class="modal fade" id="rankingsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Product Rankings</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="rankingsContent">
                <div class="text-center py-5">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
// Select all functionality
document.getElementById('selectAll').addEventListener('change', function() {
    document.querySelectorAll('.product-checkbox').forEach(checkbox => {
        checkbox.checked = this.checked;
    });
    updateSelectedCount();
});

document.querySelectorAll('.product-checkbox').forEach(checkbox => {
    checkbox.addEventListener('change', updateSelectedCount);
});

function updateSelectedCount() {
    const selected = document.querySelectorAll('.product-checkbox:checked').length;
    document.getElementById('selectedCount').textContent = `(${selected} selected)`;
    
    const bulkButtons = ['bulkIncludeBtn', 'bulkExcludeBtn'];
    bulkButtons.forEach(btnId => {
        document.getElementById(btnId).disabled = selected === 0;
    });
}

// Update single product status
function updateProductStatus(productId, status) {
    fetch(`/admin/products/${productId}/update-status`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify({ include_exclude: status })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('Success', data.message, 'success');
        }
    })
    .catch(error => {
        showToast('Error', 'Failed to update product status', 'error');
    });
}

// Bulk update status
function bulkUpdateStatus(status) {
    const selected = Array.from(document.querySelectorAll('.product-checkbox:checked')).map(cb => cb.value);
    
    if (selected.length === 0) return;
    
    if (!confirm(`Are you sure you want to mark ${selected.length} products as ${status}?`)) return;
    
    fetch('/admin/products/bulk-update-status', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify({ product_ids: selected, include_exclude: status })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('Success', data.message, 'success');
            setTimeout(() => location.reload(), 1000);
        }
    })
    .catch(error => {
        showToast('Error', 'Failed to update products', 'error');
    });
}

// View reviews
function viewReviews(productId) {
    const modal = new bootstrap.Modal(document.getElementById('reviewsModal'));
    modal.show();
    
    fetch(`/admin/products/${productId}/reviews`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => response.json())
    .then(data => {
        let html = '';
        if (data.reviews.data.length === 0) {
            html = '<p class="text-center text-muted">No reviews found</p>';
        } else {
            data.reviews.data.forEach(review => {
                html += `
                    <div class="card mb-2">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <h6>${review.reviewer_name || 'Anonymous'}</h6>
                                <span class="badge bg-warning">${review.rating} ⭐</span>
                            </div>
                            <p class="mb-1"><strong>${review.review_title || ''}</strong></p>
                            <p class="mb-0">${review.review_text || ''}</p>
                            <small class="text-muted">${review.review_date || ''}</small>
                        </div>
                    </div>
                `;
            });
        }
        document.getElementById('reviewsContent').innerHTML = html;
    });
}

// View rankings
function viewRankings(productId) {
    const modal = new bootstrap.Modal(document.getElementById('rankingsModal'));
    modal.show();
    
    fetch(`/admin/products/${productId}/rankings`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => response.json())
    .then(data => {
        let html = '<table class="table table-sm"><thead><tr><th>Keyword</th><th>Position</th><th>Page</th><th>Date</th></tr></thead><tbody>';
        if (data.rankings.data.length === 0) {
            html += '<tr><td colspan="4" class="text-center text-muted">No rankings found</td></tr>';
        } else {
            data.rankings.data.forEach(ranking => {
                html += `
                    <tr>
                        <td>${ranking.keyword.keyword}</td>
                        <td><span class="badge bg-primary">#${ranking.position}</span></td>
                        <td>Page ${ranking.page}</td>
                        <td>${new Date(ranking.created_at).toLocaleDateString()}</td>
                    </tr>
                `;
            });
        }
        html += '</tbody></table>';
        document.getElementById('rankingsContent').innerHTML = html;
    });
}

// Toast notification
function showToast(title, message, type) {
    // Implement your toast notification here
    alert(`${title}: ${message}`);
}
</script>
@endpush
