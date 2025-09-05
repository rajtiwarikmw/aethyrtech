@extends('layouts.app')

@section('title', 'Dashboard - Product Data Scraper')

@section('content')
<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center">
            <h2 class="mb-0">Dashboard Overview</h2>
            <div class="d-flex gap-2">
                <select class="form-select" id="daysFilter" onchange="changeDaysFilter(this.value)">
                    <option value="7" {{ $days == 7 ? 'selected' : '' }}>Last 7 days</option>
                    <option value="14" {{ $days == 14 ? 'selected' : '' }}>Last 14 days</option>
                    <option value="30" {{ $days == 30 ? 'selected' : '' }}>Last 30 days</option>
                </select>
                <button class="btn btn-primary" onclick="refreshData()">
                    <i class="fas fa-sync"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- System Health -->
<div class="row mb-4">
    <div class="col-12">
        <div class="stat-card">
            <h5 class="mb-3">
                <i class="fas fa-heartbeat me-2"></i>
                System Health
            </h5>
            <div class="row">
                <div class="col-md-3">
                    <div class="d-flex align-items-center">
                        <span class="health-indicator health-{{ $systemHealth['database'] }}"></span>
                        <span>Database Connection</span>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="d-flex align-items-center">
                        <span class="health-indicator health-{{ $systemHealth['recentActivity'] }}"></span>
                        <span>Recent Activity</span>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="d-flex align-items-center">
                        <span class="health-indicator health-{{ $systemHealth['dataFreshness'] }}"></span>
                        <span>Data Freshness</span>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="d-flex align-items-center">
                        <span class="health-indicator health-{{ $systemHealth['errorRate'] }}"></span>
                        <span>Error Rate</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Overview Statistics -->
<div class="row mb-4">
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="stat-card">
            <div class="d-flex align-items-center">
                <div class="stat-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                    <i class="fas fa-laptop"></i>
                </div>
                <div class="ms-3">
                    <h3 class="mb-0">{{ number_format($overview['totalProducts']) }}</h3>
                    <p class="text-muted mb-0">Total Products</p>
                    <small class="text-success">
                        {{ number_format($overview['activeProducts']) }} active
                    </small>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-3 col-md-6 mb-3">
        <div class="stat-card">
            <div class="d-flex align-items-center">
                <div class="stat-icon" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                    <i class="fas fa-store"></i>
                </div>
                <div class="ms-3">
                    <h3 class="mb-0">{{ $overview['totalPlatforms'] }}</h3>
                    <p class="text-muted mb-0">Platforms</p>
                    <small class="text-info">E-commerce sites</small>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-3 col-md-6 mb-3">
        <div class="stat-card">
            <div class="d-flex align-items-center">
                <div class="stat-icon" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                    <i class="fas fa-sync"></i>
                </div>
                <div class="ms-3">
                    <h3 class="mb-0">{{ $overview['recentScrapes'] }}</h3>
                    <p class="text-muted mb-0">Recent Scrapes</p>
                    <small class="text-success">
                        {{ $overview['successfulScrapes'] }} successful
                    </small>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-3 col-md-6 mb-3">
        <div class="stat-card">
            <div class="d-flex align-items-center">
                <div class="stat-icon" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);">
                    <i class="fas fa-rupee-sign"></i>
                </div>
                <div class="ms-3">
                    <h3 class="mb-0">₹{{ number_format($overview['avgPrice'] ?? 0) }}</h3>
                    <p class="text-muted mb-0">Avg Price</p>
                    <small class="text-info">{{ $overview['topBrand'] ?? 'N/A' }} top brand</small>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Platform Performance -->
<div class="row mb-4">
    <div class="col-12">
        <div class="chart-container">
            <h5 class="mb-3">
                <i class="fas fa-chart-bar me-2"></i>
                Platform Performance (Last {{ $days }} days)
            </h5>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Platform</th>
                            <th>Success Rate</th>
                            <th>Total Runs</th>
                            <th>Products</th>
                            <th>Avg Duration</th>
                            <th>Last Run</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($platformPerformance as $platform => $data)
                        <tr>
                            <td>
                                <a href="{{ route('dashboard.platform', $platform) }}" class="text-decoration-none">
                                    <strong>{{ $data['name'] }}</strong>
                                </a>
                            </td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="progress me-2" style="width: 60px; height: 8px;">
                                        <div class="progress-bar 
                                            @if($data['success_rate'] >= 80) bg-success
                                            @elseif($data['success_rate'] >= 60) bg-warning
                                            @else bg-danger
                                            @endif"
                                            style="width: {{ $data['success_rate'] }}%"></div>
                                    </div>
                                    <span class="small">{{ $data['success_rate'] }}%</span>
                                </div>
                            </td>
                            <td>{{ $data['total_runs'] }}</td>
                            <td>{{ number_format($data['total_products']) }}</td>
                            <td>
                                @if($data['avg_duration'])
                                {{ gmdate('H:i:s', $data['avg_duration']) }}
                                @else
                                N/A
                                @endif
                            </td>
                            <td>
                                @if($data['last_run'])
                                <span title="{{ $data['last_run']->format('Y-m-d H:i:s') }}">
                                    {{ $data['last_run']->diffForHumans() }}
                                </span>
                                @else
                                <span class="text-muted">Never</span>
                                @endif
                            </td>
                            <td>
                                @if($data['success_rate'] >= 80)
                                <span class="badge bg-success badge-status">Healthy</span>
                                @elseif($data['success_rate'] >= 60)
                                <span class="badge bg-warning badge-status">Warning</span>
                                @else
                                <span class="badge bg-danger badge-status">Error</span>
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

