<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\BuildRouteWeatherPlanJob;
use App\Services\RouteWeatherService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class RouteWeatherController extends Controller
{
    public function plan(Request $request, RouteWeatherService $routeWeatherService): JsonResponse
    {
        $data = $this->validatePlan($request);

        $payload = $routeWeatherService->plan(
            $data['origin'],
            $data['destination'],
            isset($data['departure_at']) ? Carbon::parse($data['departure_at']) : null,
            isset($data['sample_interval_km']) ? (float) $data['sample_interval_km'] : null,
            array_key_exists('use_traffic', $data) ? (bool) $data['use_traffic'] : null,
        );

        return response()->json($payload);
    }

    /**
     * Compara várias janelas de partida e recomenda a de menor risco.
     */
    public function compare(Request $request, RouteWeatherService $routeWeatherService): JsonResponse
    {
        $maxWindows = (int) config('route_weather.compare_max_windows', 6);

        $data = $request->validate([
            'origin' => ['required', 'string', 'max:512'],
            'destination' => ['required', 'string', 'max:512'],
            'departure_windows' => ['required', 'array', 'min:1', "max:{$maxWindows}"],
            'departure_windows.*' => ['required', 'date'],
            'sample_interval_km' => ['nullable', 'numeric', 'between:1,250'],
            'use_traffic' => ['nullable', 'boolean'],
        ]);

        $interval = isset($data['sample_interval_km']) ? (float) $data['sample_interval_km'] : null;
        $useTraffic = array_key_exists('use_traffic', $data) ? (bool) $data['use_traffic'] : null;

        $options = [];
        foreach ($data['departure_windows'] as $window) {
            $departure = Carbon::parse($window);
            $plan = $routeWeatherService->plan(
                $data['origin'],
                $data['destination'],
                $departure,
                $interval,
                $useTraffic,
            );

            $lastEta = $plan['timeline'] === [] ? 0 : (int) end($plan['timeline'])['eta_offset_seconds'];

            $options[] = [
                'departure_at' => $departure->toIso8601String(),
                'arrival_at' => $departure->copy()->addSeconds($lastEta)->toIso8601String(),
                'route' => [
                    'total_distance_m' => $plan['route']['total_distance_m'],
                    'total_duration_s' => $plan['route']['total_duration_s'],
                ],
                'risk' => [
                    'score' => $plan['risk']['score'],
                    'summary' => $plan['risk']['summary'],
                    'alerts_count' => count($plan['risk']['alerts']),
                ],
            ];
        }

        usort($options, function (array $a, array $b): int {
            return [$a['risk']['score'], $a['departure_at']] <=> [$b['risk']['score'], $b['departure_at']];
        });

        $best = $options[0] ?? null;

        return response()->json([
            'origin' => $data['origin'],
            'destination' => $data['destination'],
            'options' => $options,
            'best' => $best === null ? null : [
                'departure_at' => $best['departure_at'],
                'score' => $best['risk']['score'],
            ],
            'recommendation' => $this->buildRecommendation($options),
        ]);
    }

    /**
     * Enfileira o cálculo do plano para rotas longas e devolve um identificador de acompanhamento.
     */
    public function planAsync(Request $request): JsonResponse
    {
        $data = $this->validatePlan($request);

        $jobId = (string) Str::uuid();
        $cacheKey = $this->jobCacheKey($jobId);

        Cache::put($cacheKey, ['status' => 'pending'], now()->addMinutes(30));

        BuildRouteWeatherPlanJob::dispatch(
            $cacheKey,
            $data['origin'],
            $data['destination'],
            $data['departure_at'] ?? null,
            isset($data['sample_interval_km']) ? (float) $data['sample_interval_km'] : null,
            array_key_exists('use_traffic', $data) ? (bool) $data['use_traffic'] : null,
        );

        return response()->json([
            'job_id' => $jobId,
            'status' => Cache::get($cacheKey)['status'] ?? 'pending',
            'status_url' => url("/api/v1/route-weather/plan/status/{$jobId}"),
        ], 202);
    }

    /**
     * Consulta o resultado de um plano enfileirado.
     */
    public function planStatus(string $jobId): JsonResponse
    {
        $entry = Cache::get($this->jobCacheKey($jobId));

        if (! is_array($entry)) {
            return response()->json([
                'error' => ['code' => 'JOB_NOT_FOUND', 'message' => 'Plano não encontrado ou expirado.'],
            ], 404);
        }

        return response()->json(array_merge(['job_id' => $jobId], $entry));
    }

    public function health(): JsonResponse
    {
        return response()->json([
            'service' => 'RouteWeather API',
            'status' => 'ok',
            'dependencies' => [
                'google_maps' => config('route_weather.google_maps_api_key') !== null && config('route_weather.google_maps_api_key') !== '',
                'tomorrow_io' => config('route_weather.tomorrow_api_key') !== null && config('route_weather.tomorrow_api_key') !== '',
            ],
        ]);
    }

    public function docs(): JsonResponse
    {
        $path = base_path('openapi.json');
        if (! is_file($path)) {
            return response()->json([
                'error' => ['code' => 'SPEC_NOT_FOUND', 'message' => 'Especificação OpenAPI não disponível.'],
            ], 404);
        }

        $spec = json_decode((string) file_get_contents($path), true);

        return response()->json(is_array($spec) ? $spec : []);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePlan(Request $request): array
    {
        return $request->validate([
            'origin' => ['required', 'string', 'max:512'],
            'destination' => ['required', 'string', 'max:512'],
            'departure_at' => ['nullable', 'date'],
            'sample_interval_km' => ['nullable', 'numeric', 'between:1,250'],
            'use_traffic' => ['nullable', 'boolean'],
        ]);
    }

    private function jobCacheKey(string $jobId): string
    {
        return "route_weather:job:{$jobId}";
    }

    /**
     * @param  list<array<string, mixed>>  $options
     */
    private function buildRecommendation(array $options): string
    {
        if ($options === []) {
            return 'Sem janelas de partida para comparar.';
        }

        $best = $options[0];
        if (count($options) === 1) {
            return sprintf('Risco estimado de %d/100 para a partida selecionada.', $best['risk']['score']);
        }

        $worst = end($options);
        $bestTime = Carbon::parse($best['departure_at'])->format('H:i');

        if ($best['risk']['score'] === $worst['risk']['score']) {
            return sprintf('As janelas têm risco semelhante (%d/100). Escolha pela conveniência.', $best['risk']['score']);
        }

        return sprintf(
            'Melhor janela: saída às %s, com risco %d/100 (ante %d/100 da pior opção).',
            $bestTime,
            $best['risk']['score'],
            $worst['risk']['score'],
        );
    }
}
