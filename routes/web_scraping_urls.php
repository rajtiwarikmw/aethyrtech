<?php

use App\Http\Controllers\ScrapingUrlController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Scraping URLs Management Routes
|--------------------------------------------------------------------------
|
| These routes handle the admin interface for managing product URLs
| that need to be scraped.
|
*/

Route::prefix('admin')->group(function () {
    Route::prefix('scraping-urls')->name('admin.scraping-urls.')->group(function () {
        Route::get('/', [ScrapingUrlController::class, 'index'])->name('index');
        Route::get('/create', [ScrapingUrlController::class, 'create'])->name('create');
        Route::post('/', [ScrapingUrlController::class, 'store'])->name('store');
        Route::post('/{id}/retry', [ScrapingUrlController::class, 'retry'])->name('retry');
        Route::delete('/{id}', [ScrapingUrlController::class, 'destroy'])->name('destroy');
        Route::post('/bulk-delete', [ScrapingUrlController::class, 'bulkDelete'])->name('bulk-delete');
        Route::post('/bulk-retry', [ScrapingUrlController::class, 'bulkRetry'])->name('bulk-retry');
    });
});
