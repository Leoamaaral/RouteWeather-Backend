<?php

namespace Tests\Unit;

use App\Services\TripRiskAnalyzer;
use PHPUnit\Framework\TestCase;

class TripRiskAnalyzerTest extends TestCase
{
    public function test_detects_storm_and_sets_summary(): void
    {
        $analyzer = new TripRiskAnalyzer;
        $timeline = [
            $this->point(0, 0.1, 1000),
            $this->point(1, 0.2, 8000),
            $this->point(2, 0.1, 1000),
        ];

        $result = $analyzer->analyze($timeline);

        $this->assertGreaterThan(40, $result['score']);
        $types = array_column($result['alerts'], 'type');
        $this->assertContains('storm', $types);
        $this->assertStringContainsString('tempestade', strtolower($result['summary']));
    }

    public function test_heavy_rain_middle_summary(): void
    {
        $analyzer = new TripRiskAnalyzer;
        $timeline = [
            $this->point(0, 0.1, 1000),
            $this->point(1, 0.7, 1000),
            $this->point(2, 0.7, 1000),
            $this->point(3, 0.1, 1000),
        ];

        $result = $analyzer->analyze($timeline);

        $this->assertStringContainsString('meio', strtolower($result['summary']));
    }

    public function test_detects_fog_wind_and_ice_alerts(): void
    {
        $analyzer = new TripRiskAnalyzer;
        $timeline = [
            $this->point(0, 0.0, 1000, visibility: 1.0),
            $this->point(1, 0.0, 1000, wind: 75.0),
            $this->point(2, 0.4, 4200, temp: 1.0),
        ];

        $result = $analyzer->analyze($timeline);
        $types = array_column($result['alerts'], 'type');

        $this->assertContains('fog', $types);
        $this->assertContains('strong_wind', $types);
        $this->assertContains('ice_risk', $types);
        $this->assertGreaterThan(0, $result['score']);
    }

    /**
     * @return array<string, mixed>
     */
    private function point(
        int $order,
        float $prob,
        int $code,
        float $temp = 22.0,
        ?float $wind = null,
        ?float $visibility = null,
    ): array {
        return [
            'order' => $order,
            'estimated_at' => '2026-04-04T12:00:00Z',
            'location' => ['lat' => 0.0, 'lng' => 0.0],
            'weather' => [
                'temperature_c' => $temp,
                'rain_probability' => $prob,
                'condition' => 'test',
                'weather_code' => $code,
                'precipitation_intensity_mm_h' => 0.0,
                'wind_speed_kmh' => $wind,
                'visibility_km' => $visibility,
                'cloud_cover' => null,
            ],
        ];
    }
}
