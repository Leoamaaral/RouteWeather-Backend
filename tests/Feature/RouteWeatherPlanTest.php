<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RouteWeatherPlanTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->app['config']->set('route_weather.google_maps_api_key', 'gk');
        $this->app['config']->set('route_weather.tomorrow_api_key', 'tk');
        $this->app['config']->set('route_weather.sample_interval_km', 50);
        $this->app['config']->set('route_weather.sample_min_points', 3);
        $this->app['config']->set('route_weather.sample_max_points', 8);
    }

    public function test_plan_returns_timeline_risk_and_weather_fields(): void
    {
        $this->fakeUpstreams();

        $response = $this->postJson('/v1/route-weather/plan', [
            'origin' => 'São Paulo, SP',
            'destination' => 'Rio de Janeiro, RJ',
            'sample_interval_km' => 200,
            'use_traffic' => false,
        ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'meta' => ['generated_at', 'departure_at', 'sample_interval_km', 'warnings'],
            'route' => ['summary', 'polyline', 'total_distance_m', 'total_duration_s'],
            'timeline' => [['order', 'estimated_at', 'eta_offset_seconds', 'location' => ['lat', 'lng', 'city'], 'weather' => ['temperature_c', 'wind_speed_kmh', 'visibility_km', 'cloud_cover']]],
            'risk' => ['score', 'alerts', 'summary'],
        ]);

        $this->assertSame('São Paulo', $response->json('timeline.0.location.city'));
        $this->assertNotNull($response->json('timeline.0.weather.wind_speed_kmh'));
    }

    public function test_plan_validates_required_fields(): void
    {
        $response = $this->postJson('/v1/route-weather/plan', [
            'destination' => 'Rio de Janeiro, RJ',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $response->assertJsonStructure(['error' => ['code', 'message', 'details']]);
    }

    public function test_plan_maps_google_zero_results_to_404(): void
    {
        Http::fake([
            'maps.googleapis.com/*' => Http::response(['status' => 'ZERO_RESULTS', 'routes' => []], 200),
            'api.tomorrow.io/*' => Http::response([], 200),
        ]);

        $response = $this->postJson('/v1/route-weather/plan', [
            'origin' => 'Lugar inexistente',
            'destination' => 'Outro lugar',
        ]);

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'ROUTE_NOT_FOUND');
    }

    public function test_compare_ranks_departure_windows(): void
    {
        $this->fakeUpstreams();

        $response = $this->postJson('/v1/route-weather/plan/compare', [
            'origin' => 'São Paulo, SP',
            'destination' => 'Rio de Janeiro, RJ',
            'departure_windows' => ['2026-05-04T06:00:00-03:00', '2026-05-04T09:00:00-03:00'],
            'use_traffic' => false,
        ]);

        $response->assertOk();
        $response->assertJsonCount(2, 'options');
        $response->assertJsonStructure([
            'origin', 'destination', 'best' => ['departure_at', 'score'], 'recommendation',
            'options' => [['departure_at', 'arrival_at', 'route', 'risk' => ['score', 'summary', 'alerts_count']]],
        ]);
    }

    public function test_async_plan_and_status_flow(): void
    {
        $this->fakeUpstreams();

        $dispatch = $this->postJson('/v1/route-weather/plan/async', [
            'origin' => 'São Paulo, SP',
            'destination' => 'Rio de Janeiro, RJ',
            'use_traffic' => false,
        ]);

        $dispatch->assertStatus(202);
        $jobId = $dispatch->json('job_id');
        $this->assertNotEmpty($jobId);

        $status = $this->getJson("/v1/route-weather/plan/status/{$jobId}");
        $status->assertOk();
        $status->assertJsonPath('status', 'completed');
        $status->assertJsonStructure(['job_id', 'status', 'result' => ['timeline', 'risk']]);
    }

    public function test_status_returns_404_for_unknown_job(): void
    {
        $response = $this->getJson('/v1/route-weather/plan/status/does-not-exist');

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'JOB_NOT_FOUND');
    }

    public function test_health_reports_dependency_configuration(): void
    {
        $response = $this->getJson('/v1/health');

        $response->assertOk();
        $response->assertJsonPath('status', 'ok');
        $response->assertJsonPath('dependencies.google_maps', true);
        $response->assertJsonPath('dependencies.tomorrow_io', true);
    }

    public function test_docs_returns_openapi_spec(): void
    {
        $response = $this->getJson('/v1/docs');

        $response->assertOk();
        $response->assertJsonPath('openapi', '3.0.3');
        $response->assertJsonStructure(['info' => ['title'], 'paths']);
    }

    private function fakeUpstreams(): void
    {
        Http::fake([
            'maps.googleapis.com/*' => function (Request $request) {
                if (str_contains($request->url(), '/directions/')) {
                    return Http::response([
                        'status' => 'OK',
                        'routes' => [[
                            'summary' => 'BR-116',
                            'overview_polyline' => ['points' => '_p~iF~ps|U_ulLnnqC_mqNvxq`@'],
                            'legs' => [[
                                'distance' => ['value' => 400_000],
                                'duration' => ['value' => 18_000],
                            ]],
                        ]],
                    ], 200);
                }

                return Http::response([
                    'status' => 'OK',
                    'results' => [[
                        'address_components' => [[
                            'long_name' => 'São Paulo',
                            'types' => ['locality', 'political'],
                        ]],
                    ]],
                ], 200);
            },
            'api.tomorrow.io/*' => Http::response([
                'data' => [
                    'timelines' => [[
                        'timestep' => '1h',
                        'intervals' => [[
                            'startTime' => '2026-05-04T09:00:00Z',
                            'values' => [
                                'temperature' => 23.0,
                                'precipitationProbability' => 15,
                                'weatherCode' => 1000,
                                'precipitationIntensity' => 0.0,
                                'windSpeed' => 5.0,
                                'visibility' => 16.0,
                                'cloudCover' => 20.0,
                            ],
                        ]],
                    ]],
                ],
            ], 200),
        ]);
    }
}
