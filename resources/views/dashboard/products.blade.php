@extends('layouts.app')

@section('title', 'Products - Product Data Scraper')

@section('content')
<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center">
            <h2 class="mb-0">Products</h2>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#filterModal">
                    <i class="fas fa-filter me-1"></i>
                    Filters
                </button>
                <button class="btn btn-primary" onclick="exportProducts()">
                    <i class="fas fa-download me-1"></i>
                    Export
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Filters Bar -->
<div class="row mb-4">
    <div class="col-12">
        <div class="stat-card">
            <form method="GET" action="{{ route('dashboard.products') }}" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Platform</label>
                    <select name="platform" class="form-select">
                        <option value="">All Platforms</option>
                        @foreach($platforms as $platform)
                        <option value="{{ $platform }}" {{ (isset($filters['platform']) && $filters['platform'] === $platform) ? 'selected' : '' }}>
                            {{ ucfirst($platform) }}
                        </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Brand</label>
                    <select name="brand" class="form-select">
                        <option value="">All Brands</option>
                        @foreach($brands as $brand)
                        <option value="{{ $brand }}" {{ (isset($filters['brand']) && $filters['brand'] === $brand) ? 'selected' : '' }}>
                            {{ $brand }}
                        </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="active" {{ ($filters['status'] ?? '') === 'active' ? 'selected' : '' }}>Active</option>
                        <option value="inactive" {{ ($filters['status'] ?? '') === 'inactive' ? 'selected' : '' }}>Inactive</option>

                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Search</label>
                    <input type="text" name="search" class="form-control" placeholder="Product name, SKU..."
                        value="{{ $filters['search'] ?? '' }}">
                </div>
                <div class="col-md-1">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Products Table -->
<div class="row">
    <div class="col-12">
        <div class="chart-container">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">
                    <i class="fas fa-laptop me-2"></i>
                    Products ({{ number_format($products->total()) }} total)
                </h5>
                <div class="d-flex gap-2">
                    <select class="form-select form-select-sm" style="width: auto;" onchange="changeSorting(this.value)">
                        <option value="updated_at-desc" {{ (($filters['sort'] ?? '') === 'updated_at') && (($filters['order'] ?? '') === 'desc') ? 'selected' : '' }}>
                            Latest Updated
                        </option>
                        <option value="created_at-desc" {{ (($filters['sort'] ?? '') === 'created_at') && (($filters['order'] ?? '') === 'desc') ? 'selected' : '' }}>
                            Recently Added
                        </option>
                        <option value="price-asc" {{ (($filters['sort'] ?? '') === 'price') && (($filters['order'] ?? '') === 'asc') ? 'selected' : '' }}>
                            Price: Low to High
                        </option>
                        <option value="price-desc" {{ (($filters['sort'] ?? '') === 'price') && (($filters['order'] ?? '') === 'desc') ? 'selected' : '' }}>
                            Price: High to Low
                        </option>
                        <option value="rating-desc" {{ (($filters['sort'] ?? '') === 'rating') && (($filters['order'] ?? '') === 'desc') ? 'selected' : '' }}>
                            Highest Rated
                        </option>

                    </select>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Product</th>
                            <th>Platform</th>
                            <th>Brand</th>
                            <th>Price</th>
                            <th>Rating</th>
                            <th>Status</th>
                            <th>Last Updated</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($products as $product)
                        <tr>
                            <td>
                                <div class="d-flex align-items-center">
                                    @if($product->image_urls && count($product->image_urls) > 0)
                                    <img src="{{ $product->image_urls[0] }}" alt="Product"
                                        class="rounded me-3" style="width: 50px; height: 50px; object-fit: cover;"
                                        onerror="this.style.display='none'">
                                    @endif
                                    <div>
                                        <h6 class="mb-1">{{ Str::limit($product->title, 50) }}</h6>
                                        <small class="text-muted">SKU: {{ $product->sku }}</small>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge bg-primary badge-status">
                                    {{ ucfirst($product->platform) }}
                                </span>
                            </td>
                            <td>{{ $product->brand ?? 'N/A' }}</td>
                            <td>
                                @if($product->sale_price && $product->sale_price < $product->price)
                                    <div>
                                        <strong class="text-success">₹{{ number_format($product->sale_price) }}</strong>
                                        <br>
                                        <small class="text-muted text-decoration-line-through">
                                            ₹{{ number_format($product->price) }}
                                        </small>
                                    </div>
                                    @else
                                    <strong>₹{{ number_format($product->price ?? 0) }}</strong>
                                    @endif
                            </td>
                            <td>
                                @if($product->rating)
                                <div class="d-flex align-items-center">
                                    <span class="me-1">{{ number_format($product->rating, 1) }}</span>
                                    <div class="text-warning">
                                        @for($i = 1; $i <= 5; $i++)
                                            @if($i <=$product->rating)
                                            <i class="fas fa-star"></i>
                                            @else
                                            <i class="far fa-star"></i>
                                            @endif
                                            @endfor
                                    </div>
                                </div>
                                @if($product->review_count > 0)
                                <small class="text-muted">({{ number_format($product->review_count) }})</small>
                                @endif
                                @else
                                <span class="text-muted">No rating</span>
                                @endif
                            </td>
                            <td>
                                @if($product->is_active)
                                <span class="badge bg-success badge-status">Active</span>
                                @else
                                <span class="badge bg-secondary badge-status">Inactive</span>
                                @endif
                            </td>
                            <td>
                                <span title="{{ $product->updated_at->format('Y-m-d H:i:s') }}">
                                    {{ $product->updated_at->diffForHumans() }}
                                </span>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    @if($product->product_url)
                                    <a href="{{ $product->product_url }}" target="_blank"
                                        class="btn btn-outline-primary" title="View Original">
                                        <i class="fas fa-external-link-alt"></i>
                                    </a>
                                    @endif
                                    <button class="btn btn-outline-info" onclick="showProductDetails({{ $product->id }})"
                                        title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="8" class="text-center py-4">
                                <div class="text-muted">
                                    <i class="fas fa-inbox fa-3x mb-3"></i>
                                    <p>No products found matching your criteria.</p>
                                </div>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            @if($products->hasPages())
            <div class="d-flex justify-content-center mt-4">
                {{ $products->appends(request()->query())->links() }}
            </div>
            @endif
        </div>
    </div>
</div>

<!-- Product Details Modal -->
<div class="modal fade" id="productModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Product Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="productModalBody">
                <!-- Product details will be loaded here -->
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    function changeSorting(value) {
        const [sort, order] = value.split('-');
        const url = new URL(window.location);
        url.searchParams.set('sort', sort);
        url.searchParams.set('order', order);
        window.location = url;
    }

    function showProductDetails(productId) {
        // This would fetch and display product details
        const modal = new bootstrap.Modal(document.getElementById('productModal'));
        document.getElementById('productModalBody').innerHTML = `
            <div class="text-center">
                <div class="spinner-border" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </div>
        `;
        modal.show();

        // Simulate loading product details
        setTimeout(() => {
            document.getElementById('productModalBody').innerHTML = `
                <p>Product details for ID: ${productId} would be displayed here.</p>
                <p>This would include full specifications, images, price history, etc.</p>
            `;
        }, 1000);
    }

    function exportProducts() {
        // This would trigger product export
        alert('Export functionality would be implemented here');
    }
</script>
@endpush