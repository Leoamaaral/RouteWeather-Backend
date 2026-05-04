<?php

namespace App\Services;

/**
 * Distâncias ao longo da polyline e amostragem a cada N km.
 */
final class RouteGeometryService
{
    /**
     * @param  list<array{lat: float, lng: float}>  $points
     * @return list<float> cumulativa em metros, mesmo índice que $points
     */
    public function cumulativeDistancesMeters(array $points): array
    {
        $cumulative = [];
        $sum = 0.0;
        $cumulative[] = 0.0;
        $n = count($points);

        for ($i = 1; $i < $n; $i++) {
            $sum += $this->haversineMeters(
                $points[$i - 1]['lat'],
                $points[$i - 1]['lng'],
                $points[$i]['lat'],
                $points[$i]['lng'],
            );
            $cumulative[] = $sum;
        }

        return $cumulative;
    }

    /**
     * Amostra pontos ao longo da polyline a cada $intervalKm, incluindo início e fim.
     *
     * @param  list<array{lat: float, lng: float}>  $points
     * @return list<array{lat: float, lng: float, distance_from_start_m: float, index: int}>
     */
    public function sampleEveryKilometers(array $points, float $intervalKm, int $minPoints, int $maxPoints): array
    {
        if ($points === []) {
            return [];
        }

        $cumulative = $this->cumulativeDistancesMeters($points);
        $totalM = end($cumulative) ?: 0.0;
        if ($totalM <= 0) {
            return [[
                'lat' => $points[0]['lat'],
                'lng' => $points[0]['lng'],
                'distance_from_start_m' => 0.0,
                'index' => 0,
            ]];
        }

        $intervalM = max($intervalKm, 0.1) * 1000.0;
        $targets = [];
        for ($d = 0; $d < $totalM; $d += $intervalM) {
            $targets[] = $d;
        }
        $last = end($targets);
        if ($last === false || abs($last - $totalM) > 1.0) {
            $targets[] = $totalM;
        }

        $sampled = [];
        foreach ($targets as $targetM) {
            $sampled[] = $this->interpolateAlongPolyline($points, $cumulative, $targetM);
        }

        $sampled = $this->dedupeAdjacent($sampled);

        if (count($sampled) < $minPoints) {
            $sampled = $this->resampleToMinPoints($points, $cumulative, $totalM, $minPoints);
        }

        if (count($sampled) > $maxPoints) {
            $sampled = $this->downsampleEvenly($sampled, $maxPoints);
        }

        foreach ($sampled as $i => &$row) {
            $row['index'] = $i;
        }
        unset($row);

        return $sampled;
    }

    public function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earth = 6371000.0;
        $φ1 = deg2rad($lat1);
        $φ2 = deg2rad($lat2);
        $Δφ = deg2rad($lat2 - $lat1);
        $Δλ = deg2rad($lon2 - $lon1);

        $a = sin($Δφ / 2) ** 2 + cos($φ1) * cos($φ2) * sin($Δλ / 2) ** 2;

        return 2 * $earth * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * @param  list<array{lat: float, lng: float}>  $points
     * @param  list<float>  $cumulative
     * @return array{lat: float, lng: float, distance_from_start_m: float}
     */
    private function interpolateAlongPolyline(array $points, array $cumulative, float $targetM): array
    {
        $n = count($points);
        if ($n === 1) {
            return [
                'lat' => $points[0]['lat'],
                'lng' => $points[0]['lng'],
                'distance_from_start_m' => 0.0,
            ];
        }

        for ($i = 1; $i < $n; $i++) {
            $d0 = $cumulative[$i - 1];
            $d1 = $cumulative[$i];
            if ($targetM <= $d1 || $i === $n - 1) {
                $segLen = max($d1 - $d0, 1e-6);
                $t = $segLen > 0 ? ($targetM - $d0) / $segLen : 0.0;
                $t = max(0.0, min(1.0, $t));

                return [
                    'lat' => $points[$i - 1]['lat'] + $t * ($points[$i]['lat'] - $points[$i - 1]['lat']),
                    'lng' => $points[$i - 1]['lng'] + $t * ($points[$i]['lng'] - $points[$i - 1]['lng']),
                    'distance_from_start_m' => min(max($targetM, 0.0), $cumulative[$n - 1]),
                ];
            }
        }

        return [
            'lat' => $points[$n - 1]['lat'],
            'lng' => $points[$n - 1]['lng'],
            'distance_from_start_m' => $cumulative[$n - 1],
        ];
    }

    /**
     * @param  list<array{lat: float, lng: float, distance_from_start_m: float}>  $rows
     * @return list<array{lat: float, lng: float, distance_from_start_m: float}>
     */
    private function dedupeAdjacent(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $last = end($out);
            if ($last !== false && abs($row['distance_from_start_m'] - $last['distance_from_start_m']) < 5.0) {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param  list<array{lat: float, lng: float}>  $points
     * @param  list<float>  $cumulative
     * @return list<array{lat: float, lng: float, distance_from_start_m: float}>
     */
    private function resampleToMinPoints(array $points, array $cumulative, float $totalM, int $minPoints): array
    {
        $out = [];
        $steps = max($minPoints - 1, 1);
        for ($k = 0; $k < $minPoints; $k++) {
            $target = $totalM * ($k / $steps);
            $out[] = $this->interpolateAlongPolyline($points, $cumulative, $target);
        }

        return $this->dedupeAdjacent($out);
    }

    /**
     * @param  list<array{lat: float, lng: float, distance_from_start_m: float}>  $rows
     * @return list<array{lat: float, lng: float, distance_from_start_m: float}>
     */
    private function downsampleEvenly(array $rows, int $maxPoints): array
    {
        $count = count($rows);
        if ($count <= $maxPoints) {
            return $rows;
        }

        $out = [];
        $lastIdx = $count - 1;
        for ($i = 0; $i < $maxPoints; $i++) {
            $idx = (int) round($i * $lastIdx / ($maxPoints - 1));
            $idx = max(0, min($lastIdx, $idx));
            $out[] = $rows[$idx];
        }

        return $this->dedupeAdjacent($out);
    }
}
