<?php

namespace Tests\Unit;

use App\Services\RouteWeatherService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RouteWeatherServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_builds_timeline_with_risk_block(): void
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
                                'distance' => ['value' => 120_000],
                                'duration' => ['value' => 7200],
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
                        'intervals' => [[
                            'startTime' => '2026-04-04T12:00:00Z',
                            'values' => [
                                'temperature' => 24.0,
                                'precipitationProbability' => 10,
                                'weatherCode' => 1000,
                                'precipitationIntensity' => 0.0,
                            ],
                        ]],
                    ]],
                ],
            ], 200),
        ]);

        $this->app['config']->set('route_weather.google_maps_api_key', 'gk');
        $this->app['config']->set('route_weather.tomorrow_api_key', 'tk');
        $this->app['config']->set('route_weather.sample_interval_km', 50);
        $this->app['config']->set('route_weather.sample_min_points', 3);
        $this->app['config']->set('route_weather.sample_max_points', 12);

        /** @var RouteWeatherService $service */
        $service = $this->app->make(RouteWeatherService::class);

        $payload = $service->plan(
            'São Paulo, SP',
            'Rio de Janeiro, RJ',
            null,
            400.0,
            false,
        );

        $this->assertArrayHasKey('timeline', $payload);
        $this->assertArrayHasKey('risk', $payload);
        $this->assertNotEmpty($payload['timeline']);
        $this->assertArrayHasKey('score', $payload['risk']);
        $this->assertArrayHasKey('weather', $payload['timeline'][0]);
        $this->assertSame('São Paulo', $payload['timeline'][0]['location']['city']);
    }
}
