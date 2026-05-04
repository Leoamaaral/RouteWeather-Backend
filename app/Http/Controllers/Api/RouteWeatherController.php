<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RouteWeatherService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RouteWeatherController extends Controller
{
    public function plan(Request $request, RouteWeatherService $routeWeatherService): JsonResponse
    {
        $data = $request->validate([
            'origin' => ['required', 'string', 'max:512'],
            'destination' => ['required', 'string', 'max:512'],
            'departure_at' => ['nullable', 'date'],
            'sample_interval_km' => ['nullable', 'numeric', 'between:1,250'],
            'use_traffic' => ['nullable', 'boolean'],
        ]);

        $departure = isset($data['departure_at'])
            ? Carbon::parse($data['departure_at'])
            : null;

        $payload = $routeWeatherService->plan(
            $data['origin'],
            $data['destination'],
            $departure,
            isset($data['sample_interval_km']) ? (float) $data['sample_interval_km'] : null,
            array_key_exists('use_traffic', $data) ? (bool) $data['use_traffic'] : null,
        );

        return response()->json($payload);
    }
}
