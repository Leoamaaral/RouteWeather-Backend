<?php

use App\Http\Controllers\Api\RouteWeatherController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/route-weather/plan', [RouteWeatherController::class, 'plan']);
});