<!-- Charts Row -->
<div class="row mb-4">
    <div class="col-lg-8">
        <div class="chart-container">
            <h5 class="mb-3">
                <i class="fas fa-chart-line me-2"></i>
                Daily Scraping Activity
            </h5>
            <canvas id="activityChart" width="600" height="300"></canvas>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="chart-container">
            <h5 class="mb-3">
                <i class="fas fa-chart-pie me-2"></i>
                Platform Distribution
            </h5>
            <canvas id="platformChart" height="300"></canvas>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-lg-6">
        <div class="chart-container">
            <h5 class="mb-3">
                <i class="fas fa-chart-bar me-2"></i>
                Price Range Distribution
            </h5>
            <canvas id="priceChart" width="450" height="350"></canvas>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="chart-container">
            <h5 class="mb-3">
                <i class="fas fa-clock me-2"></i>
                Recent Activity
            </h5>
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Platform</th>
                            <th>Status</th>
                            <th>Products</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($recentActivity as $activity)
                        <tr>
                            <td>
                                <small>{{ $activity->created_at->format('M d, H:i') }}</small>
                            </td>
                            <td>{{ ucfirst($activity->platform) }}</td>
                            <td>
                                <span class="badge 
                                    @if($activity->status === 'completed') bg-success
                                    @elseif($activity->status === 'failed') bg-danger
                                    @else bg-warning
                                    @endif badge-status">
                                    {{ ucfirst($activity->status) }}
                                </span>
                            </td>
                            <td>{{ $activity->products_found ?? 0 }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
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

    // Daily Activity Chart
    const activityCtx = document.getElementById('activityChart').getContext('2d');
    const activityChart = new Chart(activityCtx, {
        type: 'line',
        data: {
            labels: @json(array_keys($chartData['dailyActivity'] ->toArray())),
            datasets: [{
                label: 'Successful Scrapes',
                data: @json(array_values(
                    $chartData['dailyActivity'] ->map(function($items) {
                        return $items ->where('status', 'completed') ->sum('count');
                    })->toArray()
                )),
                borderColor: chartColors.success,
                backgroundColor: chartColors.success + '20',
                tension: 0.4
            }, {
                label: 'Failed Scrapes',
                data: @json(array_values(
                    $chartData['dailyActivity'] ->map(function($items) {
                        return $items ->where('status', 'failed') ->sum('count');
                    })->toArray()
                )),
                borderColor: chartColors.danger,
                backgroundColor: chartColors.danger + '20',
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

    // Platform Distribution Chart
    const platformCtx = document.getElementById('platformChart').getContext('2d');
    const platformChart = new Chart(platformCtx, {
        type: 'doughnut',
        data: {
            labels: @json($chartData['platformDistribution'] ->pluck('platform') ->toArray()),
            datasets: [{
                data: @json($chartData['platformDistribution'] ->pluck('count') ->toArray()),
                backgroundColor: [
                    chartColors.primary,
                    chartColors.success,
                    chartColors.warning,
                    chartColors.danger,
                    chartColors.info
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

    // Price Range Chart
    const priceCtx = document.getElementById('priceChart').getContext('2d');
    const priceChart = new Chart(priceCtx, {
        type: 'bar',
        data: {
            labels: @json(array_keys($chartData['priceRanges'])),
            datasets: [{
                label: 'Products',
                data: @json(array_values($chartData['priceRanges'])),
                backgroundColor: chartColors.primary + '80',
                borderColor: chartColors.primary,
                borderWidth: 1
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

    // Functions
    function changeDaysFilter(days) {
        const url = new URL(window.location);
        url.searchParams.set('days', days);
        window.location = url;
    }

    function updateDashboardData() {
        fetch('{{ route("dashboard.api.stats") }}?days={{ $days }}')
            .then(response => response.json())
            .then(data => {
                // Update overview stats
                // This would update the dashboard with fresh data
                console.log('Dashboard data updated', data);
            })
            .catch(error => {
                console.error('Error updating dashboard:', error);
            });
    }
</script>
@endpush