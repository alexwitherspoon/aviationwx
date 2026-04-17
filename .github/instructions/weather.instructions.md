---
applyTo: "lib/weather/**/*.php,api/weather.php"
---

# Weather Subsystem -- Safety-Critical

## Data Flow

1. `api/weather.php` → `fetchWeatherUnified()` in `lib/weather/UnifiedFetcher.php`
2. Parallel fetch via `curl_multi` → adapters in `lib/weather/adapter/` parse to `WeatherSnapshot`
3. **Tempest only:** After each successful HTTP response for `observations/station`, `tempestApplyDeviceFallbackIfNeeded()` may call `GET /stations/{station_id}` and `GET /observations/device/{first_ST}` when federated `obs` is empty, then parse device `obs_st` rows into the same internal shape as station data (`lib/weather/adapter/tempest-v1.php`). Do not log API tokens.
4. `WeatherAggregator` selects freshest valid data per field
5. Staleness check: null fields exceeding `MAX_STALE_HOURS`
6. Cache and serve

## Adapter Pattern

- Each source has an adapter: `tempest-v1.php`, `metar-v1.php`, `ambient-v1.php`, etc.
- Use `WeatherReading` factory methods: `WeatherReading::celsius()`, `::knots()`, `::inHg()`, `::feet()`
- Never hardcode conversion factors; use `lib/units.php`
- Wind direction: internal values use **true north**; conversion in `lib/heading-conversion.php`. Display: use `wind_direction_magnetic`; fail closed (`---`) when missing.

## Staleness

- After `MAX_STALE_HOURS` (3 hours), null out fields; never serve stale data to pilots
- METAR has separate threshold: `WEATHER_STALENESS_WARNING_HOURS_METAR`
- Use `nullStaleFieldsBySource()` for consistency

## Testing

- Mocks in `lib/test-mocks.php` and `tests/mock-weather-responses.php`. For `swd.weatherflow.com`, mocks are **URL-specific**: `/observations/device/` uses `getMockTempestDeviceObsStResponse()`, `/rest/stations/` uses `getMockTempestStationsMetadataResponse()`, and the default station observation URL uses `getMockTempestResponse()`.
- `phpunit.xml` sets `APP_ENV=testing`; adapters receive mock responses via `getMockHttpResponse()` before real curl.
- **Tempest regression suite:** `tests/Unit/TempestAdapterTest.php` (behavior-driven cases for federated vs `obs_st`, ST extraction, fallback, URL builders, malformed `obs[0]` rejection). Extend this file when changing Tempest parsing or fallback order.
- Add tests for new adapters and aggregation logic beyond Tempest as needed.
