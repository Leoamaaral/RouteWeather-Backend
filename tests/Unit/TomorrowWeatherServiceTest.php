<?php

namespace Tests\Unit;

use App\Services\TomorrowWeatherService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TomorrowWeatherServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_uses_cache_between_identical_lookups(): void
    {
        Http::fake([
            'api.tomorrow.io/*' => Http::response($this->timelinePayload(), 200),
        ]);

        $service = new TomorrowWeatherService('k', 'https://api.tomorrow.io/v4', 600, 2);
        $time = Carbon::parse('2026-04-04T15:00:00Z');

        $first = $service->forecastAt($time, -23.55, -46.63);
        $second = $service->forecastAt($time, -23.55, -46.63);

        $this->assertSame($first['temperature_c'], $second['temperature_c']);
        $this->assertCount(1, Http::recorded());
    }

    public function test_same_utc_hour_different_minutes_reuses_one_http_request(): void
    {
        Http::fake([
            'api.tomorrow.io/*' => Http::response($this->timelinePayload(), 200),
        ]);

        $service = new TomorrowWeatherService('k', 'https://api.tomorrow.io/v4', 600, 2);
        $early = Carbon::parse('2026-04-04T15:12:00Z');
        $late = Carbon::parse('2026-04-04T15:48:00Z');

        $service->forecastAt($early, -23.55, -46.63);
        $service->forecastAt($late, -23.55, -46.63);

        $this->assertCount(1, Http::recorded());
    }

    /**
     * @return array<string, mixed>
     */
    private function timelinePayload(): array
    {
        return [
            'data' => [
                'timelines' => [[
                    'intervals' => [[
                        'startTime' => '2026-04-04T15:00:00Z',
                        'values' => [
                            'temperature' => 21.5,
                            'precipitationProbability' => 40,
                            'weatherCode' => 4200,
                            'precipitationIntensity' => 1.2,
                        ],
                    ]],
                ]],
            ],
        ];
    }
}
