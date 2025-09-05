@extends('layouts.app')

@section('title', $platformName . ' - Platform Details')

@section('content')
<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="{{ route('dashboard.index') }}">Dashboard</a></li>
                        <li class="breadcrumb-item active">{{ $platformName }}</li>
                    </ol>
                </nav>
                <h2 class="mb-0">{{ $platformName }} Platform Details</h2>
            </div>
            <div class="d-flex gap-2">
                <select class="form-select" id="daysFilter" onchange="changeDaysFilter(this.value)">
                    <option value="7" {{ $days == 7 ? 'selected' : '' }}>Last 7 days</option>
                    <option value="14" {{ $days == 14 ? 'selected' : '' }}>Last 14 days</option>
                    <option value="30" {{ $days == 30 ? 'selected' : '' }}>Last 30 days</option>
                </select>
                <button class="btn btn-primary" onclick="runScraper('{{ $platform }}')">
                    <i class="fas fa-play me-1"></i>
                    Run Scraper
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Platform Statistics -->
<div class="row mb-4">
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="stat-card">
            <div class="d-flex align-items-center">
                <div class="stat-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                    <i class="fas fa-laptop"></i>
                </div>
                <div class="ms-3">
                    <h3 class="mb-0">{{ number_format($stats['total_products'] ?? 0) }}</h3>
                    <p class="text-muted mb-0">Total Products</p>
                    <small class="text-success">
                        {{ number_format($stats['active_products'] ?? 0) }} active
                    </small>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-3 col-md-6 mb-3">
        <div class="stat-card">
            <div class="d-flex align-items-center">
                <div class="stat-icon" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                    <i class="fas fa-rupee-sign"></i>
                </div>
                <div class="ms-3">
                    <h3 class="mb-0">₹{{ number_format($stats['avg_price'] ?? 0) }}</h3>
                    <p class="text-muted mb-0">Average Price</p>
                    <small class="text-info">
                        ₹{{ number_format($stats['min_price'] ?? 0) }} - ₹{{ number_format($stats['max_price'] ?? 0) }}
                    </small>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-3 col-md-6 mb-3">
        <div class="stat-card">
            <div class="d-flex align-items-center">
                <div class="stat-icon" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                    <i class="fas fa-star"></i>
                </div>
                <div class="ms-3">
                    <h3 class="mb-0">{{ number_format($stats['avg_rating'] ?? 0, 1) }}</h3>
                    <p class="text-muted mb-0">Average Rating</p>
                    <small class="text-success">
                        {{ number_format($stats['products_with_rating'] ?? 0) }} rated products
                    </small>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-3 col-md-6 mb-3">
        <div class="stat-card">
            <div class="d-flex align-items-center">
                <div class="stat-icon" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);">
                    <i class="fas fa-tags"></i>
                </div>
                <div class="ms-3">
                    <h3 class="mb-0">{{ $stats['brands_count'] ?? 0 }}</h3>
                    <p class="text-muted mb-0">Unique Brands</p>
                    @php
                    $lastScrape = $stats['last_scrape'] instanceof \Carbon\Carbon
                    ? $stats['last_scrape']
                    : \Carbon\Carbon::parse($stats['last_scrape']);
                    @endphp

                    <small class="text-info">
                        @if($lastScrape)
                        Last: {{ $lastScrape->diffForHumans() }}
                        @else
                        Never scraped
                        @endif
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Platform Configuration -->
<div class="row mb-4">
    <div class="col-12">
        <div class="chart-container">
            <h5 class="mb-3">
                <i class="fas fa-cog me-2"></i>
                Platform Configuration
            </h5>
            <div class="row">
                <div class="col-md-6">
                    <table class="table table-sm">
                        <tr>
                            <td><strong>Platform Name:</strong></td>
                            <td>{{ $platformName }}</td>
                        </tr>
                        <tr>
                            <td><strong>Platform Key:</strong></td>
                            <td><code>{{ $platform }}</code></td>
                        </tr>
                        <tr>
                            <td><strong>Base URL:</strong></td>
                            <td><a href="{{ $platformConfig['base_url'] }}" target="_blank">{{ $platformConfig['base_url'] }}</a></td>
                        </tr>
                        <tr>
                            <td><strong>Category URLs:</strong></td>
                            <td>{{ count($platformConfig['category_urls']) }} configured</td>
                        </tr>
                        <tr>
                            <td><strong>Status:</strong></td>
                            <td>
                                @if($platformConfig['enabled'] ?? true)
                                <span class="badge bg-success">Enabled</span>
                                @else
                                <span class="badge bg-danger">Disabled</span>
                                @endif
                            </td>
                        </tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <h6>Category URLs:</h6>
                    <div class="list-group list-group-flush">
                        @foreach($platformConfig['category_urls'] as $index => $url)
                        <div class="list-group-item px-0">
                            <small class="text-muted">{{ $index + 1 }}.</small>
                            <a href="{{ $url }}" target="_blank" class="text-decoration-none">
                                {{ Str::limit($url, 60) }}
                            </a>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Charts Row -->
