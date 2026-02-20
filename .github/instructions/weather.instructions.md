---
applyTo: "lib/weather/**/*.php,api/weather.php"
---

# Weather Subsystem – Safety-Critical

## Data Flow

1. `api/weather.php` → `fetchWeatherUnified()` in `lib/weather/UnifiedFetcher.php`
2. Parallel fetch via `curl_multi` → adapters in `lib/weather/adapter/` parse to `WeatherSnapshot`
3. `WeatherAggregator` selects freshest valid data per field
4. Staleness check: null fields exceeding `MAX_STALE_HOURS`
5. Cache and serve

## Adapter Pattern

- Each source has an adapter: `tempest-v1.php`, `metar-v1.php`, `ambient-v1.php`, etc.
- Use `WeatherReading` factory methods: `WeatherReading::celsius()`, `::knots()`, `::inHg()`, `::feet()`
- Never hardcode conversion factors – use `lib/units.php`
- Wind direction: internal values use **true north**; conversion in `lib/heading-conversion.php`

## Staleness

- After `MAX_STALE_HOURS` (3 hours), null out fields – never serve stale data to pilots
- METAR has separate threshold: `WEATHER_STALENESS_WARNING_HOURS_METAR`
- Use `nullStaleFieldsBySource()` for consistency

## Testing

- Mocks in `lib/test-mocks.php` and `tests/mock-weather-responses.php`
- `phpunit.xml` sets `APP_ENV=testing`; adapters receive mock responses
- Add tests for new adapters and aggregation logic
