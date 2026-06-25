<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Integração com Tomorrow.io (timelines). Normaliza temperatura, probabilidade de chuva e condição.
 *
 * O cache guarda o JSON bruto da timeline por (local aproximado + hora UTC), e cada ETA reavalia
 * qual intervalo horário usar. Falhas de rede/API não são persistidas no cache (evita “—” por 15 min).
 */
final class TomorrowWeatherService
{
    /** @var list<string> */
    private const FIELDS = [
        'temperature',
        'precipitationProbability',
        'weatherCode',
        'precipitationIntensity',
        'windSpeed',
        'visibility',
        'cloudCover',
    ];

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly int $cacheTtlSeconds,
        private readonly int $latLngRound,
    ) {}

    /**
     * @return array{
     *   temperature_c: ?float,
     *   rain_probability: ?float,
     *   condition: ?string,
     *   weather_code: ?int,
     *   precipitation_intensity_mm_h: ?float,
     *   wind_speed_kmh: ?float,
     *   visibility_km: ?float,
     *   cloud_cover: ?float
     * }
     */
    public function forecastAt(Carbon $time, float $lat, float $lng): array
    {
        if ($this->apiKey === '') {
            return $this->emptyForecast();
        }

        $cacheKey = $this->timelineCacheKey($lat, $lng, $time);

        $cachedJson = Cache::get($cacheKey);
        if (is_array($cachedJson)) {
            $fromCache = $this->parseTimelineResponse($cachedJson, $time);
            if (! $this->forecastIsEmpty($fromCache)) {
                return $fromCache;
            }
        }

        $json = $this->fetchTimelineJsonWithRetries($time, $lat, $lng);
        if ($json === null) {
            return $this->emptyForecast();
        }

        $parsed = $this->parseTimelineResponse($json, $time);

        if (! $this->forecastIsEmpty($parsed)) {
            Cache::put($cacheKey, $json, $this->cacheTtlSeconds);
        }

        return $parsed;
    }

    /**
     * Resolve a previsão de vários pontos em paralelo, deduplicando os que caem
     * na mesma chave de cache (mesma hora UTC + local arredondado).
     *
     * @param  list<array{time: Carbon, lat: float, lng: float}>  $points
     * @return list<array<string, mixed>> previsões na mesma ordem dos pontos
     */
    public function forecastBatch(array $points): array
    {
        $results = array_fill(0, count($points), $this->emptyForecast());
        if ($points === [] || $this->apiKey === '') {
            return $results;
        }

        $groups = [];
        foreach ($points as $i => $point) {
            $key = $this->timelineCacheKey($point['lat'], $point['lng'], $point['time']);
            $groups[$key] ??= ['lat' => $point['lat'], 'lng' => $point['lng'], 'members' => []];
            $groups[$key]['members'][] = ['index' => $i, 'time' => $point['time']];
        }

        $jsonByKey = [];
        $misses = [];
        foreach ($groups as $key => $group) {
            $cached = Cache::get($key);
            if (is_array($cached)) {
                $jsonByKey[$key] = $cached;
            } else {
                $misses[$key] = $group;
            }
        }

        if ($misses !== []) {
            $keys = array_keys($misses);
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $key) => $pool->as($key)
                    ->withQueryParameters(['apikey' => $this->apiKey])
                    ->timeout(25)
                    ->acceptJson()
                    ->retry(3, 100, throw: false)
                    ->post(
                        $this->baseUrl.'/timelines',
                        $this->timelineRequestBody($misses[$key]['members'][0]['time'], $misses[$key]['lat'], $misses[$key]['lng']),
                    ),
                $keys,
            ));

            foreach (array_keys($misses) as $key) {
                $response = $responses[$key] ?? null;
                if ($response instanceof Response && $response->successful()) {
                    $jsonByKey[$key] = $response->json() ?? [];
                } else {
                    Log::warning('Tomorrow.io batch request failed', ['key' => $key]);
                }
            }
        }

        foreach ($groups as $key => $group) {
            $json = $jsonByKey[$key] ?? null;
            if ($json === null) {
                continue;
            }

            $shouldCache = isset($misses[$key]);
            foreach ($group['members'] as $member) {
                $parsed = $this->parseTimelineResponse($json, $member['time']);
                $results[$member['index']] = $parsed;
                if ($shouldCache && ! $this->forecastIsEmpty($parsed)) {
                    Cache::put($key, $json, $this->cacheTtlSeconds);
                    $shouldCache = false;
                }
            }
        }

        return $results;
    }

    private function timelineCacheKey(float $lat, float $lng, Carbon $time): string
    {
        $rlat = round($lat, $this->latLngRound);
        $rlng = round($lng, $this->latLngRound);
        $hour = $time->copy()->utc()->format('Y-m-d-H');

        return "tomorrow:tl:raw:{$rlat}:{$rlng}:{$hour}";
    }

    /**
     * @return array<string, mixed>
     */
    private function timelineRequestBody(Carbon $time, float $lat, float $lng): array
    {
        return [
            'location' => $lat.','.$lng,
            'fields' => self::FIELDS,
            'units' => 'metric',
            'timesteps' => ['1h'],
            'startTime' => $time->copy()->subHours(2)->toIso8601String(),
            'endTime' => $time->copy()->addHours(4)->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function forecastIsEmpty(array $parsed): bool
    {
        return ($parsed['temperature_c'] ?? null) === null
            && ($parsed['rain_probability'] ?? null) === null
            && ($parsed['weather_code'] ?? null) === null
            && ($parsed['precipitation_intensity_mm_h'] ?? null) === null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchTimelineJsonWithRetries(Carbon $time, float $lat, float $lng): ?array
    {
        $attempts = 3;
        $lastThrowable = null;

        for ($i = 0; $i < $attempts; $i++) {
            if ($i > 0) {
                usleep(100_000 * (2 ** ($i - 1)));
            }

            try {
                $response = Http::withQueryParameters(['apikey' => $this->apiKey])
                    ->timeout(25)
                    ->acceptJson()
                    ->post($this->baseUrl.'/timelines', $this->timelineRequestBody($time, $lat, $lng));

                $response->throw();

                return $response->json() ?? [];
            } catch (RequestException $e) {
                $lastThrowable = $e;
                $status = $e->response?->status() ?? 0;
                if ($status >= 400 && $status < 500 && $status !== 429) {
                    Log::warning('Tomorrow.io client error', [
                        'message' => $e->getMessage(),
                        'status' => $status,
                        'lat' => $lat,
                        'lng' => $lng,
                    ]);

                    return null;
                }
            } catch (\Throwable $e) {
                $lastThrowable = $e;
            }
        }

        if ($lastThrowable !== null) {
            Log::warning('Tomorrow.io request failed after retries', [
                'message' => $lastThrowable->getMessage(),
                'lat' => $lat,
                'lng' => $lng,
            ]);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @return array<int, array<string, mixed>>
     */
    private function extractIntervals(?array $json): array
    {
        $timelines = data_get($json, 'data.timelines');
        if (! is_array($timelines) || $timelines === []) {
            return [];
        }

        foreach ($timelines as $tl) {
            if (! is_array($tl)) {
                continue;
            }
            $intervals = $tl['intervals'] ?? [];
            if (($tl['timestep'] ?? '') === '1h' && is_array($intervals) && $intervals !== []) {
                return $intervals;
            }
        }

        $first = $timelines[0];

        return is_array($first) && is_array($first['intervals'] ?? null)
            ? $first['intervals']
            : [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $intervals
     * @return array<string, mixed>|null
     */
    private function pickIntervalForTime(array $intervals, Carbon $targetTime): ?array
    {
        $targetTs = $targetTime->getTimestamp();

        $parsed = [];
        foreach ($intervals as $interval) {
            if (! is_array($interval)) {
                continue;
            }
            $startRaw = $interval['startTime'] ?? $interval['time'] ?? null;
            if (! is_string($startRaw)) {
                continue;
            }
            $ts = strtotime($startRaw);
            if ($ts === false) {
                continue;
            }
            $parsed[] = ['ts' => $ts, 'interval' => $interval];
        }

        if ($parsed === []) {
            return null;
        }

        usort($parsed, fn (array $a, array $b): int => $a['ts'] <=> $b['ts']);

        $n = count($parsed);
        for ($i = 0; $i < $n; $i++) {
            $start = $parsed[$i]['ts'];
            $end = $i + 1 < $n ? $parsed[$i + 1]['ts'] : $start + 3600;
            if ($targetTs >= $start && $targetTs < $end) {
                return $parsed[$i]['interval'];
            }
        }

        $best = null;
        $bestDiff = PHP_INT_MAX;
        foreach ($parsed as $row) {
            $diff = abs($row['ts'] - $targetTs);
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $best = $row['interval'];
            }
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @return array<string, mixed>
     */
    private function parseTimelineResponse(?array $json, Carbon $targetTime): array
    {
        $intervals = $this->extractIntervals($json);
        if ($intervals === []) {
            return $this->emptyForecast();
        }

        $best = $this->pickIntervalForTime($intervals, $targetTime);
        if ($best === null) {
            return $this->emptyForecast();
        }

        $values = is_array($best['values'] ?? null) ? $best['values'] : [];

        $temp = isset($values['temperature']) ? (float) $values['temperature'] : null;
        $prob = isset($values['precipitationProbability']) ? (float) $values['precipitationProbability'] : null;
        if ($prob !== null) {
            $prob = $prob > 1 ? $prob / 100 : $prob;
        }
        $code = isset($values['weatherCode']) ? (int) $values['weatherCode'] : null;
        $intensity = isset($values['precipitationIntensity']) ? (float) $values['precipitationIntensity'] : null;
        $windKmh = isset($values['windSpeed']) ? round(((float) $values['windSpeed']) * 3.6, 1) : null;
        $visibility = isset($values['visibility']) ? (float) $values['visibility'] : null;
        $cloudCover = isset($values['cloudCover']) ? (float) $values['cloudCover'] : null;

        return [
            'temperature_c' => $temp,
            'rain_probability' => $prob,
            'condition' => $this->describeCode($code),
            'weather_code' => $code,
            'precipitation_intensity_mm_h' => $intensity,
            'wind_speed_kmh' => $windKmh,
            'visibility_km' => $visibility,
            'cloud_cover' => $cloudCover,
        ];
    }

    private function describeCode(?int $code): ?string
    {
        if ($code === null) {
            return null;
        }

        return match (true) {
            in_array($code, [8000, 8001, 8002, 8003], true) => 'tempestade',
            in_array($code, [4201, 4202, 4203, 4001], true) => 'chuva forte',
            in_array($code, [4000, 4200], true) => 'chuva leve',
            $code === 1000 => 'limpo',
            default => 'condição mista',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyForecast(): array
    {
        return [
            'temperature_c' => null,
            'rain_probability' => null,
            'condition' => null,
            'weather_code' => null,
            'precipitation_intensity_mm_h' => null,
            'wind_speed_kmh' => null,
            'visibility_km' => null,
            'cloud_cover' => null,
        ];
    }
}