<div class="row mb-4">
    <div class="col-lg-8">
        <div class="chart-container">
            <h5 class="mb-3">
                <i class="fas fa-chart-line me-2"></i>
                Daily Product Changes (Last {{ $days }} days)
            </h5>
            <canvas id="changesChart" width="600" height="300"></canvas>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="chart-container">
            <h5 class="mb-3">
                <i class="fas fa-chart-pie me-2"></i>
                Brand Distribution
            </h5>
            <canvas id="brandChart" height="300"></canvas>
        </div>
    </div>
</div>

<!-- Recent Scraping Sessions -->
<div class="row mb-4">
    <div class="col-12">
        <div class="chart-container">
            <h5 class="mb-3">
                <i class="fas fa-history me-2"></i>
                Recent Scraping Sessions (Last {{ $days }} days)
            </h5>

            @if($recentLogs->isEmpty())
            <div class="text-center py-4">
                <div class="text-muted">
                    <i class="fas fa-inbox fa-3x mb-3"></i>
                    <p>No recent scraping sessions found for this platform.</p>
                </div>
            </div>
            @else
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Date & Time</th>
                            <th>Status</th>
                            <th>Duration</th>
                            <th>Products Found</th>
                            <th>Changes</th>
                            <th>Errors</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($recentLogs as $log)
                        <tr>
                            <td>
                                <div>
                                    <strong>{{ $log->created_at->format('M d, Y') }}</strong>
                                    <br>
                                    <small class="text-muted">{{ $log->created_at->format('H:i:s') }}</small>
                                </div>
                            </td>
                            <td>
                                @switch($log->status)
                                @case('completed')
                                <span class="badge bg-success badge-status">
                                    <i class="fas fa-check me-1"></i>Completed
                                </span>
                                @break
                                @case('failed')
                                <span class="badge bg-danger badge-status">
                                    <i class="fas fa-times me-1"></i>Failed
                                </span>
                                @break
                                @case('started')
                                <span class="badge bg-info badge-status">
                                    <i class="fas fa-play me-1"></i>Started
                                </span>
                                @break
                                @case('partial')
                                <span class="badge bg-warning badge-status">
                                    <i class="fas fa-exclamation me-1"></i>Partial
                                </span>
                                @break
                                @default
                                <span class="badge bg-secondary badge-status">{{ ucfirst($log->status) }}</span>
                                @endswitch
                            </td>
                            <td>
                                @if($log->duration_seconds)
                                {{ gmdate('H:i:s', $log->duration_seconds) }}
                                @else
                                <span class="text-muted">N/A</span>
                                @endif
                            </td>
                            <td class="text-center">
                                <strong>{{ number_format($log->products_found ?? 0) }}</strong>
                            </td>
                            <td>
                                <div class="small">
                                    @if($log->products_added > 0)
                                    <div class="text-success">
                                        <i class="fas fa-plus me-1"></i>{{ $log->products_added }} added
                                    </div>
                                    @endif
                                    @if($log->products_updated > 0)
                                    <div class="text-info">
                                        <i class="fas fa-edit me-1"></i>{{ $log->products_updated }} updated
                                    </div>
                                    @endif
                                    @if($log->products_deactivated > 0)
                                    <div class="text-warning">
                                        <i class="fas fa-minus me-1"></i>{{ $log->products_deactivated }} deactivated
                                    </div>
                                    @endif
                                    @if($log->products_added == 0 && $log->products_updated == 0 && $log->products_deactivated == 0)
                                    <span class="text-muted">No changes</span>
                                    @endif
                                </div>
                            </td>
                            <td>
                                @if($log->errors_count > 0)
                                <span class="badge bg-danger">{{ $log->errors_count }}</span>
                                @else
                                <span class="text-success">
                                    <i class="fas fa-check"></i>
                                </span>
                                @endif
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-outline-info" onclick="showLogDetails({{ $log->id }})"
                                        title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    @if($log->errors_count > 0)
                                    <button class="btn btn-outline-danger" onclick="showLogErrors({{ $log->id }})"
                                        title="View Errors">
                                        <i class="fas fa-exclamation-triangle"></i>
                                    </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </div>
    </div>
</div>

