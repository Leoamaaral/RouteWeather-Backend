<?php

namespace Tests\Unit;

use App\Services\GoogleDirectionsService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleDirectionsServiceTest extends TestCase
{
    public function test_parses_successful_directions_response(): void
    {
        Http::fake([
            'maps.googleapis.com/*' => Http::response([
                'status' => 'OK',
                'routes' => [[
                    'summary' => 'BR-116',
                    'overview_polyline' => ['points' => '_p~iF~ps|U_ulLnnqC_mqNvxq`@'],
                    'legs' => [[
                        'distance' => ['value' => 100_000],
                        'duration' => ['value' => 3600],
                    ]],
                ]],
            ], 200),
        ]);

        $service = new GoogleDirectionsService('test-key');
        $route = $service->fetchRoute('São Paulo, SP', 'Campinas, SP');

        $this->assertSame('BR-116', $route['summary']);
        $this->assertSame(100_000.0, $route['total_distance_m']);
        $this->assertSame(3600, $route['total_duration_s']);
        $this->assertNotSame('', $route['polyline']);
    }
}
