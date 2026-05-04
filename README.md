# RouteWeather API

API REST em **Laravel 11** que calcula uma rota (Google Directions), amostra pontos ao longo do percurso, busca previsão do tempo (Tomorrow.io) em cada trecho estimado e devolve uma linha do tempo com análise de risco da viagem.
**Para usar com frontend -> (repo:https://github.com/Leoamaaral/web)**

## Requisitos

- PHP **8.2+** com extensões usuais do Laravel (`openssl`, `pdo`, `mbstring`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`, etc.)
- [Composer](https://getcomposer.org/)
- Chaves de API:
  - **Google Maps** (Directions e Geocoding reverso para cidade nos pontos)
  - **Tomorrow.io** (previsão)

## Instalação

Na pasta `api/`:

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Configure as variáveis obrigatórias no `.env` (veja a tabela abaixo). Em seguida:

```bash
php artisan serve
```

A aplicação fica em `http://127.0.0.1:8000` por padrão (ajuste `APP_URL` se necessário).

## Variáveis de ambiente

| Variável | Descrição |
|----------|-----------|
| `APP_KEY` | Gerada com `php artisan key:generate` |
| `APP_URL` | URL base da API (ex.: `http://localhost:8000`) |
| `GOOGLE_MAPS_API_KEY` | Chave com APIs de Directions e Geocoding habilitadas |
| `TOMORROW_IO_API_KEY` | Chave Tomorrow.io |
| `TOMORROW_IO_BASE_URL` | Opcional; padrão `https://api.tomorrow.io/v4` |
| `CORS_ALLOWED_ORIGINS` | Origens permitidas, separadas por vírgula (ex.: `http://localhost:3000`) |
| `ROUTE_SAMPLE_INTERVAL_KM` | Intervalo entre pontos de amostragem na rota (km); padrão `25` |
| `ROUTE_SAMPLE_MIN_POINTS` / `ROUTE_SAMPLE_MAX_POINTS` | Limites de pontos na amostragem |
| `WEATHER_CACHE_TTL` | TTL do cache de previsão (segundos) |
| `WEATHER_CACHE_LATLNG_ROUND` | Casas decimais para chave de cache lat/lng |
| `CACHE_STORE` | `file` ou `redis` (com Redis configurado) |
| `QUEUE_CONNECTION` | Padrão `sync` |

Detalhes adicionais estão em `.env.example` e em `config/route_weather.php`.

## Rotas

| Método | URI | Descrição |
|--------|-----|-----------|
| `GET` | `/` | JSON de status do serviço |
| `GET` | `/up` | Health check (Laravel) |
| `POST` | `/api/v1/route-weather/plan` | Plano de rota + clima + risco |

## `POST /api/v1/route-weather/plan`

Calcula a rota entre origem e destino, amostra pontos ao longo da polyline, projeta horário estimado em cada ponto, consulta o tempo e retorna metadados da rota, timeline e bloco `risk` (score, alertas, resumo).

### Corpo (JSON)

| Campo | Tipo | Obrigatório | Descrição |
|-------|------|-------------|-----------|
| `origin` | string | sim | Endereço ou lugar de partida (até 512 caracteres) |
| `destination` | string | sim | Endereço ou lugar de chegada |
| `departure_at` | datetime ISO | não | Momento da partida; se omitido, usa agora |
| `sample_interval_km` | number | não | Entre `1` e `250` km; se omitido, usa `ROUTE_SAMPLE_INTERVAL_KM` |
| `use_traffic` | boolean | não | Se `true` (padrão), a rota considera trânsito no horário de partida |

### Exemplo com `curl`

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

### Forma geral da resposta

- `meta`: `generated_at`, `departure_at`, `sample_interval_km`
- `route`: `summary`, `polyline` (encoded), `total_distance_m`, `total_duration_s`
- `timeline`: lista ordenada com `order`, `estimated_at`, `eta_offset_seconds`, `distance_from_start_km`, `location` (`lat`, `lng`, `city`), `weather` (temperatura, probabilidade de chuva, condição, códigos Tomorrow, intensidade de precipitação)
- `risk`: `score` (inteiro), `alerts`, `summary` (texto)

Erros de validação retornam **422** com detalhes no formato padrão do Laravel.

## CORS

Origens são lidas de `CORS_ALLOWED_ORIGINS` (lista separada por vírgula). Ajuste para o domínio do frontend (por exemplo Next.js em `http://localhost:3000`).

## Testes

```bash
composer test
# ou
./vendor/bin/phpunit
```

## Estrutura relevante

- `routes/api.php` — rotas versionadas `v1`
- `app/Http/Controllers/Api/RouteWeatherController.php` — validação HTTP e resposta JSON
- `app/Services/` — integrações Google, Tomorrow.io, geometria da rota e análise de risco

## Licença

Projeto RouteWeather é OpenSource!
