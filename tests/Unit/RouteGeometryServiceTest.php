<?php

namespace Tests\Unit;

use App\Services\RouteGeometryService;
use PHPUnit\Framework\TestCase;

class RouteGeometryServiceTest extends TestCase
{
    public function test_samples_include_start_and_end(): void
    {
        $geometry = new RouteGeometryService;
        $points = [
            ['lat' => -23.55, 'lng' => -46.63],
            ['lat' => -23.56, 'lng' => -46.64],
            ['lat' => -23.57, 'lng' => -46.65],
        ];

        $samples = $geometry->sampleEveryKilometers($points, 0.05, 3, 20);

        $this->assertGreaterThanOrEqual(3, count($samples));
        $this->assertEquals(0.0, $samples[0]['distance_from_start_m'], '', 5.0);
    }
}
