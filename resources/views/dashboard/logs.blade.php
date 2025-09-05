@extends('layouts.app')

@section('title', 'Scraping Logs - Product Data Scraper')

@section('content')
<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center">
            <h2 class="mb-0">Scraping Logs</h2>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-primary" onclick="refreshLogs()">
                    <i class="fas fa-sync me-1"></i>
                    Refresh
                </button>
                <button class="btn btn-primary" onclick="exportLogs()">
                    <i class="fas fa-download me-1"></i>
                    Export
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="row mb-4">
    <div class="col-12">
        <div class="stat-card">
            <form method="GET" action="{{ route('dashboard.logs') }}" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Platform</label>
                    <select name="platform" class="form-select">
                        <option value="">All Platforms</option>
                        @foreach($platforms as $platform)
                        <option value="{{ $platform }}" {{ ($filters['platform'] ?? '') === $platform ? 'selected' : '' }}>
                            {{ ucfirst($platform) }}
                        </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="completed" {{ isset($filters['status']) && $filters['status'] === 'completed' ? 'selected' : '' }}>Completed</option>
                        <option value="failed" {{ isset($filters['status']) && $filters['status'] === 'failed' ? 'selected' : '' }}>Failed</option>
                        <option value="started" {{ isset($filters['status']) && $filters['status'] === 'started' ? 'selected' : '' }}>Started</option>
                        <option value="partial" {{ isset($filters['status']) && $filters['status'] === 'partial' ? 'selected' : '' }}>Partial</option>

                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Time Period</label>
                    <select name="days" class="form-select">
                        <option value="">All Time</option>
                        <option value="1" {{ ($filters['days'] ?? '') === '1' ? 'selected' : '' }}>Last 24 hours</option>
                        <option value="7" {{ ($filters['days'] ?? '') === '7' ? 'selected' : '' }}>Last 7 days</option>
                        <option value="30" {{ ($filters['days'] ?? '') === '30' ? 'selected' : '' }}>Last 30 days</option>

                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-filter me-1"></i>
                        Apply Filters
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Logs Table -->
<div class="row">
    <div class="col-12">
        <div class="chart-container">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">
                    <i class="fas fa-list-alt me-2"></i>
                    Scraping Sessions ({{ number_format($logs->total()) }} total)
                </h5>
            </div>

            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Date & Time</th>
                            <th>Platform</th>
                            <th>Status</th>
                            <th>Duration</th>
                            <th>Products</th>
                            <th>Changes</th>
                            <th>Errors</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($logs as $log)
                        <tr>
                            <td>
                                <div>
                                    <strong>{{ $log->created_at->format('M d, Y') }}</strong>
                                    <br>
                                    <small class="text-muted">{{ $log->created_at->format('H:i:s') }}</small>
                                </div>
                            </td>
                            <td>
                                <span class="badge bg-primary badge-status">
                                    {{ ucfirst($log->platform) }}
                                </span>
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
                            <td>
                                <div class="text-center">
                                    <strong>{{ number_format($log->products_found ?? 0) }}</strong>
                                    <br>
                                    <small class="text-muted">found</small>
                                </div>
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
                        @empty
                        <tr>
                            <td colspan="8" class="text-center py-4">
                                <div class="text-muted">
                                    <i class="fas fa-inbox fa-3x mb-3"></i>
                                    <p>No scraping logs found matching your criteria.</p>
                                </div>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            @if($logs->hasPages())
            <div class="d-flex justify-content-center mt-4">
                {{ $logs->appends(request()->query())->links() }}
            </div>
            @endif
        </div>
    </div>
</div>

<!-- Log Details Modal -->
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

<!-- Error Details Modal -->
<div class="modal fade" id="errorModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Error Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="errorModalBody">
                <!-- Error details will be loaded here -->
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    function refreshLogs() {
        location.reload();
    }

    function exportLogs() {
        // This would trigger logs export
        alert('Export functionality would be implemented here');
    }

    function showLogDetails(logId) {
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
                <div class="row">
                    <div class="col-md-6">
                        <h6>Session Information</h6>
                        <table class="table table-sm">
                            <tr><td>Log ID:</td><td>${logId}</td></tr>
                            <tr><td>Platform:</td><td>Amazon</td></tr>
                            <tr><td>Status:</td><td><span class="badge bg-success">Completed</span></td></tr>
                            <tr><td>Started:</td><td>2024-01-15 10:30:00</td></tr>
                            <tr><td>Completed:</td><td>2024-01-15 10:45:30</td></tr>
                            <tr><td>Duration:</td><td>15m 30s</td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6>Results Summary</h6>
                        <table class="table table-sm">
                            <tr><td>Products Found:</td><td>150</td></tr>
                            <tr><td>Products Added:</td><td>25</td></tr>
                            <tr><td>Products Updated:</td><td>45</td></tr>
                            <tr><td>Products Deactivated:</td><td>5</td></tr>
                            <tr><td>Errors:</td><td>2</td></tr>
                        </table>
                    </div>
                </div>
                <div class="mt-3">
                    <h6>Summary</h6>
                    <p class="text-muted">Scraping session completed successfully with minor errors. Most products were processed correctly.</p>
                </div>
            `;
        }, 1000);
    }

    function showLogErrors(logId) {
        const modal = new bootstrap.Modal(document.getElementById('errorModal'));
        document.getElementById('errorModalBody').innerHTML = `
            <div class="text-center">
                <div class="spinner-border" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </div>
        `;
        modal.show();

        // Simulate loading error details
        setTimeout(() => {
            document.getElementById('errorModalBody').innerHTML = `
                <div class="alert alert-danger">
                    <h6><i class="fas fa-exclamation-triangle me-2"></i>Errors encountered during scraping</h6>
                </div>
                <div class="accordion" id="errorAccordion">
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#error1">
                                HTTP Request Failed - Product ID: ABC123
                            </button>
                        </h2>
                        <div id="error1" class="accordion-collapse collapse show">
                            <div class="accordion-body">
                                <strong>Error:</strong> Connection timeout<br>
                                <strong>URL:</strong> https://example.com/product/abc123<br>
                                <strong>Time:</strong> 2024-01-15 10:35:22<br>
                                <strong>Details:</strong> Request timed out after 30 seconds
                            </div>
                        </div>
                    </div>
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#error2">
                                Data Parsing Error - Product ID: XYZ789
                            </button>
                        </h2>
                        <div id="error2" class="accordion-collapse collapse">
                            <div class="accordion-body">
                                <strong>Error:</strong> Invalid price format<br>
                                <strong>URL:</strong> https://example.com/product/xyz789<br>
                                <strong>Time:</strong> 2024-01-15 10:42:15<br>
                                <strong>Details:</strong> Could not parse price: "Special Offer"
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }, 1000);
    }
</script>
@endpush