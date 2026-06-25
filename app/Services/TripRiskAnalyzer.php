<?php

namespace App\Services;

/**
 * Consolida sinais de precipitação, códigos meteorológicos e posição ao longo do trajeto.
 *
 * Lógica (v1):
 * - O score começa em 0 e sobe com probabilidade de chuva em cada ponto (peso médio ~30).
 * - Tempestades (códigos Tomorrow 8000–8003) adicionam peso alto e geram alerta dedicado.
 * - Chuva forte usa códigos explícitos (4201–4203, 4001) ou prob ≥ 0,65 ou intensidade ≥ 7,5 mm/h.
 * - Chuva leve: prob ≥ 0,35 sem classificação forte/tempestade.
 * - Neblina (visibilidade < 2 km), vento forte (> 60 km/h) e risco de gelo (≤ 3 °C com chuva)
 *   também elevam o score e geram alertas dedicados.
 * - Usamos também o percentil 90 das probabilidades para não “esconder” um trecho ruim atrás da média.
 * - O resumo textual prioriza tempestade, neblina, gelo, vento forte e chuva, nessa ordem.
 */
final class TripRiskAnalyzer
{
    /** @var list<int> */
    private const THUNDERSTORM_CODES = [8000, 8001, 8002, 8003];

    /** @var list<int> */
    private const HEAVY_RAIN_CODES = [4001, 4201, 4202, 4203];

    /** @var list<int> */
    private const LIGHT_RAIN_CODES = [4000, 4200];

    private const FOG_VISIBILITY_KM = 2.0;

    private const STRONG_WIND_KMH = 60.0;

    private const ICE_TEMPERATURE_C = 3.0;

    /**
     * @param  list<array{
     *   order: int,
     *   estimated_at: string,
     *   location: array{lat: float, lng: float},
     *   weather: array{
     *     temperature_c: ?float,
     *     rain_probability: ?float,
     *     condition: ?string,
     *     weather_code: ?int,
     *     precipitation_intensity_mm_h: ?float,
     *     wind_speed_kmh?: ?float,
     *     visibility_km?: ?float,
     *     cloud_cover?: ?float
     *   }
     * }>  $timeline
     * @return array{score: int, alerts: list<array<string, mixed>>, summary: string}
     */
    public function analyze(array $timeline): array
    {
        if ($timeline === []) {
            return [
                'score' => 0,
                'alerts' => [],
                'summary' => 'Sem dados suficientes para avaliar o risco da viagem.',
            ];
        }

        $count = count($timeline);
        $probs = [];
        $score = 0.0;

        $alerts = [];
        $middleStart = (int) floor($count / 3);
        $middleEnd = (int) ceil(2 * $count / 3);

        foreach ($timeline as $item) {
            $weather = $item['weather'] ?? [];
            $prob = isset($weather['rain_probability']) ? (float) $weather['rain_probability'] : null;
            if ($prob !== null) {
                $probs[] = $prob;
            }

            $code = isset($weather['weather_code']) ? (int) $weather['weather_code'] : null;
            $intensity = isset($weather['precipitation_intensity_mm_h'])
                ? (float) $weather['precipitation_intensity_mm_h']
                : null;
            $temp = isset($weather['temperature_c']) ? (float) $weather['temperature_c'] : null;
            $wind = isset($weather['wind_speed_kmh']) ? (float) $weather['wind_speed_kmh'] : null;
            $visibility = isset($weather['visibility_km']) ? (float) $weather['visibility_km'] : null;

            $score += $this->scoreFromProbability($prob);
            $score += $this->scoreFromWeatherCode($code);

            if ($intensity !== null && $intensity >= 7.5) {
                $score += 12;
            }

            $order = (int) ($item['order'] ?? 0);
            $segment = $this->segmentLabel($order, $middleStart, $middleEnd, $count);

            foreach ($this->buildAlertsForPoint($prob, $code, $intensity, $segment, $order) as $alert) {
                $alerts[] = $alert;
            }

            $score += $this->scoreFromConditions($visibility, $wind, $temp, $prob, $intensity, $code);
            foreach ($this->buildConditionAlerts($visibility, $wind, $temp, $prob, $intensity, $code, $segment, $order) as $alert) {
                $alerts[] = $alert;
            }
        }

        $p90 = $this->percentile($probs, 90);
        if ($p90 >= 0.55) {
            $score += 15;
        }

        $score = (int) round(min(100, max(0, $score)));

        $alerts = $this->dedupeAlerts($alerts);

        return [
            'score' => $score,
            'alerts' => $alerts,
            'summary' => $this->buildSummary($timeline, $alerts, $middleStart, $middleEnd),
        ];
    }

    private function scoreFromProbability(?float $prob): float
    {
        if ($prob === null) {
            return 0.0;
        }

        return min(28.0, $prob * 40.0);
    }

    private function scoreFromWeatherCode(?int $code): float
    {
        if ($code === null) {
            return 0.0;
        }

        if (in_array($code, self::THUNDERSTORM_CODES, true)) {
            return 38.0;
        }

        if (in_array($code, self::HEAVY_RAIN_CODES, true)) {
            return 22.0;
        }

        if (in_array($code, self::LIGHT_RAIN_CODES, true)) {
            return 10.0;
        }

        return 0.0;
    }

