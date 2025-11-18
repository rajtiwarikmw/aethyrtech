@extends('layouts.admin')

@section('title', 'Create Keyword')

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-6">
            <h1 class="h3 mb-0">Create Keyword</h1>
        </div>
        <div class="col-md-6 text-end">
            <a href="{{ route('admin.keywords.index') }}" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
        </div>
    </div>

    <div class="row">
        <div class="col-md-8">
            {{-- Single Keyword Form --}}
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-key"></i> Create Single Keyword</h5>
                </div>
                <div class="card-body">
                    <form action="{{ route('admin.keywords.store') }}" method="POST">
                        @csrf
                        <input type="hidden" name="type" value="single">

                        <div class="mb-3">
                            <label for="keyword" class="form-label">Keyword <span class="text-danger">*</span></label>
                            <input type="text" class="form-control @error('keyword') is-invalid @enderror" 
                                   id="keyword" name="keyword" value="{{ old('keyword') }}" 
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
                                <option value="amazon" {{ old('platform') == 'amazon' ? 'selected' : '' }}>Amazon</option>
                                <option value="flipkart" {{ old('platform') == 'flipkart' ? 'selected' : '' }}>Flipkart</option>
                                <option value="vijaysales" {{ old('platform') == 'vijaysales' ? 'selected' : '' }}>VijaySales</option>
                                <option value="croma" {{ old('platform') == 'croma' ? 'selected' : '' }}>Croma</option>
                                <option value="reliancedigital" {{ old('platform') == 'reliancedigital' ? 'selected' : '' }}>Reliance Digital</option>
                                <option value="blinkit" {{ old('platform') == 'blinkit' ? 'selected' : '' }}>Blinkit</option>
                                <option value="bigbasket" {{ old('platform') == 'bigbasket' ? 'selected' : '' }}>BigBasket</option>
                            </select>
                            @error('platform')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status" name="status">
                                <option value="active" {{ old('status', 'active') == 'active' ? 'selected' : '' }}>Active</option>
                                <option value="inactive" {{ old('status') == 'inactive' ? 'selected' : '' }}>Inactive</option>
                            </select>
                        </div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Create Keyword
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            {{-- Bulk Create Form --}}
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-layer-group"></i> Bulk Create Keywords</h5>
                </div>
                <div class="card-body">
                    <form action="{{ route('admin.keywords.store') }}" method="POST">
                        @csrf
                        <input type="hidden" name="type" value="bulk">

                        <div class="mb-3">
                            <label for="keywords_bulk" class="form-label">Keywords (One per line) <span class="text-danger">*</span></label>
                            <textarea class="form-control @error('keywords_bulk') is-invalid @enderror" 
                                      id="keywords_bulk" name="keywords_bulk" rows="10" 
                                      placeholder="mini printer&#10;wireless printer&#10;portable printer&#10;thermal printer" required>{{ old('keywords_bulk') }}</textarea>
                            @error('keywords_bulk')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <small class="text-muted">Enter one keyword per line. Empty lines will be ignored.</small>
                        </div>

                        <div class="mb-3">
                            <label for="platform_bulk" class="form-label">Platform <span class="text-danger">*</span></label>
                            <select class="form-select @error('platform_bulk') is-invalid @enderror" 
                                    id="platform_bulk" name="platform_bulk" required>
                                <option value="">Select Platform</option>
                                <option value="amazon" {{ old('platform_bulk') == 'amazon' ? 'selected' : '' }}>Amazon</option>
                                <option value="flipkart" {{ old('platform_bulk') == 'flipkart' ? 'selected' : '' }}>Flipkart</option>
                                <option value="vijaysales" {{ old('platform_bulk') == 'vijaysales' ? 'selected' : '' }}>VijaySales</option>
                                <option value="croma" {{ old('platform_bulk') == 'croma' ? 'selected' : '' }}>Croma</option>
                                <option value="reliancedigital" {{ old('platform_bulk') == 'reliancedigital' ? 'selected' : '' }}>Reliance Digital</option>
                                <option value="blinkit" {{ old('platform_bulk') == 'blinkit' ? 'selected' : '' }}>Blinkit</option>
                                <option value="bigbasket" {{ old('platform_bulk') == 'bigbasket' ? 'selected' : '' }}>BigBasket</option>
                            </select>
                            @error('platform_bulk')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="status_bulk" class="form-label">Status</label>
                            <select class="form-select" id="status_bulk" name="status_bulk">
                                <option value="active" {{ old('status_bulk', 'active') == 'active' ? 'selected' : '' }}>Active</option>
                                <option value="inactive" {{ old('status_bulk') == 'inactive' ? 'selected' : '' }}>Inactive</option>
                            </select>
                        </div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-save"></i> Create Multiple Keywords
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            {{-- Help Card --}}
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-info-circle"></i> Help</h5>
                </div>
                <div class="card-body">
                    <h6>Single Keyword</h6>
                    <p class="small">Create one keyword at a time with specific platform assignment.</p>

                    <hr>

                    <h6>Bulk Create</h6>
                    <p class="small">Create multiple keywords at once. Enter one keyword per line.</p>

                    <hr>

                    <h6>Platform</h6>
                    <p class="small">Select the e-commerce platform where this keyword will be used for ranking tracking.</p>

                    <hr>

                    <h6>Status</h6>
                    <ul class="small mb-0">
                        <li><strong>Active:</strong> Keyword will be used in ranking scraper</li>
                        <li><strong>Inactive:</strong> Keyword will be skipped</li>
                    </ul>
                </div>
            </div>

            {{-- Example Card --}}
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-lightbulb"></i> Examples</h5>
                </div>
                <div class="card-body">
                    <h6>Good Keywords:</h6>
                    <ul class="small">
                        <li>mini printer</li>
                        <li>wireless printer</li>
                        <li>portable thermal printer</li>
                        <li>bluetooth printer</li>
                    </ul>

                    <h6>Avoid:</h6>
                    <ul class="small mb-0">
                        <li>Too generic (e.g., "printer")</li>
                        <li>Too specific (e.g., "HP DeskJet 2723 All-in-One Wireless Inkjet Colour Printer")</li>
                        <li>Special characters</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
