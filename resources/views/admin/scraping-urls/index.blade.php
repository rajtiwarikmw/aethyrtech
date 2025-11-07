@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1>Scraping URLs Management</h1>
                <a href="{{ route('admin.scraping-urls.create') }}" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Add URLs
                </a>
            </div>

            @if(session('success'))
                <div class="alert alert-success alert-dismissible fade show">
                    {{ session('success') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            @if(session('error'))
                <div class="alert alert-danger alert-dismissible fade show">
                    {{ session('error') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            <!-- Statistics Cards -->
            <div class="row mb-4">
                <div class="col-md-2">
                    <div class="card text-center">
                        <div class="card-body">
                            <h5 class="card-title">Total</h5>
                            <h2>{{ $stats['total'] }}</h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card text-center bg-warning text-white">
                        <div class="card-body">
                            <h5 class="card-title">Pending</h5>
                            <h2>{{ $stats['pending'] }}</h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card text-center bg-info text-white">
                        <div class="card-body">
                            <h5 class="card-title">Processing</h5>
                            <h2>{{ $stats['processing'] }}</h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card text-center bg-success text-white">
                        <div class="card-body">
                            <h5 class="card-title">Completed</h5>
                            <h2>{{ $stats['completed'] }}</h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card text-center bg-danger text-white">
                        <div class="card-body">
                            <h5 class="card-title">Failed</h5>
                            <h2>{{ $stats['failed'] }}</h2>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="GET" action="{{ route('admin.scraping-urls.index') }}" class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Platform</label>
                            <select name="platform" class="form-select">
                                <option value="all" {{ $platform == 'all' ? 'selected' : '' }}>All Platforms</option>
                                <option value="amazon" {{ $platform == 'amazon' ? 'selected' : '' }}>Amazon</option>
                                <option value="flipkart" {{ $platform == 'flipkart' ? 'selected' : '' }}>Flipkart</option>
                                <option value="vijaysales" {{ $platform == 'vijaysales' ? 'selected' : '' }}>VijaySales</option>
                                <option value="croma" {{ $platform == 'croma' ? 'selected' : '' }}>Croma</option>
                                <option value="reliancedigital" {{ $platform == 'reliancedigital' ? 'selected' : '' }}>Reliance Digital</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="all" {{ $status == 'all' ? 'selected' : '' }}>All Status</option>
                                <option value="pending" {{ $status == 'pending' ? 'selected' : '' }}>Pending</option>
                                <option value="processing" {{ $status == 'processing' ? 'selected' : '' }}>Processing</option>
                                <option value="completed" {{ $status == 'completed' ? 'selected' : '' }}>Completed</option>
                                <option value="failed" {{ $status == 'failed' ? 'selected' : '' }}>Failed</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" class="btn btn-primary d-block w-100">Filter</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Bulk Actions -->
            <div class="card mb-3">
                <div class="card-body">
                    <form id="bulkActionForm" method="POST">
                        @csrf
                        <div class="row">
                            <div class="col-md-8">
                                <div class="btn-group" role="group">
                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAll()">Select All</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAll()">Deselect All</button>
                                </div>
                                <span class="ms-3" id="selectedCount">0 selected</span>
                            </div>
                            <div class="col-md-4 text-end">
                                <button type="button" class="btn btn-sm btn-warning" onclick="bulkRetry()">
                                    <i class="fas fa-redo"></i> Retry Selected
                                </button>
                                <button type="button" class="btn btn-sm btn-danger" onclick="bulkDelete()">
                                    <i class="fas fa-trash"></i> Delete Selected
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- URLs Table -->
            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th width="30"><input type="checkbox" id="selectAllCheckbox" onclick="toggleSelectAll()"></th>
                                    <th>ID</th>
                                    <th>Platform</th>
                                    <th>URL</th>
                                    <th>Status</th>
                                    <th>Priority</th>
                                    <th>Last Scraped</th>
                                    <th>Retries</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($urls as $url)
                                <tr>
                                    <td><input type="checkbox" class="url-checkbox" value="{{ $url->id }}"></td>
                                    <td>{{ $url->id }}</td>
                                    <td>
                                        <span class="badge bg-secondary">{{ strtoupper($url->platform) }}</span>
                                    </td>
                                    <td>
                                        <a href="{{ $url->url }}" target="_blank" class="text-truncate d-inline-block" style="max-width: 400px;">
                                            {{ $url->url }}
                                        </a>
                                    </td>
                                    <td>
                                        @if($url->status == 'pending')
                                            <span class="badge bg-warning">Pending</span>
                                        @elseif($url->status == 'processing')
                                            <span class="badge bg-info">Processing</span>
                                        @elseif($url->status == 'completed')
                                            <span class="badge bg-success">Completed</span>
                                        @elseif($url->status == 'failed')
                                            <span class="badge bg-danger" title="{{ $url->error_message }}">Failed</span>
                                        @endif
                                    </td>
                                    <td>{{ $url->priority }}</td>
                                    <td>{{ $url->last_scraped_at ? $url->last_scraped_at->diffForHumans() : 'Never' }}</td>
                                    <td>{{ $url->retry_count }}</td>
                                    <td>
                                        @if($url->status == 'failed')
                                        <form action="{{ route('admin.scraping-urls.retry', $url->id) }}" method="POST" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-warning" title="Retry">
                                                <i class="fas fa-redo"></i>
                                            </button>
                                        </form>
                                        @endif
                                        <form action="{{ route('admin.scraping-urls.destroy', $url->id) }}" method="POST" class="d-inline" onsubmit="return confirm('Are you sure?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-danger" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="9" class="text-center">No URLs found</td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div class="mt-3">
                        {{ $urls->appends(['platform' => $platform, 'status' => $status])->links() }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function toggleSelectAll() {
    const checkboxes = document.querySelectorAll('.url-checkbox');
    const selectAllCheckbox = document.getElementById('selectAllCheckbox');
    checkboxes.forEach(cb => cb.checked = selectAllCheckbox.checked);
    updateSelectedCount();
}

function selectAll() {
    document.querySelectorAll('.url-checkbox').forEach(cb => cb.checked = true);
    document.getElementById('selectAllCheckbox').checked = true;
    updateSelectedCount();
}

function deselectAll() {
    document.querySelectorAll('.url-checkbox').forEach(cb => cb.checked = false);
    document.getElementById('selectAllCheckbox').checked = false;
    updateSelectedCount();
}

function updateSelectedCount() {
    const count = document.querySelectorAll('.url-checkbox:checked').length;
    document.getElementById('selectedCount').textContent = count + ' selected';
}

function getSelectedIds() {
    return Array.from(document.querySelectorAll('.url-checkbox:checked')).map(cb => cb.value);
}

function bulkRetry() {
    const ids = getSelectedIds();
    if (ids.length === 0) {
        alert('Please select URLs to retry');
        return;
    }
    
    const form = document.getElementById('bulkActionForm');
    form.action = '{{ route("admin.scraping-urls.bulk-retry") }}';
    
    // Add hidden inputs for IDs
    ids.forEach(id => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'ids[]';
        input.value = id;
        form.appendChild(input);
    });
    
    form.submit();
}

function bulkDelete() {
    const ids = getSelectedIds();
    if (ids.length === 0) {
        alert('Please select URLs to delete');
        return;
    }
    
    if (!confirm('Are you sure you want to delete ' + ids.length + ' URLs?')) {
        return;
    }
    
    const form = document.getElementById('bulkActionForm');
    form.action = '{{ route("admin.scraping-urls.bulk-delete") }}';
    
    // Add hidden inputs for IDs
    ids.forEach(id => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'ids[]';
        input.value = id;
        form.appendChild(input);
    });
    
    form.submit();
}

// Update count when checkboxes change
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.url-checkbox').forEach(cb => {
        cb.addEventListener('change', updateSelectedCount);
    });
});
</script>
@endsection
