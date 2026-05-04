# RouteWeather API

REST API built with **Laravel 11**. It computes a route (Google Directions), samples points along the path, fetches weather forecasts (Tomorrow.io) at each estimated segment, and returns a timeline with a trip risk analysis.

**Repository:** [github.com/Leoamaaral/api](https://github.com/Leoamaaral/api)  
**Frontend companion:** [github.com/Leoamaaral/web](https://github.com/Leoamaaral/web)

## Requirements

- PHP **8.2+** with typical Laravel extensions (`openssl`, `pdo`, `mbstring`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`, etc.)
- [Composer](https://getcomposer.org/)
- API keys:
  - **Google Maps** (Directions and reverse geocoding for city labels on sample points)
  - **Tomorrow.io** (forecast)

## Setup

From the `api/` directory:

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Set the required variables in `.env` (see the table below), then:

```bash
php artisan serve
```

The app listens on `http://127.0.0.1:8000` by default (adjust `APP_URL` if needed).

## Environment variables

| Variable | Description |
|----------|-------------|
| `APP_KEY` | Generated with `php artisan key:generate` |
| `APP_URL` | Base URL of the API (e.g. `http://localhost:8000`) |
| `GOOGLE_MAPS_API_KEY` | Key with Directions and Geocoding APIs enabled |
| `TOMORROW_IO_API_KEY` | Tomorrow.io API key |
| `TOMORROW_IO_BASE_URL` | Optional; default `https://api.tomorrow.io/v4` |
| `CORS_ALLOWED_ORIGINS` | Allowed origins, comma-separated (e.g. `http://localhost:3000`) |
| `ROUTE_SAMPLE_INTERVAL_KM` | Spacing between route sample points (km); default `25` |
| `ROUTE_SAMPLE_MIN_POINTS` / `ROUTE_SAMPLE_MAX_POINTS` | Min/max number of sample points |
| `WEATHER_CACHE_TTL` | Forecast cache TTL (seconds) |
| `WEATHER_CACHE_LATLNG_ROUND` | Decimal places for lat/lng cache keys |
| `CACHE_STORE` | `file` or `redis` (with Redis configured) |
| `QUEUE_CONNECTION` | Default `sync` |

More detail is in `.env.example` and `config/route_weather.php`.

## Routes

| Method | URI | Description |
|--------|-----|-------------|
| `GET` | `/` | Service status JSON |
| `GET` | `/up` | Laravel health check |
| `POST` | `/api/v1/route-weather/plan` | Route plan + weather + risk |

## `POST /api/v1/route-weather/plan`

Computes the route between origin and destination, samples points along the encoded polyline, projects an estimated time at each point, queries weather, and returns route metadata, a `timeline`, and a `risk` block (score, alerts, summary).

### Request body (JSON)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `origin` | string | yes | Start address or place (max 512 characters) |
| `destination` | string | yes | End address or place |
| `departure_at` | ISO datetime | no | Departure time; if omitted, uses “now” |
| `sample_interval_km` | number | no | Between `1` and `250` km; if omitted, uses `ROUTE_SAMPLE_INTERVAL_KM` |
| `use_traffic` | boolean | no | If `true` (default), routing uses traffic for the departure time |

### Example with `curl`

```bash
curl -sS -X POST "http://127.0.0.1:8000/api/v1/route-weather/plan" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "origin": "Av. Paulista, São Paulo",
    "destination": "Campinas, SP",
    "departure_at": "2026-05-04T08:00:00-03:00",
    "sample_interval_km": 25,
    "use_traffic": true
  }'
```

### Response shape

- `meta`: `generated_at`, `departure_at`, `sample_interval_km`
- `route`: `summary`, `polyline` (encoded), `total_distance_m`, `total_duration_s`
- `timeline`: ordered list with `order`, `estimated_at`, `eta_offset_seconds`, `distance_from_start_km`, `location` (`lat`, `lng`, `city`), `weather` (temperature, rain probability, condition, Tomorrow codes, precipitation intensity)
- `risk`: `score` (integer), `alerts`, `summary` (text)

Validation errors return **422** with Laravel’s default error payload.

## CORS

Origins are read from `CORS_ALLOWED_ORIGINS` (comma-separated list). Point it at your frontend origin (e.g. Next.js on `http://localhost:3000`).

## Tests

```bash
composer test
# or
./vendor/bin/phpunit
```

## Project layout

- `routes/api.php` — versioned `v1` routes
- `app/Http/Controllers/Api/RouteWeatherController.php` — HTTP validation and JSON response
- `app/Services/` — Google and Tomorrow.io integrations, route geometry, risk analysis

## License

RouteWeather is open source;
