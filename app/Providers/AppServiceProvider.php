<?php

namespace App\Providers;

use App\Services\GoogleDirectionsService;
use App\Services\PolylineDecoder;
use App\Services\RouteGeometryService;
use App\Services\RouteWeatherService;
use App\Services\TomorrowWeatherService;
use App\Services\TripRiskAnalyzer;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GoogleDirectionsService::class, function () {
            return new GoogleDirectionsService(
                (string) config('route_weather.google_maps_api_key'),
                (string) config('route_weather.geocode_language', 'pt-BR'),
            );
        });

        $this->app->singleton(TomorrowWeatherService::class, function () {
            return new TomorrowWeatherService(
                (string) config('route_weather.tomorrow_api_key'),
                rtrim((string) config('route_weather.tomorrow_base_url'), '/'),
                (int) config('route_weather.weather_cache_ttl_seconds'),
                (int) config('route_weather.weather_cache_latlng_round'),
            );
        });

        $this->app->singleton(RouteWeatherService::class, function ($app) {
            return new RouteWeatherService(
                $app->make(GoogleDirectionsService::class),
                $app->make(PolylineDecoder::class),
                $app->make(RouteGeometryService::class),
                $app->make(TomorrowWeatherService::class),
                $app->make(TripRiskAnalyzer::class),
                (float) config('route_weather.sample_interval_km'),
                (int) config('route_weather.sample_min_points'),
                (int) config('route_weather.sample_max_points'),
                (int) config('route_weather.plan_cache_ttl_seconds'),
            );
        });
    }

    public function boot(): void
    {
        //
    }
}
