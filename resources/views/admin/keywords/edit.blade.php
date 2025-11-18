@extends('layouts.admin')

@section('title', 'Edit Keyword')

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-6">
            <h1 class="h3 mb-0">Edit Keyword</h1>
        </div>
        <div class="col-md-6 text-end">
            <a href="{{ route('admin.keywords.index') }}" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
            <form action="{{ route('admin.keywords.destroy', $keyword) }}" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this keyword?')">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-trash"></i> Delete
                </button>
            </form>
        </div>
    </div>

    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-edit"></i> Edit Keyword Details</h5>
                </div>
                <div class="card-body">
                    <form action="{{ route('admin.keywords.update', $keyword) }}" method="POST">
                        @csrf
                        @method('PUT')

                        <div class="mb-3">
                            <label for="keyword" class="form-label">Keyword <span class="text-danger">*</span></label>
                            <input type="text" class="form-control @error('keyword') is-invalid @enderror" 
                                   id="keyword" name="keyword" value="{{ old('keyword', $keyword->keyword) }}" 
                                   placeholder="e.g., mini printer" required>
                            @error('keyword')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="platform" class="form-label">Platform <span class="text-danger">*</span></label>
                            <select class="form-select @error('platform') is-invalid @enderror" 
                                    id="platform" name="platform" required>
                                <option value="">Select Platform</option>
                                <option value="amazon" {{ old('platform', $keyword->platform) == 'amazon' ? 'selected' : '' }}>Amazon</option>
                                <option value="flipkart" {{ old('platform', $keyword->platform) == 'flipkart' ? 'selected' : '' }}>Flipkart</option>
                                <option value="vijaysales" {{ old('platform', $keyword->platform) == 'vijaysales' ? 'selected' : '' }}>VijaySales</option>
                                <option value="croma" {{ old('platform', $keyword->platform) == 'croma' ? 'selected' : '' }}>Croma</option>
                                <option value="reliancedigital" {{ old('platform', $keyword->platform) == 'reliancedigital' ? 'selected' : '' }}>Reliance Digital</option>
                                <option value="blinkit" {{ old('platform', $keyword->platform) == 'blinkit' ? 'selected' : '' }}>Blinkit</option>
                                <option value="bigbasket" {{ old('platform', $keyword->platform) == 'bigbasket' ? 'selected' : '' }}>BigBasket</option>
                            </select>
                            @error('platform')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status" name="status">
                                <option value="active" {{ old('status', $keyword->status) == 'active' ? 'selected' : '' }}>Active</option>
                                <option value="inactive" {{ old('status', $keyword->status) == 'inactive' ? 'selected' : '' }}>Inactive</option>
                            </select>
                            <small class="text-muted">
                                Active keywords will be used in ranking scraper. Inactive keywords will be skipped.
                            </small>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Update Keyword
                            </button>
                            <a href="{{ route('admin.keywords.index') }}" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            {{-- Keyword Info --}}
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-info-circle"></i> Keyword Info</h5>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2">
                        <span>ID:</span>
                        <strong>{{ $keyword->id }}</strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Platform:</span>
                        <span class="badge bg-primary">{{ ucfirst($keyword->platform) }}</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Status:</span>
                        @if($keyword->status == 'active')
                            <span class="badge bg-success">Active</span>
                        @else
                            <span class="badge bg-secondary">Inactive</span>
                        @endif
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Created:</span>
                        <small>{{ $keyword->created_at->format('d-m-Y') }}</small>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Updated:</span>
                        <small>{{ $keyword->updated_at->format('d-m-Y') }}</small>
                    </div>
                </div>
            </div>

            {{-- Rankings Stats --}}
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-chart-line"></i> Rankings Stats</h5>
                </div>
                <div class="card-body">
                    @php
                        $rankingsCount = \App\Models\ProductRanking::where('keyword_id', $keyword->id)->count();
                        $uniqueProducts = \App\Models\ProductRanking::where('keyword_id', $keyword->id)
                            ->distinct('product_id')
                            ->count('product_id');
                        $latestRanking = \App\Models\ProductRanking::where('keyword_id', $keyword->id)
                            ->orderBy('created_at', 'desc')
                            ->first();
                    @endphp

                    <div class="d-flex justify-content-between mb-2">
                        <span>Total Rankings:</span>
                        <strong>{{ number_format($rankingsCount) }}</strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Unique Products:</span>
                        <strong>{{ number_format($uniqueProducts) }}</strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Last Tracked:</span>
                        <small>{{ $latestRanking ? $latestRanking->created_at->diffForHumans() : 'Never' }}</small>
                    </div>

                    <hr>

                    <a href="{{ route('admin.keywords.rankings', $keyword) }}" class="btn btn-primary btn-sm w-100">
                        <i class="fas fa-chart-line"></i> View All Rankings
                    </a>
                </div>
            </div>

            {{-- Actions --}}
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-bolt"></i> Quick Actions</h5>
                </div>
                <div class="card-body">
                    <form action="{{ route('admin.scraper.run') }}" method="POST">
                        @csrf
                        <input type="hidden" name="platform" value="{{ $keyword->platform }}">
                        <input type="hidden" name="type" value="rankings">
                        <input type="hidden" name="keyword_ids[]" value="{{ $keyword->id }}">
                        
                        <button type="submit" class="btn btn-success btn-sm w-100 mb-2">
                            <i class="fas fa-play"></i> Run Ranking Scraper
                        </button>
                    </form>

                    @if($keyword->status == 'active')
                        <form action="{{ route('admin.keywords.update-status', $keyword) }}" method="POST">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="status" value="inactive">
                            <button type="submit" class="btn btn-warning btn-sm w-100 mb-2">
                                <i class="fas fa-pause"></i> Deactivate Keyword
                            </button>
                        </form>
                    @else
                        <form action="{{ route('admin.keywords.update-status', $keyword) }}" method="POST">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="status" value="active">
                            <button type="submit" class="btn btn-success btn-sm w-100 mb-2">
                                <i class="fas fa-play"></i> Activate Keyword
                            </button>
                        </form>
                    @endif

                    <form action="{{ route('admin.keywords.destroy', $keyword) }}" method="POST" onsubmit="return confirm('Are you sure? This will also delete all associated rankings.')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-danger btn-sm w-100">
                            <i class="fas fa-trash"></i> Delete Keyword
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
