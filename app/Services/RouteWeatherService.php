<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Orquestra rota (Google), geometria (amostragem + ETA) e clima (Tomorrow.io) + análise de risco.
 */
final class RouteWeatherService
{
    public function __construct(
        private readonly GoogleDirectionsService $directions,
        private readonly PolylineDecoder $polylineDecoder,
        private readonly RouteGeometryService $geometry,
        private readonly TomorrowWeatherService $weather,
        private readonly TripRiskAnalyzer $riskAnalyzer,
        private readonly float $defaultSampleKm,
        private readonly int $sampleMinPoints,
        private readonly int $sampleMaxPoints,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function plan(
        string $origin,
        string $destination,
        ?Carbon $departureAt = null,
        ?float $sampleIntervalKm = null,
        ?bool $useTraffic = null,
    ): array {
        $departureAt ??= Carbon::now();
        $intervalKm = $sampleIntervalKm ?? $this->defaultSampleKm;
        $useTraffic ??= true;

        $departureTimestamp = $useTraffic ? $departureAt->getTimestamp() : null;

        $route = $this->directions->fetchRoute($origin, $destination, $departureTimestamp);
        $points = $this->polylineDecoder->decode($route['polyline']);

        $samples = $this->geometry->sampleEveryKilometers(
            $points,
            $intervalKm,
            $this->sampleMinPoints,
            $this->sampleMaxPoints,
        );

        $totalMeters = $route['total_distance_m'] > 0
            ? $route['total_distance_m']
            : (end($samples)['distance_from_start_m'] ?? 1.0);

        $totalDuration = $route['total_duration_s'];

        $timeline = [];
        foreach ($samples as $sample) {
            $lat = (float) $sample['lat'];
            $lng = (float) $sample['lng'];
            $distanceM = (float) $sample['distance_from_start_m'];
            $ratio = $totalMeters > 0 ? min(1.0, max(0.0, $distanceM / $totalMeters)) : 0.0;
            $etaOffsetSeconds = (int) round($ratio * $totalDuration);
            $estimatedAt = $departureAt->copy()->addSeconds($etaOffsetSeconds);

            $forecast = $this->weather->forecastAt(
                $estimatedAt,
                $lat,
                $lng,
            );

            $city = null;
            try {
                $city = $this->directions->reverseGeocodeCity($lat, $lng);
            } catch (\Throwable) {
                $city = null;
            }

            $timeline[] = [
                'order' => (int) $sample['index'],
                'estimated_at' => $estimatedAt->toIso8601String(),
                'eta_offset_seconds' => $etaOffsetSeconds,
                'distance_from_start_km' => round($distanceM / 1000, 3),
                'location' => [
                    'lat' => round($lat, 6),
                    'lng' => round($lng, 6),
                    'city' => $city,
                ],
                'weather' => [
                    'temperature_c' => $forecast['temperature_c'],
                    'rain_probability' => $forecast['rain_probability'],
                    'condition' => $forecast['condition'],
                    'weather_code' => $forecast['weather_code'],
                    'precipitation_intensity_mm_h' => $forecast['precipitation_intensity_mm_h'],
                ],
            ];
        }

        $risk = $this->riskAnalyzer->analyze($timeline);

        return [
            'meta' => [
                'generated_at' => Carbon::now()->toIso8601String(),
                'departure_at' => $departureAt->toIso8601String(),
                'sample_interval_km' => $intervalKm,
            ],
            'route' => [
                'summary' => $route['summary'],
                'polyline' => $route['polyline'],
                'total_distance_m' => $route['total_distance_m'],
                'total_duration_s' => $route['total_duration_s'],
            ],
            'timeline' => $timeline,
            'risk' => $risk,
        ];
    }
}
