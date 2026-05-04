<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return ['service' => 'RouteWeather API', 'status' => 'ok'];
});
