@extends('layouts.admin')

@section('title', 'Review Details')

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-6">
            <h1 class="h3 mb-0">Review Details</h1>
        </div>
        <div class="col-md-6 text-end">
            <a href="{{ route('admin.reviews.index') }}" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
            <form action="{{ route('admin.reviews.destroy', $review) }}" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this review?')">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-trash"></i> Delete Review
                </button>
            </form>
        </div>
    </div>

    <div class="row">
        <div class="col-md-8">
            {{-- Review Information --}}
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-comment"></i> Review Information</h5>
                </div>
                <div class="card-body">
                    <table class="table table-bordered">
                        <tr>
                            <th width="200">Platform</th>
                            <td><span class="badge bg-primary">{{ ucfirst($review->platform) }}</span></td>
                        </tr>
                        <tr>
                            <th>Review ID</th>
                            <td><code>{{ $review->review_id }}</code></td>
                        </tr>
                        <tr>
                            <th>Product SKU</th>
                            <td>
                                <code>{{ $review->product->sku ?? 'N/A' }}</code>
                                @if($review->product)
                                    <a href="{{ route('admin.products.show', $review->product) }}" class="btn btn-sm btn-primary ms-2">
                                        <i class="fas fa-box"></i> View Product
                                    </a>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th>Reviewer Name</th>
                            <td>{{ $review->reviewer_name ?? 'Anonymous' }}</td>
                        </tr>
                        <tr>
                            <th>Rating</th>
                            <td>
                                <span class="badge bg-warning text-dark" style="font-size: 1.2em;">
                                    {{ $review->rating }} ⭐
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th>Review Title</th>
                            <td><strong>{{ $review->review_title ?? 'N/A' }}</strong></td>
                        </tr>
                        <tr>
                            <th>Review Date</th>
                            <td>{{ $review->review_date ?? 'N/A' }}</td>
                        </tr>
                        <tr>
                            <th>Verified Purchase</th>
                            <td>
                                @if($review->verified_purchase)
                                    <span class="badge bg-success"><i class="fas fa-check-circle"></i> Verified Purchase</span>
                                @else
                                    <span class="badge bg-secondary">Not Verified</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th>Helpful Votes</th>
                            <td>{{ $review->helpful_votes ?? 0 }} people found this helpful</td>
                        </tr>
                        @if($review->reviewer_profile_url)
                        <tr>
                            <th>Reviewer Profile</th>
                            <td>
                                <a href="{{ $review->reviewer_profile_url }}" target="_blank" class="btn btn-sm btn-info">
                                    <i class="fas fa-external-link-alt"></i> View Profile
                                </a>
                            </td>
                        </tr>
                        @endif
                        <tr>
                            <th>Created At</th>
                            <td>{{ $review->created_at->format('d-m-Y H:i:s') }}</td>
                        </tr>
                        <tr>
                            <th>Updated At</th>
                            <td>{{ $review->updated_at->format('d-m-Y H:i:s') }}</td>
                        </tr>
                    </table>
                </div>
            </div>

            {{-- Review Text --}}
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-align-left"></i> Review Text</h5>
                </div>
                <div class="card-body">
                    <p class="lead">{{ $review->review_text ?? 'No review text available' }}</p>
                </div>
            </div>

            {{-- Product Variant Info --}}
            @if($review->product_variant)
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-tag"></i> Product Variant</h5>
                </div>
                <div class="card-body">
                    <p>{{ $review->product_variant }}</p>
                </div>
            </div>
            @endif
        </div>

        <div class="col-md-4">
            {{-- Product Info --}}
            @if($review->product)
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-box"></i> Product Info</h5>
                </div>
                <div class="card-body">
                    @if($review->product->image_url)
                        <img src="{{ $review->product->image_url }}" alt="{{ $review->product->title }}" class="img-fluid mb-3">
                    @endif
                    <h6>{{ $review->product->title }}</h6>
                    <p class="mb-1"><strong>Brand:</strong> {{ $review->product->brand ?? 'N/A' }}</p>
                    <p class="mb-1"><strong>Price:</strong> ₹{{ number_format($review->product->price, 2) }}</p>
                    <p class="mb-1"><strong>Rating:</strong> {{ $review->product->rating ?? 'N/A' }} ⭐</p>
                    <a href="{{ route('admin.products.show', $review->product) }}" class="btn btn-primary btn-sm w-100 mt-2">
                        View Product Details
                    </a>
                </div>
            </div>
            @endif

            {{-- Review Images --}}
            @if($review->review_images)
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-images"></i> Review Images</h5>
                </div>
                <div class="card-body">
                    @php
                        $images = is_array($review->review_images) ? $review->review_images : json_decode($review->review_images, true);
                    @endphp
                    @if($images && count($images) > 0)
                        <div class="row">
                            @foreach($images as $image)
                                <div class="col-6 mb-2">
                                    <a href="{{ $image }}" target="_blank">
                                        <img src="{{ $image }}" alt="Review Image" class="img-fluid img-thumbnail">
                                    </a>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-muted">No images attached</p>
                    @endif
                </div>
            </div>
            @endif

            {{-- Quick Stats --}}
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-chart-bar"></i> Quick Stats</h5>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2">
                        <span>Rating:</span>
                        <strong>{{ $review->rating }}/5</strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Helpful Votes:</span>
                        <strong>{{ $review->helpful_votes ?? 0 }}</strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Images:</span>
                        <strong>{{ is_array($review->review_images) ? count($review->review_images) : (json_decode($review->review_images) ? count(json_decode($review->review_images)) : 0) }}</strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Verified:</span>
                        <strong>{{ $review->verified_purchase ? 'Yes' : 'No' }}</strong>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
