<?php

namespace App\Services;

/**
 * Decodifica polylines no formato Encoded Polyline Algorithm Format (Google).
 */
final class PolylineDecoder
{
    /**
     * @return list<array{lat: float, lng: float}>
     */
    public function decode(string $encoded): array
    {
        $encoded = trim($encoded);
        if ($encoded === '') {
            return [];
        }

        $length = strlen($encoded);
        $index = 0;
        $points = [];
        $lat = 0;
        $lng = 0;

        while ($index < $length) {
            $result = 0;
            $shift = 0;
            do {
                $b = ord($encoded[$index]) - 63;
                $index++;
                $result |= ($b & 0x1f) << $shift;
                $shift += 5;
            } while ($b >= 0x20);
            $dlat = ($result & 1) !== 0 ? ~($result >> 1) : ($result >> 1);
            $lat += $dlat;

            $result = 0;
            $shift = 0;
            do {
                $b = ord($encoded[$index]) - 63;
                $index++;
                $result |= ($b & 0x1f) << $shift;
                $shift += 5;
            } while ($b >= 0x20);
            $dlng = ($result & 1) !== 0 ? ~($result >> 1) : ($result >> 1);
            $lng += $dlng;

            $points[] = [
                'lat' => $lat / 1e5,
                'lng' => $lng / 1e5,
            ];
        }

        return $points;
    }
}