    private function scoreFromConditions(
        ?float $visibility,
        ?float $wind,
        ?float $temp,
        ?float $prob,
        ?float $intensity,
        ?int $code,
    ): float {
        $score = 0.0;

        if ($this->isFog($visibility)) {
            $score += 18.0;
        }
        if ($this->isStrongWind($wind)) {
            $score += 15.0;
        }
        if ($this->isIceRisk($temp, $prob, $intensity, $code)) {
            $score += 20.0;
        }

        return $score;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildConditionAlerts(
        ?float $visibility,
        ?float $wind,
        ?float $temp,
        ?float $prob,
        ?float $intensity,
        ?int $code,
        string $segment,
        int $order,
    ): array {
        $alerts = [];

        if ($this->isFog($visibility)) {
            $alerts[] = [
                'type' => 'fog',
                'label' => 'Neblina',
                'severity' => 'high',
                'segment' => $segment,
                'order' => $order,
            ];
        }

        if ($this->isStrongWind($wind)) {
            $alerts[] = [
                'type' => 'strong_wind',
                'label' => 'Vento forte',
                'severity' => 'high',
                'segment' => $segment,
                'order' => $order,
            ];
        }

        if ($this->isIceRisk($temp, $prob, $intensity, $code)) {
            $alerts[] = [
                'type' => 'ice_risk',
                'label' => 'Risco de gelo',
                'severity' => 'high',
                'segment' => $segment,
                'order' => $order,
            ];
        }

        return $alerts;
    }

    private function isFog(?float $visibility): bool
    {
        return $visibility !== null && $visibility < self::FOG_VISIBILITY_KM;
    }

    private function isStrongWind(?float $wind): bool
    {
        return $wind !== null && $wind > self::STRONG_WIND_KMH;
    }

    private function isIceRisk(?float $temp, ?float $prob, ?float $intensity, ?int $code): bool
    {
        if ($temp === null || $temp > self::ICE_TEMPERATURE_C) {
            return false;
        }

        $hasPrecip = ($prob !== null && $prob >= 0.3)
            || ($intensity !== null && $intensity > 0.0)
            || ($code !== null && in_array($code, [...self::LIGHT_RAIN_CODES, ...self::HEAVY_RAIN_CODES, ...self::THUNDERSTORM_CODES], true));

        return $hasPrecip;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildAlertsForPoint(
        ?float $prob,
        ?int $code,
        ?float $intensity,
        string $segment,
        int $order,
    ): array {
        $alerts = [];
        $storm = $code !== null && in_array($code, self::THUNDERSTORM_CODES, true);
        $heavyCode = $code !== null && in_array($code, self::HEAVY_RAIN_CODES, true);
        $heavyProb = $prob !== null && $prob >= 0.65;
        $heavyIntensity = $intensity !== null && $intensity >= 7.5;
        $heavy = $heavyCode || $heavyProb || $heavyIntensity;

        $lightCode = $code !== null && in_array($code, self::LIGHT_RAIN_CODES, true);
        $lightProb = $prob !== null && $prob >= 0.35;

        if ($storm) {
            $alerts[] = [
                'type' => 'storm',
                'label' => 'Tempestade',
                'severity' => 'critical',
                'segment' => $segment,
                'order' => $order,
            ];
        }

        if ($heavy && ! $storm) {
            $alerts[] = [
                'type' => 'heavy_rain',
                'label' => 'Chuva forte',
                'severity' => 'high',
                'segment' => $segment,
                'order' => $order,
            ];
        }

        if (($lightProb || $lightCode) && ! $heavy && ! $storm) {
            $alerts[] = [
                'type' => 'light_rain',
                'label' => 'Chuva leve',
                'severity' => 'low',
                'segment' => $segment,
                'order' => $order,
            ];
        }

        return $alerts;
    }

    private function segmentLabel(int $order, int $middleStart, int $middleEnd, int $count): string
    {
        if ($order <= 0) {
            return 'início';
        }
        if ($order >= $count - 1) {
            return 'final';
        }
        if ($order >= $middleStart && $order < $middleEnd) {
            return 'meio';
        }

        return 'trecho intermediário';
    }

    /**
     * @param  list<float>  $values
     */
    private function percentile(array $values, int $percentile): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $index = (int) ceil(($percentile / 100) * count($values)) - 1;
        $index = max(0, min(count($values) - 1, $index));

        return $values[$index];
    }

    /**
     * @param  list<array<string, mixed>>  $alerts
     * @return list<array<string, mixed>>
     */
    private function dedupeAlerts(array $alerts): array
    {
        $seen = [];
        $out = [];

        foreach ($alerts as $alert) {
            $key = ($alert['type'] ?? '').':'.($alert['severity'] ?? '').':'.($alert['segment'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $alert;
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $timeline
     * @param  list<array<string, mixed>>  $alerts
     */
    private function buildSummary(array $timeline, array $alerts, int $middleStart, int $middleEnd): string
    {
        $types = array_column($alerts, 'type');

        if (in_array('storm', $types, true)) {
            return 'Risco elevado: tempestade prevista em parte do trajeto. Considere adiar ou replanejar.';
        }

        if (in_array('ice_risk', $types, true)) {
            return 'Risco de gelo na pista: temperatura baixa com precipitação em parte do trajeto.';
        }

        if (in_array('fog', $types, true)) {
            return 'Visibilidade reduzida por neblina em trechos do percurso. Redobre a atenção.';
        }

        if (in_array('strong_wind', $types, true)) {
            return 'Ventos fortes previstos no trajeto; atenção a veículos altos e motos.';
        }

        $middleHeavy = false;
        foreach ($alerts as $alert) {
            if (($alert['type'] ?? '') === 'heavy_rain' && ($alert['segment'] ?? '') === 'meio') {
                $middleHeavy = true;
                break;
            }
        }

        if ($middleHeavy) {
            return 'Alta chance de chuva forte no meio do trajeto.';
        }

        if (in_array('heavy_rain', $types, true)) {
            return 'Chuva forte prevista em trechos do percurso.';
        }

        if (in_array('light_rain', $types, true)) {
            return 'Possibilidade de chuva leve em alguns pontos da rota.';
        }

        return 'Condições relativamente favoráveis ao longo da rota.';
    }
}
