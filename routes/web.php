<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// Redirect root to dashboard
Route::get('/', function () {
    return redirect()->route('dashboard.index');
});

// Dashboard routes
Route::prefix('dashboard')->name('dashboard.')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('index');
    Route::get('/platform/{platform}', [DashboardController::class, 'platform'])->name('platform');
    Route::get('/products', [DashboardController::class, 'products'])->name('products');
    Route::get('/logs', [DashboardController::class, 'logs'])->name('logs');
    
    // API endpoints for AJAX requests
    Route::get('/api/stats', [DashboardController::class, 'apiStats'])->name('api.stats');
});

