<?php

namespace App\Services;

use App\Exceptions\ExternalServiceException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class GoogleDirectionsService
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $geocodeLanguage = 'pt-BR',
    ) {}

    /**
     * @param  list<string>  $waypoints
     * @return array{
     *   polyline: string,
     *   summary: string,
     *   total_distance_m: float,
     *   total_duration_s: int,
     *   legs: list<array<string, mixed>>,
     *   duration_profile: list<array{distance_m: float, duration_s: float}>
     * }
     *
     * @throws RequestException|ExternalServiceException
     */
    public function fetchRoute(
        string $origin,
        string $destination,
        ?int $departureTimestamp = null,
        array $waypoints = [],
        bool $avoidTolls = false,
        bool $avoidHighways = false,
    ): array {
        $query = [
            'origin' => $origin,
            'destination' => $destination,
            'key' => $this->apiKey,
        ];

        if ($departureTimestamp !== null) {
            $query['departure_time'] = $departureTimestamp;
            $query['traffic_model'] = 'best_guess';
        }

        if ($waypoints !== []) {
            $query['waypoints'] = implode('|', $waypoints);
        }

        $avoid = [];
        if ($avoidTolls) {
            $avoid[] = 'tolls';
        }
        if ($avoidHighways) {
            $avoid[] = 'highways';
        }
        if ($avoid !== []) {
            $query['avoid'] = implode('|', $avoid);
        }

        $response = Http::timeout(20)
            ->acceptJson()
            ->get('https://maps.googleapis.com/maps/api/directions/json', $query);

        $response->throw();

        $payload = $response->json();
        $status = $payload['status'] ?? 'UNKNOWN';
        if ($status !== 'OK' || empty($payload['routes'][0])) {
            $message = $payload['error_message'] ?? 'No route returned from Google Directions.';

            if (in_array($status, ['ZERO_RESULTS', 'NOT_FOUND'], true)) {
                throw ExternalServiceException::routeNotFound(
                    'Não foi possível encontrar uma rota entre origem e destino.',
                    ['google_status' => $status],
                );
            }

            throw ExternalServiceException::directionsFailed(
                "Google Directions failed ({$status}): {$message}",
                ['google_status' => $status],
            );
        }

        $route = $payload['routes'][0];
        $polyline = $route['overview_polyline']['points'] ?? '';
        if ($polyline === '') {
            throw ExternalServiceException::directionsFailed('Google Directions returned an empty polyline.');
        }

        $legs = $route['legs'] ?? [];
        $useTraffic = $departureTimestamp !== null;

        $totalDistanceM = 0.0;
        $totalDurationS = 0;
        foreach ($legs as $leg) {
            $totalDistanceM += (float) ($leg['distance']['value'] ?? 0);
            $totalDurationS += (int) $this->legDurationSeconds($leg, $useTraffic);
        }

        return [
            'polyline' => $polyline,
            'summary' => (string) ($route['summary'] ?? ''),
            'total_distance_m' => $totalDistanceM,
            'total_duration_s' => max($totalDurationS, 1),
            'legs' => $legs,
            'duration_profile' => $this->buildDurationProfile($legs, $useTraffic),
        ];
    }

    /**
     * Constrói um perfil cumulativo distância→duração a partir dos passos da rota,
     * para estimar ETA por trecho em vez de assumir velocidade constante.
     *
     * @param  list<array<string, mixed>>  $legs
     * @return list<array{distance_m: float, duration_s: float}>
     */
    private function buildDurationProfile(array $legs, bool $useTraffic): array
    {
        $profile = [['distance_m' => 0.0, 'duration_s' => 0.0]];
        $cumulativeDist = 0.0;
        $cumulativeDur = 0.0;

        foreach ($legs as $leg) {
            $legDuration = (float) $this->legDurationSeconds($leg, $useTraffic);
            $steps = is_array($leg['steps'] ?? null) ? $leg['steps'] : [];

            if ($steps === []) {
                $cumulativeDist += (float) ($leg['distance']['value'] ?? 0);
                $cumulativeDur += $legDuration;
                $profile[] = ['distance_m' => $cumulativeDist, 'duration_s' => $cumulativeDur];

                continue;
            }

            $stepDurationSum = 0.0;
            foreach ($steps as $step) {
                $stepDurationSum += (float) ($step['duration']['value'] ?? 0);
            }
            $scale = $stepDurationSum > 0 ? $legDuration / $stepDurationSum : 1.0;

            foreach ($steps as $step) {
                $cumulativeDist += (float) ($step['distance']['value'] ?? 0);
                $cumulativeDur += (float) ($step['duration']['value'] ?? 0) * $scale;
                $profile[] = ['distance_m' => $cumulativeDist, 'duration_s' => $cumulativeDur];
            }
        }

        return $profile;
    }

    /**
     * @param  array<string, mixed>  $leg
     */
    private function legDurationSeconds(array $leg, bool $useTraffic): int
    {
        if ($useTraffic && isset($leg['duration_in_traffic']['value'])) {
            return (int) $leg['duration_in_traffic']['value'];
        }

        return (int) ($leg['duration']['value'] ?? 0);
    }

    public function reverseGeocodeCity(float $lat, float $lng): ?string
    {
        return $this->reverseGeocodeCities([['lat' => $lat, 'lng' => $lng]])[0] ?? null;
    }

    /**
     * Resolve cidades para vários pontos em paralelo, reutilizando cache por ponto.
     *
     * @param  list<array{lat: float, lng: float}>  $coords
     * @return list<?string> cidades na mesma ordem dos coords
     */
    public function reverseGeocodeCities(array $coords): array
    {
        if ($coords === [] || $this->apiKey === '') {
            return array_fill(0, count($coords), null);
        }

        $cities = [];
        $missesByKey = [];
        foreach ($coords as $i => $coord) {
            $key = $this->geocodeCacheKey($coord['lat'], $coord['lng']);
            $cached = Cache::get($key);
            if ($cached !== null) {
                $cities[$i] = $cached === '' ? null : $cached;

                continue;
            }
            $cities[$i] = null;
            $missesByKey[$key] ??= ['lat' => $coord['lat'], 'lng' => $coord['lng'], 'indexes' => []];
            $missesByKey[$key]['indexes'][] = $i;
        }

        if ($missesByKey === []) {
            return $cities;
        }

        $keys = array_keys($missesByKey);
        $responses = Http::pool(fn (Pool $pool) => array_map(
            fn (string $key) => $pool->as($key)
                ->timeout(20)
                ->acceptJson()
                ->get('https://maps.googleapis.com/maps/api/geocode/json', [
                    'latlng' => $missesByKey[$key]['lat'].','.$missesByKey[$key]['lng'],
                    'result_type' => 'locality|administrative_area_level_2',
                    'language' => $this->geocodeLanguage,
                    'key' => $this->apiKey,
                ]),
            $keys,
        ));

        foreach ($missesByKey as $key => $miss) {
            $city = $this->cityFromGeocodeResponse($responses[$key] ?? null);
            Cache::put($key, $city ?? '', 86400);
            foreach ($miss['indexes'] as $i) {
                $cities[$i] = $city;
            }
        }

        ksort($cities);

        return array_values($cities);
    }

    private function geocodeCacheKey(float $lat, float $lng): string
    {
        return sprintf('gmaps:city:%0.3f:%0.3f', round($lat, 3), round($lng, 3));
    }

    private function cityFromGeocodeResponse(mixed $response): ?string
    {
        if (! $response instanceof Response || ! $response->successful()) {
            return null;
        }

        $payload = $response->json();
        if (($payload['status'] ?? '') !== 'OK' || empty($payload['results'])) {
            return null;
        }

        foreach ($payload['results'] as $result) {
            $components = $result['address_components'] ?? [];
            if (! is_array($components)) {
                continue;
            }
            foreach ($components as $component) {
                $types = $component['types'] ?? [];
                if (! is_array($types)) {
                    continue;
                }
                if (in_array('locality', $types, true) || in_array('administrative_area_level_2', $types, true)) {
                    $name = $component['long_name'] ?? null;
                    if (is_string($name) && $name !== '') {
                        return $name;
                    }
                }
            }
        }

        return null;
    }
}
