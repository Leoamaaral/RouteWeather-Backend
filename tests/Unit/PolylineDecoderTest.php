<?php

namespace Tests\Unit;

use App\Services\PolylineDecoder;
use PHPUnit\Framework\TestCase;

class PolylineDecoderTest extends TestCase
{
    public function test_decodes_google_sample_polyline(): void
    {
        $decoder = new PolylineDecoder;
        $points = $decoder->decode('_p~iF~ps|U_ulLnnqC_mqNvxq`@');

        $this->assertGreaterThan(1, count($points));
        $this->assertEqualsWithDelta(38.5, $points[0]['lat'], 0.01);
        $this->assertEqualsWithDelta(-120.2, $points[0]['lng'], 0.01);
    }
}
