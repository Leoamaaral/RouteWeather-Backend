<?php

return [
    'google_maps_api_key' => env('GOOGLE_MAPS_API_KEY'),
    'tomorrow_api_key' => env('TOMORROW_IO_API_KEY'),
    'tomorrow_base_url' => env('TOMORROW_IO_BASE_URL', 'https://api.tomorrow.io/v4'),

    /** Distância entre pontos de amostragem ao longo da polyline (km). */
    'sample_interval_km' => (float) env('ROUTE_SAMPLE_INTERVAL_KM', 25),

    /** Mínimo e máximo de pontos para limitar custo de API. */
    'sample_min_points' => (int) env('ROUTE_SAMPLE_MIN_POINTS', 3),
    'sample_max_points' => (int) env('ROUTE_SAMPLE_MAX_POINTS', 24),

    /** Buckets de cache para previsão (segundos). */
    'weather_cache_ttl_seconds' => (int) env('WEATHER_CACHE_TTL', 900),

    /** Arredondamento geográfico para chave de cache (~100 m com 3 casas; ~1.1 km com 2). */
    'weather_cache_latlng_round' => (int) env('WEATHER_CACHE_LATLNG_ROUND', 3),
];
