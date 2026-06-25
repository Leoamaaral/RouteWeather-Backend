<?php

namespace App\Jobs;

use App\Services\RouteWeatherService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Processa o plano de forma assíncrona para não bloquear o HTTP em rotas longas.
 * O cliente recebe um job_id e consulta o resultado em cache via /plan/status/{jobId}.
 */
class BuildRouteWeatherPlanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $cacheKey,
        public readonly string $origin,
        public readonly string $destination,
        public readonly ?string $departureAtIso = null,
        public readonly ?float $sampleIntervalKm = null,
        public readonly ?bool $useTraffic = null,
    ) {}

    public function handle(RouteWeatherService $routeWeatherService): void
    {
        $departure = $this->departureAtIso ? Carbon::parse($this->departureAtIso) : null;

        $result = $routeWeatherService->plan(
            $this->origin,
            $this->destination,
            $departure,
            $this->sampleIntervalKm,
            $this->useTraffic,
        );

        Cache::put($this->cacheKey, [
            'status' => 'completed',
            'result' => $result,
        ], now()->addMinutes(30));
    }

    public function failed(Throwable $exception): void
    {
        Cache::put($this->cacheKey, [
            'status' => 'failed',
            'error' => [
                'code' => 'PLAN_BUILD_FAILED',
                'message' => $exception->getMessage(),
            ],
        ], now()->addMinutes(30));
    }
}
