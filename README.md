# RouteWeather API

REST API built with **Laravel 11**. It computes a route (Google Directions), samples points along the path, fetches weather forecasts (Tomorrow.io) at each estimated segment, and returns a timeline with a trip risk analysis.

**Repository:** [github.com/Leoamaaral/RouteWeather-Backend](https://github.com/Leoamaaral/RouteWeather-Backend)  
**Frontend companion:** [github.com/Leoamaaral/RouteWeather-Front](https://github.com/Leoamaaral/RouteWeather-Front)

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

## Environment variables - test

| Variable | Description |
|----------|-------------|
| `APP_KEY` | Generated with `php artisan key:generate` |
| `APP_URL` | Base URL of the API (e.g. `http://localhost:8000`) |
| `GOOGLE_MAPS_API_KEY` | Key with Directions and Geocoding APIs enabled |
| `TOMORROW_IO_API_KEY` | Tomorrow.io API key |
| `TOMORROW_IO_BASE_URL` | Optional; default `https://api.tomorrow.io/v4` |
| `GEOCODE_LANGUAGE` | Language for reverse geocoding city labels; default `pt-BR` |
| `CORS_ALLOWED_ORIGINS` | Allowed origins, comma-separated (e.g. `http://localhost:3000`) |
| `ROUTE_SAMPLE_INTERVAL_KM` | Spacing between route sample points (km); default `25` |
| `ROUTE_SAMPLE_MIN_POINTS` / `ROUTE_SAMPLE_MAX_POINTS` | Min/max number of sample points |
| `WEATHER_CACHE_TTL` | Forecast cache TTL (seconds) |
| `WEATHER_CACHE_LATLNG_ROUND` | Decimal places for lat/lng cache keys |
| `PLAN_CACHE_TTL` | Full-plan cache TTL (seconds); `0` disables it |
| `ROUTE_COMPARE_MAX_WINDOWS` | Max departure windows accepted by `/plan/compare` |
| `CACHE_STORE` | `file` or `redis` (with Redis configured) |
| `QUEUE_CONNECTION` | `sync` (default) or `redis` for async plans |

More detail is in `.env.example` and `config/route_weather.php`.

## Routes

| Method | URI | Description |
|--------|-----|-------------|
| `GET` | `/` | Service status JSON |
| `GET` | `/up` | Laravel health check |
| `GET` | `/api/v1/health` | Service status with dependency configuration flags |
| `GET` | `/api/v1/docs` | OpenAPI 3 specification (JSON) |
| `POST` | `/api/v1/route-weather/plan` | Route plan + weather + risk |
| `POST` | `/api/v1/route-weather/plan/compare` | Compare departure windows and recommend the lowest-risk one |
| `POST` | `/api/v1/route-weather/plan/async` | Enqueue a plan; returns a `job_id` (202) |
| `GET` | `/api/v1/route-weather/plan/status/{jobId}` | Poll an enqueued plan result |

The `plan` and `status` endpoints are rate limited to 30 requests/minute; `compare` and `async` to 10 requests/minute. Errors return a structured body: `{ "error": { "code", "message", "details?" } }`.

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

- `meta`: `generated_at`, `departure_at`, `sample_interval_km`, `warnings` (e.g. points without forecast)
- `route`: `summary`, `polyline` (encoded), `total_distance_m`, `total_duration_s`
- `timeline`: ordered list with `order`, `estimated_at`, `eta_offset_seconds`, `distance_from_start_km`, `location` (`lat`, `lng`, `city`), `weather` (temperature, rain probability, condition, Tomorrow codes, precipitation intensity, `wind_speed_kmh`, `visibility_km`, `cloud_cover`). ETA per point is derived from the Google route's per-step duration profile, not a linear distance ratio.
- `risk`: `score` (integer), `alerts` (rain, storm, fog, strong wind, ice risk), `summary` (text)

Validation errors return **422** with Laravel’s default error payload.

## CORS

Origins are read from `CORS_ALLOWED_ORIGINS` (comma-separated list). Point it at your frontend origin (e.g. Next.js on `http://localhost:3000`).

## Deploy na Vercel (produção)

A Vercel **não** roda `php artisan serve` nem inclui PHP no build padrão. Este projeto usa o runtime community [`vercel-php`](https://github.com/vercel-community/php) via `vercel.json` e `api/index.php`.

### 1. Ajustar o projeto na Vercel (dashboard)

No projeto **route-weather-backend**:

| Configuração | Valor |
|--------------|-------|
| **Framework Preset** | Other |
| **Build Command** | *(vazio — apague `php artisan serve`)* |
| **Output Directory** | *(vazio — o `vercel.json` controla)* |
| **Install Command** | *(vazio ou `composer install --no-dev --optimize-autoloader`)* |
| **Root Directory** | `.` (raiz do repositório Backend) |

Faça push dos arquivos `vercel.json`, `api/index.php` e `package.json` antes de redeployar.

### 2. Variáveis de ambiente na Vercel (API)

Defina em **Settings → Environment Variables** (Production):

| Variável | Exemplo / valor |
|----------|-----------------|
| `APP_KEY` | saída de `php artisan key:generate --show` (local) |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://route-weather-backend.vercel.app` |
| `LOG_CHANNEL` | `stderr` |
| `CACHE_STORE` | `array` *(simples; sem cache entre requisições)* ou `redis` com Upstash |
| `SESSION_DRIVER` | `cookie` |
| `APP_CONFIG_CACHE` | `/tmp/config.php` |
| `APP_ROUTES_CACHE` | `/tmp/routes.php` |
| `APP_EVENTS_CACHE` | `/tmp/events.php` |
| `APP_PACKAGES_CACHE` | `/tmp/packages.php` |
| `APP_SERVICES_CACHE` | `/tmp/services.php` |
| `VIEW_COMPILED_PATH` | `/tmp` |
| `GOOGLE_MAPS_API_KEY` | sua chave server-side |
| `TOMORROW_IO_API_KEY` | sua chave Tomorrow.io |
| `CORS_ALLOWED_ORIGINS` | URL do front em prod, ex. `https://seu-app.vercel.app` |

> **Cache em serverless:** `CACHE_STORE=file` não funciona na Vercel (filesystem somente leitura). Para cache persistente entre requisições, use **Upstash Redis** no Marketplace da Vercel e configure `CACHE_STORE=redis` + variáveis `REDIS_*`.

### 3. Conectar o frontend (web) em produção

No projeto **Next.js** na Vercel, defina:

```
NEXT_PUBLIC_API_URL=https://route-weather-backend.vercel.app
```

*(sem barra no final)*

O browser chamará `https://route-weather-backend.vercel.app/api/v1/route-weather/plan`.

Confirme que a URL do front está em `CORS_ALLOWED_ORIGINS` da API. Múltiplas origens: separadas por vírgula.

### 4. Validar o deploy

```bash
curl -sS https://route-weather-backend.vercel.app/api/v1/health
```

Deve retornar JSON com status do serviço.

### Alternativa: Railway / Render / Fly.io

Se preferir um host com PHP nativo (sem limitações serverless), Laravel roda com `php-fpm` + Nginx ou `php artisan serve` atrás de um process manager. A Vercel é viável para esta API REST, mas hosts PHP tradicionais simplificam cache em disco e filas.

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
