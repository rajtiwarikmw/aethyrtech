@extends('layouts.admin')

@section('title', 'Product Details')

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-6">
            <h1 class="h3 mb-0">Product Details</h1>
        </div>
        <div class="col-md-6 text-end">
            <a href="{{ route('admin.products.index') }}" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
        </div>
    </div>

    <div class="row">
        <div class="col-md-8">
            {{-- Product Information --}}
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-info-circle"></i> Product Information</h5>
                </div>
                <div class="card-body">
                    <table class="table table-bordered">
                        <tr>
                            <th width="200">Platform</th>
                            <td><span class="badge bg-primary">{{ ucfirst($product->platform) }}</span></td>
                        </tr>
                        <tr>
                            <th>SKU</th>
                            <td><code>{{ $product->sku }}</code></td>
                        </tr>
                        <tr>
                            <th>Title</th>
                            <td>{{ $product->title }}</td>
                        </tr>
                        <tr>
                            <th>Brand</th>
                            <td>{{ $product->brand ?? 'N/A' }}</td>
                        </tr>
                        <tr>
                            <th>Category</th>
                            <td>{{ $product->category ?? 'N/A' }}</td>
                        </tr>
                        <tr>
                            <th>Price</th>
                            <td>₹{{ number_format($product->price, 2) }}</td>
                        </tr>
                        <tr>
                            <th>Sale Price</th>
                            <td>
                                @if($product->sale_price)
                                    ₹{{ number_format($product->sale_price, 2) }}
                                    <span class="badge bg-success">
                                        {{ round((($product->price - $product->sale_price) / $product->price) * 100) }}% OFF
                                    </span>
                                @else
                                    N/A
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th>Rating</th>
                            <td>
                                @if($product->rating)
                                    <span class="badge bg-warning">{{ $product->rating }} ⭐</span>
                                    @if($product->rating_count)
                                        ({{ number_format($product->rating_count) }} ratings)
                                    @endif
                                @else
                                    N/A
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th>Availability</th>
                            <td>
                                @if($product->availability)
                                    <span class="badge bg-success">In Stock</span>
                                @else
                                    <span class="badge bg-danger">Out of Stock</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th>Status</th>
                            <td>
                                <select class="form-select form-select-sm w-auto" onchange="updateStatus(this.value)">
                                    <option value="include" {{ $product->include_exclude == 'include' ? 'selected' : '' }}>Include</option>
                                    <option value="exclude" {{ $product->include_exclude == 'exclude' ? 'selected' : '' }}>Exclude</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>Product URL</th>
                            <td>
                                <a href="{{ $product->product_url }}" target="_blank" class="btn btn-sm btn-primary">
                                    <i class="fas fa-external-link-alt"></i> View on {{ ucfirst($product->platform) }}
                                </a>
                            </td>
                        </tr>
                        <tr>
                            <th>Last Scraped</th>
                            <td>
                                @if($product->last_scraped_at)
                                    {{ $product->last_scraped_at->format('d-m-Y H:i:s') }}
                                    <small class="text-muted">({{ $product->last_scraped_at->diffForHumans() }})</small>
                                @else
                                    Never
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th>Created At</th>
                            <td>{{ $product->created_at->format('d-m-Y H:i:s') }}</td>
                        </tr>
                        <tr>
                            <th>Updated At</th>
                            <td>{{ $product->updated_at->format('d-m-Y H:i:s') }}</td>
                        </tr>
                    </table>
                </div>
            </div>

            {{-- Description --}}
            @if($product->description)
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-align-left"></i> Description</h5>
                </div>
                <div class="card-body">
                    <p>{{ $product->description }}</p>
                </div>
            </div>
            @endif
        </div>

        <div class="col-md-4">
            {{-- Product Image --}}
            @if($product->image_url)
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-image"></i> Product Image</h5>
                </div>
                <div class="card-body text-center">
                    <img src="{{ $product->image_url }}" alt="{{ $product->title }}" class="img-fluid" style="max-height: 300px;">
                </div>
            </div>
            @endif

            {{-- Quick Stats --}}
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-chart-bar"></i> Quick Stats</h5>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2">
                        <span>Total Reviews:</span>
                        <strong>{{ $product->reviews->count() }}</strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Total Rankings:</span>
                        <strong>{{ $product->rankings->count() }}</strong>
                    </div>
                    <hr>
                    <a href="#reviews" class="btn btn-primary btn-sm w-100 mb-2">
                        <i class="fas fa-comments"></i> View Reviews
                    </a>
                    <a href="#rankings" class="btn btn-success btn-sm w-100">
                        <i class="fas fa-chart-line"></i> View Rankings
                    </a>
                </div>
            </div>
        </div>
    </div>

    {{-- Reviews Section --}}
    <div class="row" id="reviews">
        <div class="col-md-12">
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-comments"></i> Reviews ({{ $product->reviews->count() }})</h5>
                    <a href="{{ route('admin.reviews.index', ['sku' => $product->sku]) }}" class="btn btn-sm btn-primary">
                        View All Reviews
                    </a>
                </div>
                <div class="card-body">
                    @forelse($product->reviews()->latest()->limit(5)->get() as $review)
                        <div class="card mb-2">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <h6>{{ $review->reviewer_name ?? 'Anonymous' }}</h6>
                                    <span class="badge bg-warning">{{ $review->rating }} ⭐</span>
                                </div>
                                @if($review->review_title)
                                    <p class="mb-1"><strong>{{ $review->review_title }}</strong></p>
                                @endif
                                <p class="mb-1">{{ \Str::limit($review->review_text, 200) }}</p>
                                <small class="text-muted">{{ $review->review_date }}</small>
                            </div>
                        </div>
                    @empty
                        <p class="text-center text-muted">No reviews available</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    {{-- Rankings Section --}}
    <div class="row" id="rankings">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-chart-line"></i> Rankings ({{ $product->rankings->count() }})</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead>
                                <tr>
                                    <th>Keyword</th>
                                    <th>Position</th>
                                    <th>Page</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($product->rankings()->with('keyword')->latest()->limit(10)->get() as $ranking)
                                    <tr>
                                        <td>{{ $ranking->keyword->keyword ?? 'N/A' }}</td>
                                        <td><span class="badge bg-primary">#{{ $ranking->position }}</span></td>
                                        <td>Page {{ $ranking->page }}</td>
                                        <td>{{ $ranking->created_at->format('d-m-Y H:i') }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center text-muted">No rankings available</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
function updateStatus(status) {
    fetch('{{ route("admin.products.update-status", $product) }}', {
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
            alert('Status updated successfully');
        }
    })
    .catch(error => {
        alert('Failed to update status');
    });
}
</script>
@endpush
