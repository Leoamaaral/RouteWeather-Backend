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

/**
 * Exemplo: processamento assíncrono para não bloquear o HTTP em rotas longas.
 * O cliente recebe um job_id e consulta o resultado em cache/DB quando pronto.
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
    ) {}

    public function handle(RouteWeatherService $routeWeatherService): void
    {
        $departure = $this->departureAtIso ? Carbon::parse($this->departureAtIso) : null;

        $result = $routeWeatherService->plan(
            $this->origin,
            $this->destination,
            $departure,
            $this->sampleIntervalKm,
        );

        Cache::put($this->cacheKey, $result, now()->addMinutes(30));
    }
}
