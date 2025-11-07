@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-10">
            <div class="card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h4 class="mb-0">Add Product URLs for Scraping</h4>
                        <a href="{{ route('admin.scraping-urls.index') }}" class="btn btn-secondary btn-sm">
                            <i class="fas fa-arrow-left"></i> Back to List
                        </a>
                    </div>
                </div>

                <div class="card-body">
                    @if($errors->any())
                        <div class="alert alert-danger">
                            <ul class="mb-0">
                                @foreach($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('admin.scraping-urls.store') }}">
                        @csrf

                        <div class="mb-4">
                            <label for="platform" class="form-label">Platform <span class="text-danger">*</span></label>
                            <select name="platform" id="platform" class="form-select" required>
                                <option value="">-- Select Platform --</option>
                                <option value="amazon" {{ old('platform') == 'amazon' ? 'selected' : '' }}>Amazon</option>
                                <option value="flipkart" {{ old('platform') == 'flipkart' ? 'selected' : '' }}>Flipkart</option>
                                <option value="vijaysales" {{ old('platform') == 'vijaysales' ? 'selected' : '' }}>VijaySales</option>
                                <option value="croma" {{ old('platform') == 'croma' ? 'selected' : '' }}>Croma</option>
                                <option value="reliancedigital" {{ old('platform') == 'reliancedigital' ? 'selected' : '' }}>Reliance Digital</option>
                                <option value="blinkit" {{ old('platform') == 'blinkit' ? 'selected' : '' }}>Blinkit</option>
                                <option value="bigbasket" {{ old('platform') == 'bigbasket' ? 'selected' : '' }}>BigBasket</option>
                            </select>
                            <small class="form-text text-muted">
                                Select the platform for which you want to add product URLs. You can only add URLs for one platform at a time.
                            </small>
                        </div>

                        <div class="mb-4">
                            <label for="urls" class="form-label">Product URLs <span class="text-danger">*</span></label>
                            <textarea name="urls" id="urls" class="form-control" rows="15" required placeholder="Enter one URL per line&#10;&#10;Example:&#10;https://www.amazon.in/dp/B08CFSZLQ4&#10;https://www.amazon.in/dp/B08L5TNJHG&#10;https://www.amazon.in/dp/B08L5WHQVR">{{ old('urls') }}</textarea>
                            <small class="form-text text-muted">
                                Enter one product URL per line. Duplicate URLs will be automatically skipped.
                            </small>
                        </div>

                        <div class="mb-4">
                            <label for="priority" class="form-label">Priority (Optional)</label>
                            <input type="number" name="priority" id="priority" class="form-control" min="0" max="100" value="{{ old('priority', 0) }}">
                            <small class="form-text text-muted">
                                Higher priority URLs will be scraped first. Default is 0.
                            </small>
                        </div>

                        <div class="alert alert-info">
                            <h6 class="alert-heading"><i class="fas fa-info-circle"></i> Important Notes:</h6>
                            <ul class="mb-0">
                                <li>Only add URLs from <strong>one platform</strong> at a time</li>
                                <li>Make sure URLs are valid product detail pages (PDP)</li>
                                <li>Duplicate URLs will be automatically skipped</li>
                                <li>URLs will be queued and processed by the scraper</li>
                                <li>You can check the status in the URL management page</li>
                            </ul>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="fas fa-plus"></i> Add URLs to Queue
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Examples Card -->
            <div class="card mt-4">
                <div class="card-header">
                    <h5 class="mb-0">URL Format Examples</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Amazon</h6>
                            <code>https://www.amazon.in/dp/B08CFSZLQ4</code>
                            <br><br>
                            
                            <h6>Flipkart</h6>
                            <code>https://www.flipkart.com/product/p/itm123456</code>
                            <br><br>
                            
                            <h6>VijaySales</h6>
                            <code>https://www.vijaysales.com/product-name/12345</code>
                        </div>
                        <div class="col-md-6">
                            <h6>Croma</h6>
                            <code>https://www.croma.com/product-name/p/123456</code>
                            <br><br>
                            
                            <h6>Reliance Digital</h6>
                            <code>https://www.reliancedigital.in/product/123456</code>
                            <br><br>
                            
                            <h6>Blinkit</h6>
                            <code>https://blinkit.com/prn/product-name/prid/123456</code>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Auto-detect platform from URL
document.getElementById('urls').addEventListener('blur', function() {
    const urls = this.value.trim();
    const platformSelect = document.getElementById('platform');
    
    if (!platformSelect.value && urls) {
        const firstUrl = urls.split('\n')[0].trim();
        
        if (firstUrl.includes('amazon.in')) {
            platformSelect.value = 'amazon';
        } else if (firstUrl.includes('flipkart.com')) {
            platformSelect.value = 'flipkart';
        } else if (firstUrl.includes('vijaysales.com')) {
            platformSelect.value = 'vijaysales';
        } else if (firstUrl.includes('croma.com')) {
            platformSelect.value = 'croma';
        } else if (firstUrl.includes('reliancedigital.in')) {
            platformSelect.value = 'reliancedigital';
        } else if (firstUrl.includes('blinkit.com')) {
            platformSelect.value = 'blinkit';
        } else if (firstUrl.includes('bigbasket.com')) {
            platformSelect.value = 'bigbasket';
        }
    }
});

// Count URLs
document.getElementById('urls').addEventListener('input', function() {
    const urls = this.value.trim().split('\n').filter(url => url.trim() !== '');
    const count = urls.length;
    
    if (count > 0) {
        this.setAttribute('placeholder', count + ' URL(s) entered');
    }
});
</script>
@endsection
