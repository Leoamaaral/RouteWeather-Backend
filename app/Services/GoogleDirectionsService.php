<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class GoogleDirectionsService
{
    public function __construct(
        private readonly string $apiKey,
    ) {}

    /**
     * @return array{
     *   polyline: string,
     *   summary: string,
     *   total_distance_m: float,
     *   total_duration_s: int,
     *   legs: list<array<string, mixed>>
     * }
     *
     * @throws RequestException
     */
    public function fetchRoute(string $origin, string $destination, ?int $departureTimestamp = null): array
    {
        $query = [
            'origin' => $origin,
            'destination' => $destination,
            'key' => $this->apiKey,
        ];

        if ($departureTimestamp !== null) {
            $query['departure_time'] = $departureTimestamp;
            $query['traffic_model'] = 'best_guess';
        }

        $response = Http::timeout(20)
            ->acceptJson()
            ->get('https://maps.googleapis.com/maps/api/directions/json', $query);

        $response->throw();

        $payload = $response->json();
        if (($payload['status'] ?? '') !== 'OK' || empty($payload['routes'][0])) {
            $status = $payload['status'] ?? 'UNKNOWN';
            $message = $payload['error_message'] ?? 'No route returned from Google Directions.';

            throw new \RuntimeException("Google Directions failed ({$status}): {$message}");
        }

        $route = $payload['routes'][0];
        $polyline = $route['overview_polyline']['points'] ?? '';
        if ($polyline === '') {
            throw new \RuntimeException('Google Directions returned an empty polyline.');
        }

        $totalDistanceM = 0.0;
        $totalDurationS = 0;

        foreach ($route['legs'] ?? [] as $leg) {
            $totalDistanceM += (float) ($leg['distance']['value'] ?? 0);
            $duration = $leg['duration_in_traffic']['value'] ?? $leg['duration']['value'] ?? 0;
            $totalDurationS += (int) $duration;
        }

        return [
            'polyline' => $polyline,
            'summary' => (string) ($route['summary'] ?? ''),
            'total_distance_m' => $totalDistanceM,
            'total_duration_s' => max($totalDurationS, 1),
            'legs' => $route['legs'] ?? [],
        ];
    }

    public function reverseGeocodeCity(float $lat, float $lng): ?string
    {
        $cacheKey = sprintf('gmaps:city:%0.3f:%0.3f', round($lat, 3), round($lng, 3));

        return Cache::remember($cacheKey, 86400, function () use ($lat, $lng) {
            $response = Http::timeout(20)
                ->acceptJson()
                ->get('https://maps.googleapis.com/maps/api/geocode/json', [
                    'latlng' => $lat.','.$lng,
                    'result_type' => 'locality|administrative_area_level_2',
                    'language' => 'pt-BR',
                    'key' => $this->apiKey,
                ]);

            $response->throw();
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
        });
    }
}
