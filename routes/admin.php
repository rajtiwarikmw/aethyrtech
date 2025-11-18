<?php

use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ReviewController;
use App\Http\Controllers\Admin\KeywordController;
use App\Http\Controllers\Admin\ScraperController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
|
| These routes are for the admin panel and require authentication
|
*/

Route::prefix('admin')->name('admin.')->group(function () {
    
    // Dashboard
    Route::get('/', function () {
        return view('admin.dashboard');
    })->name('dashboard');

    // Products Management
    Route::prefix('products')->name('products.')->group(function () {
        Route::get('/', [ProductController::class, 'index'])->name('index');
        Route::get('/export', [ProductController::class, 'export'])->name('export');
        Route::get('/{product}', [ProductController::class, 'show'])->name('show');
        Route::post('/{product}/update-status', [ProductController::class, 'updateIncludeExclude'])->name('update-status');
        Route::post('/bulk-update-status', [ProductController::class, 'bulkUpdateIncludeExclude'])->name('bulk-update-status');
        Route::get('/{product}/reviews', [ProductController::class, 'getReviews'])->name('reviews');
        Route::get('/{product}/rankings', [ProductController::class, 'getRankings'])->name('rankings');
    });

    // Reviews Management
    Route::prefix('reviews')->name('reviews.')->group(function () {
        Route::get('/', [ReviewController::class, 'index'])->name('index');
        Route::get('/export', [ReviewController::class, 'export'])->name('export');
        Route::get('/{review}', [ReviewController::class, 'show'])->name('show');
        Route::delete('/{review}', [ReviewController::class, 'destroy'])->name('destroy');
        Route::post('/bulk-delete', [ReviewController::class, 'bulkDelete'])->name('bulk-delete');
    });

    // Keywords Management
    Route::prefix('keywords')->name('keywords.')->group(function () {
        Route::get('/', [KeywordController::class, 'index'])->name('index');
        Route::get('/create', [KeywordController::class, 'create'])->name('create');
        Route::post('/', [KeywordController::class, 'store'])->name('store');
        Route::get('/{keyword}/edit', [KeywordController::class, 'edit'])->name('edit');
        Route::put('/{keyword}', [KeywordController::class, 'update'])->name('update');
        Route::delete('/{keyword}', [KeywordController::class, 'destroy'])->name('destroy');
        Route::post('/bulk-create', [KeywordController::class, 'bulkCreate'])->name('bulk-create');
        Route::post('/bulk-update-status', [KeywordController::class, 'bulkUpdateStatus'])->name('bulk-update-status');
        Route::post('/bulk-delete', [KeywordController::class, 'bulkDelete'])->name('bulk-delete');
        Route::get('/{keyword}/rankings', [KeywordController::class, 'rankings'])->name('rankings');
        Route::get('/export', [KeywordController::class, 'export'])->name('export');
    });

    // Scraper Management
    Route::prefix('scraper')->name('scraper.')->group(function () {
        Route::get('/', [ScraperController::class, 'index'])->name('index');
        Route::post('/run', [ScraperController::class, 'runScraper'])->name('run');
        Route::get('/status/{id}', [ScraperController::class, 'getStatus'])->name('status');
        Route::get('/history', [ScraperController::class, 'history'])->name('history');
        Route::get('/{id}', [ScraperController::class, 'show'])->name('show');
        Route::post('/{id}/stop', [ScraperController::class, 'stop'])->name('stop');
        Route::delete('/{id}', [ScraperController::class, 'destroy'])->name('destroy');
    });
});