<!-- Recent Products -->
<div class="row mb-4">
    <div class="col-12">
        <div class="chart-container">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">
                    <i class="fas fa-laptop me-2"></i>
                    Recent Products
                </h5>
                <a href="{{ route('dashboard.products', ['platform' => $platform]) }}" class="btn btn-outline-primary btn-sm">
                    View All Products
                </a>
            </div>

            @if($products->isEmpty())
            <div class="text-center py-4">
                <div class="text-muted">
                    <i class="fas fa-inbox fa-3x mb-3"></i>
                    <p>No products found for this platform.</p>
                </div>
            </div>
            @else
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Product</th>
                            <th>Brand</th>
                            <th>Price</th>
                            <th>Rating</th>
                            <th>Status</th>
                            <th>Last Updated</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($products->take(10) as $product)
                        <tr>
                            <td>
                                <div class="d-flex align-items-center">
                                    @if($product->image_urls && count($product->image_urls) > 0)
                                    <img src="{{ $product->image_urls[0] }}" alt="Product"
                                        class="rounded me-3" style="width: 40px; height: 40px; object-fit: cover;"
                                        onerror="this.style.display='none'">
                                    @endif
                                    <div>
                                        <h6 class="mb-1">{{ Str::limit($product->title, 40) }}</h6>
                                        <small class="text-muted">{{ $product->sku }}</small>
                                    </div>
                                </div>
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
                                    <div class="text-warning small">
                                        @for($i = 1; $i <= 5; $i++)
                                            @if($i <=$product->rating)
                                            <i class="fas fa-star"></i>
                                            @else
                                            <i class="far fa-star"></i>
                                            @endif
                                            @endfor
                                    </div>
                                </div>
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
                        @endforeach
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            @if($products->hasPages())
            <div class="d-flex justify-content-center mt-3">
                {{ $products->appends(request()->query())->links() }}
            </div>
            @endif
            @endif
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    // Chart configurations
    const chartColors = {
        primary: '#2563eb',
        success: '#10b981',
        warning: '#f59e0b',
        danger: '#ef4444',
        info: '#06b6d4'
    };

    // Daily Changes Chart
    const changesCtx = document.getElementById('changesChart').getContext('2d');
    const changesChart = new Chart(changesCtx, {
        type: 'line',
        data: {
            labels: @json($chartData['dailyChanges'] - > pluck('date') - > toArray()),
            datasets: [{
                label: 'Products Added',
                data: @json($chartData['dailyChanges'] - > pluck('added') - > toArray()),
                borderColor: chartColors.success,
                backgroundColor: chartColors.success + '20',
                tension: 0.4
            }, {
                label: 'Products Updated',
                data: @json($chartData['dailyChanges'] - > pluck('updated') - > toArray()),
                borderColor: chartColors.info,
                backgroundColor: chartColors.info + '20',
                tension: 0.4
            }]
        },
        options: {
            responsive: false,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });

    // Brand Distribution Chart
    const brandCtx = document.getElementById('brandChart').getContext('2d');
    const brandChart = new Chart(brandCtx, {
        type: 'doughnut',
        data: {
            labels: @json($chartData['brandDistribution'] - > pluck('brand') - > toArray()),
            datasets: [{
                data: @json($chartData['brandDistribution'] - > pluck('count') - > toArray()),
                backgroundColor: [
                    chartColors.primary,
                    chartColors.success,
                    chartColors.warning,
                    chartColors.danger,
                    chartColors.info,
                    '#8b5cf6',
                    '#f97316',
                    '#84cc16',
                    '#06b6d4',
                    '#6366f1'
                ]
            }]
        },
        options: {
            responsive: false,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }
    });

    // Functions
    function changeDaysFilter(days) {
        const url = new URL(window.location);
        url.searchParams.set('days', days);
        window.location = url;
    }

    function runScraper(platform) {
        if (confirm(`Are you sure you want to run the scraper for ${platform}? This may take several minutes.`)) {
            // This would trigger the scraper via AJAX
            alert(`Scraper for ${platform} would be started. Check the logs for progress.`);
        }
    }

    function showLogDetails(logId) {
        // Reuse the function from logs.blade.php
        const modal = new bootstrap.Modal(document.getElementById('logModal'));
        document.getElementById('logModalBody').innerHTML = `
            <div class="text-center">
                <div class="spinner-border" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </div>
        `;
        modal.show();

        // Simulate loading log details
        setTimeout(() => {
            document.getElementById('logModalBody').innerHTML = `
                <p>Log details for ID: ${logId} would be displayed here.</p>
                <p>This would include session information, results summary, and detailed logs.</p>
            `;
        }, 1000);
    }

    function showLogErrors(logId) {
        // Reuse the function from logs.blade.php
        alert(`Error details for log ${logId} would be displayed here.`);
    }

    function showProductDetails(productId) {
        // Reuse the function from products.blade.php
        alert(`Product details for ID: ${productId} would be displayed here.`);
    }
</script>

<!-- Modals (reuse from other views) -->
<div class="modal fade" id="logModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Scraping Log Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="logModalBody">
                <!-- Log details will be loaded here -->
            </div>
        </div>
    </div>
</div>
@endpush