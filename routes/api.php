<?php

use App\Http\Controllers\Api\RouteWeatherController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', [RouteWeatherController::class, 'health']);
    Route::get('/docs', [RouteWeatherController::class, 'docs']);

    Route::middleware('throttle:30,1')->group(function () {
        Route::post('/route-weather/plan', [RouteWeatherController::class, 'plan']);
        Route::get('/route-weather/plan/status/{jobId}', [RouteWeatherController::class, 'planStatus']);
    });

    Route::middleware('throttle:10,1')->group(function () {
        Route::post('/route-weather/plan/async', [RouteWeatherController::class, 'planAsync']);
        Route::post('/route-weather/plan/compare', [RouteWeatherController::class, 'compare']);
    });
});
