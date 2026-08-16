<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\LinkController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Management & analytics API
|--------------------------------------------------------------------------
|
| Everything here is authenticated with a bearer API token and carries the
| ability required for the operation. This surface is served by the API/
| dashboard vhost, separately from the redirect vhost.
|
*/

Route::get('/health', HealthController::class)->name('health');

Route::middleware('api.token:links:read')->group(function () {
    Route::get('/links', [LinkController::class, 'index'])->name('links.index');
    Route::get('/links/{link}', [LinkController::class, 'show'])->name('links.show');
});

Route::middleware('api.token:links:write')->group(function () {
    Route::post('/links', [LinkController::class, 'store'])->name('links.store');
    Route::post('/links/bulk', [LinkController::class, 'bulkStore'])->name('links.bulk');
    Route::patch('/links/{link}', [LinkController::class, 'update'])->name('links.update');
    Route::delete('/links/{link}', [LinkController::class, 'destroy'])->name('links.destroy');
});

Route::middleware('api.token:analytics:read')->prefix('analytics')->group(function () {
    Route::get('/summary', [AnalyticsController::class, 'summary'])->name('analytics.summary');
    Route::get('/timeseries', [AnalyticsController::class, 'timeseries'])->name('analytics.timeseries');
    Route::get('/top-links', [AnalyticsController::class, 'topLinks'])->name('analytics.top-links');
    Route::get('/breakdown/{dimension}', [AnalyticsController::class, 'breakdown'])->name('analytics.breakdown');
    Route::get('/links/{link}', [AnalyticsController::class, 'linkStats'])->name('analytics.link');
});
