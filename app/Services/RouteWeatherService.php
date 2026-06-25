<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Orquestra rota (Google), geometria (amostragem + ETA) e clima (Tomorrow.io) + análise de risco.
 *
 * As previsões e a geocodificação reversa dos pontos amostrados são resolvidas em paralelo,
 * e o plano completo é cacheado por um curto período para evitar recomputar requisições idênticas.
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
        private readonly int $planCacheTtlSeconds = 0,
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

        if ($this->planCacheTtlSeconds <= 0) {
            return $this->buildPlan($origin, $destination, $departureAt, $intervalKm, $useTraffic);
        }

        $cacheKey = $this->planCacheKey($origin, $destination, $departureAt, $intervalKm, $useTraffic);

        return Cache::remember(
            $cacheKey,
            $this->planCacheTtlSeconds,
            fn () => $this->buildPlan($origin, $destination, $departureAt, $intervalKm, $useTraffic),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPlan(
        string $origin,
        string $destination,
        Carbon $departureAt,
        float $intervalKm,
        bool $useTraffic,
    ): array {
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
        $profile = $route['duration_profile'] ?? [];

        $weatherRequests = [];
        $coords = [];
        $etaOffsets = [];
        $estimatedTimes = [];

        foreach ($samples as $i => $sample) {
            $lat = (float) $sample['lat'];
            $lng = (float) $sample['lng'];
            $distanceM = (float) $sample['distance_from_start_m'];

            $etaOffsetSeconds = $this->etaSecondsForDistance($profile, $distanceM, $totalMeters, $totalDuration);
            $estimatedAt = $departureAt->copy()->addSeconds($etaOffsetSeconds);

            $etaOffsets[$i] = $etaOffsetSeconds;
            $estimatedTimes[$i] = $estimatedAt;
            $coords[$i] = ['lat' => $lat, 'lng' => $lng];
            $weatherRequests[$i] = ['time' => $estimatedAt, 'lat' => $lat, 'lng' => $lng];
        }

        $forecasts = $this->weather->forecastBatch(array_values($weatherRequests));
        $cities = $this->directions->reverseGeocodeCities(array_values($coords));

        $timeline = [];
        $missingForecasts = 0;
        foreach ($samples as $i => $sample) {
            $forecast = $forecasts[$i] ?? [];
            if (($forecast['temperature_c'] ?? null) === null && ($forecast['weather_code'] ?? null) === null) {
                $missingForecasts++;
            }

            $timeline[] = [
                'order' => (int) $sample['index'],
                'estimated_at' => $estimatedTimes[$i]->toIso8601String(),
                'eta_offset_seconds' => $etaOffsets[$i],
                'distance_from_start_km' => round((float) $sample['distance_from_start_m'] / 1000, 3),
                'location' => [
                    'lat' => round((float) $sample['lat'], 6),
                    'lng' => round((float) $sample['lng'], 6),
                    'city' => $cities[$i] ?? null,
                ],
                'weather' => [
                    'temperature_c' => $forecast['temperature_c'] ?? null,
                    'rain_probability' => $forecast['rain_probability'] ?? null,
                    'condition' => $forecast['condition'] ?? null,
                    'weather_code' => $forecast['weather_code'] ?? null,
                    'precipitation_intensity_mm_h' => $forecast['precipitation_intensity_mm_h'] ?? null,
                    'wind_speed_kmh' => $forecast['wind_speed_kmh'] ?? null,
                    'visibility_km' => $forecast['visibility_km'] ?? null,
                    'cloud_cover' => $forecast['cloud_cover'] ?? null,
                ],
            ];
        }

        $risk = $this->riskAnalyzer->analyze($timeline);

        $warnings = [];
        if ($missingForecasts > 0) {
            $warnings[] = sprintf(
                '%d de %d pontos sem previsão meteorológica disponível.',
                $missingForecasts,
                count($timeline),
            );
        }

        return [
            'meta' => [
                'generated_at' => Carbon::now()->toIso8601String(),
                'departure_at' => $departureAt->toIso8601String(),
                'sample_interval_km' => $intervalKm,
                'warnings' => $warnings,
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

    /**
     * Interpola o tempo de viagem até uma distância usando o perfil real de trechos
     * do Google; cai para proporção linear quando o perfil não está disponível.
     *
     * @param  list<array{distance_m: float, duration_s: float}>  $profile
     */
    private function etaSecondsForDistance(array $profile, float $distanceM, float $totalMeters, int $totalDuration): int
    {
        if (count($profile) < 2) {
            $ratio = $totalMeters > 0 ? min(1.0, max(0.0, $distanceM / $totalMeters)) : 0.0;

            return (int) round($ratio * $totalDuration);
        }

        $n = count($profile);
        for ($i = 1; $i < $n; $i++) {
            $d0 = $profile[$i - 1]['distance_m'];
            $d1 = $profile[$i]['distance_m'];
            if ($distanceM <= $d1 || $i === $n - 1) {
                $segLen = max($d1 - $d0, 1e-6);
                $t = max(0.0, min(1.0, ($distanceM - $d0) / $segLen));
                $duration = $profile[$i - 1]['duration_s'] + $t * ($profile[$i]['duration_s'] - $profile[$i - 1]['duration_s']);

                return (int) round(max(0.0, min((float) $totalDuration, $duration)));
            }
        }

        return $totalDuration;
    }

    private function planCacheKey(
        string $origin,
        string $destination,
        Carbon $departureAt,
        float $intervalKm,
        bool $useTraffic,
    ): string {
        $hash = md5(implode('|', [
            mb_strtolower(trim($origin)),
            mb_strtolower(trim($destination)),
            $departureAt->copy()->utc()->format('Y-m-d-H'),
            (string) $intervalKm,
            $useTraffic ? '1' : '0',
        ]));

        return "route_weather:plan:{$hash}";
    }
}
