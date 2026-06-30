# Weather, Webcam, and NOTAM Data Flow Documentation

This document describes how weather, webcam, and NOTAM data is fetched, processed, calculated, transformed, and displayed in the AviationWX dashboard.

**Document role:** Checked-in reference for **required pipeline logic** - what the system must do, in present tense. It is not a project plan, rollout tracker, or implementation status log. Delivery gaps belong in GitHub issues and pull requests (see `CODE_STYLE.md`). Use this document to understand system intent, then verify in code that behavior matches. Logic stated here can be audited against the implementation to find safety or functional gaps.

## Table of Contents

1. [Weather Data Fetching](#weather-data-fetching)
2. [Weather Data Processing](#weather-data-processing)
3. [Weather Data Calculations](#weather-data-calculations)
4. [Weather Data Transformations](#weather-data-transformations)
5. [Weather Data Caching and Staleness](#weather-data-caching-and-staleness)
6. [Webcam Data Fetching](#webcam-data-fetching)
7. [Webcam Data Processing](#webcam-data-processing)
8. [NOTAM Data Fetching](#notam-data-fetching)
9. [NOTAM Data Processing](#notam-data-processing)
10. [Data Display on Dashboard](#data-display-on-dashboard)
11. [Airport country resolution (geometry aggregate)](#airport-country-resolution-geometry-aggregate)
12. [Airport "Last updated": observation time vs fetch time](#airport-last-updated-observation-vs-fetch-time)

---

## Weather Data Fetching

### Overview

Weather data is fetched from multiple sources and combined to provide a complete picture:
- **Primary Source**: Tempest, Ambient Weather, WeatherLink, PWSWeather.com, SynopticData.com, or METAR-only
- **METAR Supplement**: Aviation weather data (visibility, ceiling, cloud cover) from aviationweather.gov

### Fetching Strategy

The system uses **parallel fetching** for all configured sources:

- **Unified Fetcher**: Fetches all sources simultaneously using `curl_multi`
- **Sources Fetched**: All sources configured in the `weather_sources` array
- **Circuit Breaker**: Each source has per-airport circuit breaker protection; HTTP 429/503 also open a shared `global_weather_{provider}_{credential fingerprint}` key so airports sharing one API key back off together. When present, `Retry-After` / `X-RateLimit-Reset` from the failed response extend backoff (up to 15 minutes).
- **NWS points cache**: `api.weather.gov/points/{lat},{lon}` responses are cached under `cache/nws-points/` (12-hour TTL) via `lib/nws-points-cache.php`. The scheduler starts `scripts/refresh-nws-points.php` on `NWS_POINTS_REFRESH_INTERVAL_SECONDS` to warm stale entries for airports with an NWS `weather_sources` entry and coordinates (`lib/nws-points-refresh.php`). Station observations still use the direct `/stations/{id}/observations/latest` path.
- **Upstream throttle**: Before `curl_multi_add_handle`, `lib/upstream-rate-limit.php` enforces a per-credential token bucket (flock under `cache/upstream-limits/`). When the budget is exhausted, that source is skipped for the current fetch cycle only. METAR uses `metarResolveStationResponse()` (mock, bulk slice, then HTTP); bulk is tried before the per-airport METAR HTTP circuit gate. Throttle and circuit-open outcomes are not recorded as fetch failures. Throttle skips, upstream HTTP 429 responses (per-station METAR HTTP fallback and AWC national bulk gzip download), fail-open I/O, and METAR bulk download failures are counted in `cache/weather_health.json` via `lib/weather-health.php`. The status page **Weather Data Fetching** row shows aggregate health; expand it for per-provider HTTP 429 counts in the last hour. Throttle skip logs include `fingerprint_prefix` only (no raw API keys). METAR bulk refresh writes `cache/metar-bulk/meta.json` and logs `metar_bulk_age_seconds` on successful ingest.
- **Parallel Execution**: All sources are fetched concurrently for maximum speed
- **Freshness Selection**: Handled during aggregation (freshest data wins for each field)

**Source Configuration**:
All weather sources are configured in a unified `weather_sources` array. Each source includes a `type` and source-specific configuration (station_id, api_key, etc.). Sources marked with `backup: true` are only used when primary sources fail.

### Backup Weather Source

**Purpose**: Provides redundancy when primary weather source becomes stale or fails.

**Behavior**:
- Backup source is treated as another source in the unified aggregation system
- Fetched in parallel with all other sources on every weather fetch
- No special activation thresholds - always fetched if configured
- Circuit breaker protection applies (backup can be skipped if in backoff)

**Activation Detection**:
- Backup is considered "active" when it's providing data for any fields
- Determined by `backup_status` field in cache (set during aggregation)
- Backup is active when: backup has fresh data AND primary is stale (exceeds warning threshold)
- Used for display purposes (showing which sources are providing data)

**Field-Level Aggregation**:
- Each weather field uses the freshest available data from all sources
- Aggregator selects the value with the most recent observation time
- Backup data is used automatically when primary sources fail or are stale
- No manual switching logic - aggregation handles it automatically

### Primary Weather Sources

#### Tempest WeatherFlow API
- **Primary endpoint (federated station snapshot)**: `https://swd.weatherflow.com/swd/rest/observations/station/{station_id}?token={api_key}`
  - WeatherFlow builds this from devices on the station; it can be empty while a physical sensor still has data (federation gap).
- **Automatic device fallback** (when the primary response does not parse, or parses to a timestamp-only / sensor-empty federated row with no temperature, humidity, pressure, dew point, wind, or non-zero precip):
  1. `GET https://swd.weatherflow.com/swd/rest/stations/{station_id}?token={api_key}` - response lists devices (typically hub `HB` plus one Tempest unit `ST`).
  2. The integration selects the **first device with `device_type` `ST`** (the Tempest unit that publishes `obs_st` rows).
  3. `GET https://swd.weatherflow.com/swd/rest/observations/device/{device_id}?token={api_key}` - latest observation uses the **`obs_st` numeric row layout** from WeatherFlow's API docs.
  4. The adapter maps that row into the same internal shape as federated `obs[0]`, then applies the same unit conversions. **Dew point** is not present on raw `obs_st` rows; it may be derived later from temperature and humidity when both exist (same as any missing dew point path).
  5. **Pressure**: device index 6 is station pressure in mb and is run through the same mb → inHg path as federated `sea_level_pressure`; when only the device path is used, treat displayed pressure as sensor-reported pressure, not a guaranteed sea-level reduction.
  6. **Logging**: when the device path is used, an internal structured log records `airport_id`, `station_id`, and `device_id` (no secrets).
  7. **Device body guard**: if `/observations/device` returns HTTP success but parses to another sensor-less row, the integration keeps the original station response (no success log, same as a failed follow-up).
- **Malformed guard**: if `obs[0]` is a JSON list (numeric array) instead of a federated object, parsing returns null so numeric indices are never mis-read as field names.
- **Data Provided** (after successful parse, federated or device fallback):
  - Temperature (Celsius)
  - Humidity (%)
  - Pressure (mb, converted to inHg)
  - Wind speed (m/s, converted to knots)
  - Wind direction (degrees)
  - Gust speed (m/s, converted to knots)
  - Dewpoint (Celsius) when present on federated obs; otherwise may be computed from T/RH downstream
  - Precipitation accumulation (mm, converted to inches)
  - Observation timestamp (Unix seconds)
- **Unit Conversions** (see [Server-Side Conversions](#server-side-conversions-api-adapters) for formulas):
  - Wind: m/s → knots (`kts = m/s × 1.943844`)
  - Pressure: mb → inHg (`inHg = mb / 33.8639`)
  - Precipitation: mm → inches (`inches = mm × 0.0393701`)

#### Ambient Weather API
- **Endpoint**: 
  - All devices: `https://api.ambientweather.net/v1/devices?applicationKey={app_key}&apiKey={api_key}`
  - Specific device: `https://api.ambientweather.net/v1/devices/{mac_address}?applicationKey={app_key}&apiKey={api_key}`
- **Device Selection**: If `mac_address` is provided in config, uses specific device endpoint. Otherwise, uses device list endpoint and selects first device.
- **Data Provided**:
  - Temperature (Fahrenheit, converted to Celsius)
  - Humidity (%)
  - Pressure (inHg)
  - Wind speed (mph, converted to knots)
  - Wind direction (degrees)
  - Gust speed (mph, converted to knots)
  - Precipitation (inches)
  - Observation timestamp (milliseconds, converted to seconds)
- **Unit Conversions** (see [Server-Side Conversions](#server-side-conversions-api-adapters) for formulas):
  - Temperature: °F → °C (`°C = (°F - 32) / 1.8`)
  - Wind: mph → knots (`kts = mph × 0.868976`)
  - Timestamp: milliseconds → seconds (`seconds = ms / 1000`)

#### WeatherLink API
- **Endpoint**: Varies by API version
- **Authentication**: Custom headers (requires synchronous fetching)
- **Data Provided**: Similar to other sources, with API-specific format
- **Note**: Requires special header authentication, so always uses synchronous fetching

#### PWSWeather.com (via AerisWeather API)
- **Endpoint**: `https://api.aerisapi.com/observations/{station_id}?client_id={client_id}&client_secret={client_secret}`
- **Data Provided**:
  - Temperature (Celsius)
  - Humidity (%)
  - Pressure (inHg)
  - Wind speed (knots)
  - Wind direction (degrees)
  - Gust speed (knots)
  - Dewpoint (Celsius)
  - Precipitation accumulation (inches)
  - Visibility (statute miles)
  - Observation timestamp (Unix seconds)
- **Unit Conversions**:
  - None required (data already in standard units)
- **Note**: PWSWeather.com stations upload data to pwsweather.com, and station owners receive AerisWeather API access to retrieve observations. Supports async fetching.

#### SynopticData.com Weather API
- **Endpoint**: `https://api.synopticdata.com/v2/stations/latest?stid={station_id}&token={api_token}&vars={variables}`
- **Data Provided**:
  - Temperature (Celsius)
  - Humidity (%)
  - Pressure (mb/hPa, converted to inHg) or Altimeter (inHg, used directly)
  - Wind speed (m/s, converted to knots)
  - Wind direction (degrees)
  - Gust speed (m/s, converted to knots)
  - Dewpoint (Celsius)
  - Precipitation accumulation (mm, converted to inches)
  - Visibility (meters or miles, converted to statute miles if needed)
  - Observation timestamp (ISO 8601, parsed to Unix seconds)
- **Unit Conversions** (see [Server-Side Conversions](#server-side-conversions-api-adapters) for formulas):
  - Wind: m/s → knots (`kts = m/s × 1.943844`)
  - Pressure: mb/hPa → inHg (`inHg = mb / 33.8639`)
  - Precipitation: mm → inches (`inches = mm × 0.0393701`)
  - Altimeter: Already in inHg (no conversion needed)
  - Visibility: meters → statute miles (`SM = m / 1609.344`) if value > 10
- **Note**: SynopticData provides access to over 170,000 weather stations worldwide. Typically used selectively on airports where other primary sources (Tempest, Ambient, WeatherLink, PWSWeather) aren't available. Supports async fetching. Pressure may be provided as altimeter (inHg, no conversion) or sea_level_pressure/pressure (mb/hPa, requires conversion).

#### METAR-Only Source
- **Endpoint**: `https://aviationweather.gov/api/data/metar?ids={station}&format=json&taf=false&hours=0`
- **Bulk cache (multi-airport only):** When **more than one airport** is enabled in config, the scheduler starts `scripts/refresh-metar-bulk.php` in the background on `METAR_BULK_REFRESH_INTERVAL_SECONDS` (see `lib/constants.php`); download, CSV ingest, and slice writes run inside that worker so the scheduler loop stays non-blocking. It downloads AWC `metars.cache.csv.gz`, maps each configured METAR `station_id` (plus `nearby_stations`) to a one-station JSON envelope under `cache/metar-bulk/stations/{ICAO}.json`, and `fetchMETARFromStation` prefers that file when its mtime is within `METAR_BULK_STATION_FILE_MAX_AGE_SECONDS`. Ingest **rejects the gzip** unless the CSV header row matches the canonical 44-column AWC layout in `lib/metar-bulk-csv-schema.php` (and `tests/Fixtures/metar-bulk-csv-header-line.txt`); a mismatch is logged with a short column diff summary and workers fall back to per-station HTTP. Rows shorter than 44 columns are skipped (`skipped_short_rows` in refresh logs). With **only one enabled airport**, bulk download and slice reads are skipped; per-station METAR HTTP is used. Stale or missing slices always fall back to the JSON endpoint above so METAR still serves when bulk ingest fails or a slice ages out.
- **Data Provided**:
  - Temperature (Celsius)
  - Dewpoint (Celsius)
  - Wind direction and speed (knots)
  - Pressure/Altimeter (inHg)
  - Visibility (statute miles)
  - Ceiling (feet AGL)
  - Cloud cover (FEW/SCT/BKN/OVC)
  - Precipitation (inches)
  - Observation timestamp (ISO 8601, parsed to Unix seconds)

### Supplemental remote weather policy

**Guiding principle:** Pilot safety is the prime directive for weather sourcing. It takes precedence over filling coverage gaps. When safety and completeness conflict, fail closed: show less data, show an outage banner, or do not configure a remote source rather than display weather that could mislead pilots about conditions at the field.

When an airport has on-field sensors but needs additional weather fields, a **remote** source may be configured to supplement on-site data. **METAR** is the most common case (ceiling, visibility, cloud cover), but the same policy applies to **any remote weather source** (for example NWS, AWOSnet, or federation data from another airport) when that source observes conditions away from the field.

Remote supplemental data is not a substitute for on-field infrastructure.

**Terminology:**

- **On-field** and **on-site** mean equipment or observations at the airport (Tempest, webcams, co-located ASOS/METAR, on-field AWOSnet).
- **Co-located source** observes the airport itself (for METAR: active station ICAO matches `airport.icao` when set; for other source types: configured to report conditions at this field).
- **Supplemental (remote) source** observes a different location (for example KUAO METAR when displaying 7S9, or NWS data from a station that is not co-located with the airport).

**Operator rule (configuration):** A remote source is added only when conditions at that location are **equivalent to the airport within VFR standards**, based on local geography, terrain, and climate. Examples of acceptable pairings: similar elevation, no intervening terrain or weather regimes that routinely diverge on ceiling height or visibility. If weather is not equivalent under VFR standards, do **not** configure a remote source for that airport.

**Preference:** On-field sensor data always takes precedence over any remote source when both are available. Source attribution in the dashboard identifies which source supplied each field.

**Scope:** This policy applies to all weather source types. METAR is the most common supplemental source; the same rules apply to NWS, AWOSnet, and other remote sources that observe a location other than the field.

**Configuration shapes (METAR examples):**

| Pattern | Example | Meaning |
|---------|---------|---------|
| Co-located METAR | KSPB: `station_id: "KSPB"` with `icao: "KSPB"` | On-field ASOS/METAR; counts as **local** infrastructure for site health |
| Remote primary METAR | 7S9: Tempest + webcams + `station_id: "KUAO"` (no on-field METAR) | KUAO is the only METAR source; **supplemental** for aggregation and outage logic |
| `nearby_stations` fallback | KSPB: primary `KSPB`, `nearby_stations: ["KVUO", "KHIO"]` | Fetch fallback when the primary METAR HTTP/bulk request fails; does not define supplemental vs co-located by itself |

Whether a source is **co-located** or **supplemental** is determined at runtime from the **active reporting identity** (for METAR: station ICAO from aggregated weather data vs `airport.icao`), not by which config key (`station_id` vs `nearby_stations`) holds the code.

**Fields supplemental remote sources may contribute** (when on-field sensors lack them and on-field infrastructure is healthy):

- Visibility (flight category) - typically METAR
- Ceiling (flight category) - typically METAR
- Cloud cover - typically METAR
- Other fields only when the remote source is operator-approved as equivalent and on-field sensors do not provide them

**Fields that must come from on-field sources when available:** wind, temperature, dewpoint, humidity, pressure, precipitation (see [Data aggregation](#data-aggregation-unified-fetcher) and [SAFETY_CRITICAL_CALCULATIONS.md](SAFETY_CRITICAL_CALCULATIONS.md#local-vs-supplemental-remote-weather)).

**When all on-field infrastructure is down:** Show the standard local-outage banner and **hide supplemental remote weather fields** in the API and dashboard. Without on-site sensors or webcams to corroborate conditions, remote data could mislead pilots (for example a power outage at 7S9 while KUAO METAR continues reporting). Co-located sources at airports such as KSPB remain **local**: they may use different power or internet than Tempest or webcams, but they observe the field and count toward site health until they go stale.

See also [Data outage detection](#data-outage-detection) and [SAFETY_CRITICAL_CALCULATIONS.md](SAFETY_CRITICAL_CALCULATIONS.md#local-vs-supplemental-remote-weather).

### METAR station identifiers (standards)

Every METAR report identifies the **observing station** with a four-character **ICAO location indicator** in the identification group (WMO FM 15 / Annex 3; FAA AIM; NOAA AWC product documentation). The authoritative listing is **ICAO Doc 7910** (*Location Indicators*).

Important distinctions:

- **METAR station ICAO** is always present on the wire for a valid METAR product. AviationWX `station_id` values must be ICAO-format (see [CONFIGURATION.md](CONFIGURATION.md)).
- **Airport identifier** (ICAO, FAA LID, etc.) is not always the same code as the METAR station. Many strips have no on-field METAR. US private-field AWOS may use an identifier unrelated to the airport LID (FAA Order JO 7350.9).
- **Co-located** for AviationWX means the active METAR station ICAO matches `airport.icao` when that field is set. Airports without `icao` (for example FAA-only 7S9) treat any configured METAR as supplemental when on-site sensors or webcams are present. The same co-located vs supplemental distinction applies to other source types under [Supplemental remote weather policy](#supplemental-remote-weather-policy); METAR locality is the reference pattern for classifying remote sources.

### METAR fetch and supplementation (technical)

When a primary on-site source is configured, METAR data is fetched to supplement:

- **Visibility**: Required for flight category calculation
- **Ceiling**: Required for flight category calculation
- **Cloud cover**: Additional aviation context

METAR fetching logic (per station ID):
1. **Test mode**: `getMockHttpResponse` supplies canned JSON so PHPUnit stays deterministic.
2. **Bulk slice (multi-airport installs only):** When more than one airport is enabled, if `cache/metar-bulk/stations/{ICAO}.json` exists and is newer than `METAR_BULK_STATION_FILE_MAX_AGE_SECONDS`, read and parse it (same envelope as the JSON API). Single-enabled-airport configs skip this step.
3. **HTTP**: Otherwise GET the per-station JSON endpoint.

Station selection (primary and fallbacks):
1. Attempts primary `station_id` from the METAR source first
2. If primary fails and `nearby_stations` are configured, tries each nearby station sequentially
3. Stops on first successful fetch
4. If all stations fail, visibility/ceiling remain null

### Circuit Breaker Protection

Both primary and METAR sources have independent circuit breakers:

**Purpose**: Prevents repeated failed API calls when a source is down

**Behavior**:
- Tracks consecutive failures per source
- After threshold failures, enters "backoff" period
- During backoff, skips fetch attempts for that source
- Backoff duration increases with more failures
- Permanent errors (4xx HTTP codes) use 2x backoff multiplier
- Transient errors (timeouts, network issues) use normal backoff

**Result**: System gracefully degrades - if primary fails, METAR-only data may still be available

---

## Weather Data Processing

### Parsing Flow

1. **API Response Received**
   - Raw JSON from weather API
   - HTTP status code checked (must be 200)
   - Response body validated (non-empty)

2. **Source-Specific Parser**
   - Each adapter has its own parser function
   - Extracts relevant fields from API response
   - Performs unit conversions
   - Handles missing/null values gracefully

3. **Standard Format Conversion**
   - All sources converted to common data structure
   - Field names standardized
   - Units standardized (Celsius, knots, inHg, etc.)

4. **Unit Tracking via WeatherReading Factory Methods**
   - Each field is wrapped in a `WeatherReading` object with explicit unit
   - Factory methods ensure correct unit assignment: `celsius()`, `inHg()`, `knots()`, etc.
   - Enables runtime validation and safe unit conversions via `convertTo()` method
   - Example: `WeatherReading::celsius(15.5, 'tempest', $obsTime)` creates a temperature reading with unit 'C'

### Unit Tracking Architecture

**WeatherReading Object**:
Each weather measurement is stored as a `WeatherReading` object that carries:
- `value`: The numeric measurement
- `unit`: The unit of measurement (e.g., 'C', 'inHg', 'kt', 'SM', 'ft', '%')
- `observationTime`: When the measurement was taken
- `source`: Which API/adapter provided the data
- `isValid`: Whether the value passes validation

**Factory Methods** (`lib/weather/data/WeatherReading.php`):
- `celsius($value, $source, $time)` - Temperature in Celsius
- `inHg($value, $source, $time)` - Pressure in inches of mercury
- `hPa($value, $source, $time)` - Pressure in hectoPascals
- `knots($value, $source, $time)` - Wind speed in knots
- `statuteMiles($value, $source, $time)` - Visibility in statute miles
- `feet($value, $source, $time)` - Altitude/ceiling in feet
- `inches($value, $source, $time)` - Precipitation in inches
- `percent($value, $source, $time)` - Humidity percentage
- `degrees($value, $source, $time)` - Wind direction in degrees
- `text($value, $source, $time)` - Text values (cloud cover codes)

**Unit Conversion** (`WeatherReading::convertTo($targetUnit)`):
- Converts value to different unit, returns new WeatherReading
- Uses centralized conversion library (`lib/units.php`)
- Example: `$temp->convertTo('F')` converts Celsius to Fahrenheit

**Internal Standard Units**:
| Field | Internal Unit | Notes |
|-------|--------------|-------|
| Temperature/Dewpoint | Celsius (°C) | ICAO standard |
| Pressure | inHg | US aviation standard |
| Visibility | Statute miles (SM) | FAA standard |
| Precipitation | Inches (in) | US standard |
| Wind Speed | Knots (kt) | ICAO standard |
| Altitude/Ceiling | Feet (ft) | ICAO standard |
| Humidity | Percent (%) | Universal |

### Wind Direction Conventions by Source

All internal wind direction values are normalized to **true north** (degrees 0-360). See [Wind Direction: True North](SAFETY_CRITICAL_CALCULATIONS.md#wind-direction-true-north) for conversion functions and runway segment handling.

**Display**: Use `wind_direction_magnetic` from API/cache for all pilot-facing wind displays. When missing, show `---` (fail closed). No display-time conversion.

**Public API v1** (`GET /v1/airports/{id}/weather`): Wind direction is an explicit object (true_north, magnetic_north, variable) for safety-critical clarity:
```json
"wind_direction": {
  "true_north": 230,
  "magnetic_north": 216,
  "variable": false
}
```
When VRB: `true_north` and `magnetic_north` are null, `variable` is true. Use `magnetic_north` for display; when `variable` is true, show "VRB"; when both null, display `---` (fail closed).

| Source | Convention | Notes |
|--------|------------|-------|
| METAR | True north | Aviation standard |
| NWS API | True north | NOAA standard |
| AWOSnet | True north | Same as METAR |
| SWOB (Nav Canada) | True north | Meteorological data |
| Tempest | True north | Calibration to true north |
| Ambient | True north | Calibration to true north |
| WeatherLink | True north | Calibration to true north |
| Synoptic | True north | Meteorological practice |
| PWSWeather | Magnetic (assumed) | Converted to true at ingest |

### Observation Time Handling

**Critical for Safety**: The system tracks when weather was actually observed, not just when it was fetched.

**Timestamps Tracked**:
- `obs_time_primary`: When primary source weather was measured
- `obs_time_backup`: When backup source weather was measured
- `obs_time_metar`: When METAR observation was made
- `last_updated_primary`: When primary data was fetched from API
- `last_updated_backup`: When backup data was fetched from API
- `last_updated_metar`: When METAR data was fetched
- `last_updated`: Most recent observation time from all sources

**Priority**: Observation time is preferred over fetch time for display, as it represents actual weather conditions.

### Data Aggregation (Unified Fetcher)

The unified weather pipeline uses `WeatherAggregator` with `AggregationPolicy` to combine data from all configured sources:

1. **Source Configuration**:
   - All sources (primary, additional sources array, backup, METAR) are fetched in parallel
   - Sources are identified by type (tempest, ambient, weatherlink, pwsweather, synopticdata, nws, metar, backup)
   - Circuit breaker protection applies to each source independently

2. **Freshness-Based Selection** (Core Principle):
   - **Freshest data wins**: For each field, the source with the most recent observation time is selected
   - No source priority ordering - all sources compete equally based on data freshness
   - This ensures pilots always see the most current data available from any source
   - Each field is evaluated independently, allowing mixed sources in the final result

3. **Field Selection Rules**:
   - **Wind Group** (speed, direction, gust): Must come from single source as a complete unit; selects the source with the freshest complete wind data
   - **All Other Fields** (temperature, dewpoint, humidity, pressure, visibility, ceiling, cloud_cover, precip_accum): Selects the freshest non-stale observation from any source
   - METAR typically provides ceiling and cloud_cover (other sources do not provide these fields)

4. **Local vs supplemental remote weather** (Safety-Critical):
   - **On-site weather source (aggregation)**: On-site sensors (Tempest, Ambient, etc.) or **co-located** observations (e.g. KSPB METAR for KSPB). Webcams are on-field for [site health](#data-outage-detection) but are not weather aggregation inputs.
   - **Supplemental (remote) source**: Observations from a different location (e.g. KUAO METAR for 7S9, or KVUO METAR fallback when KSPB primary fails). See [supplemental remote weather policy](#supplemental-remote-weather-policy).
   - **Rule**: For LOCAL_FIELDS (wind, temperature, dewpoint, humidity, pressure, precip_accum), on-field measurements **always override** supplemental remote data when both have valid data, regardless of freshness
   - **Rationale**: Wind and temperature at the airport can differ significantly from nearby locations. Using supplemental remote data for these fields could mislead pilots
   - **Fill-in allowed**: Supplemental remote sources may fill in missing aviation fields (typically visibility, ceiling, cloud_cover from METAR) when on-field sensors are healthy but lack those measurements
   - **Fill-in forbidden during site outage**: When all on-field infrastructure is stale or unavailable, supplemental remote weather fields are hidden (fail closed) in addition to the outage banner
   - **Classification**: Co-located vs supplemental is determined from the active reporting identity (for METAR: station ICAO from aggregated weather data vs `airport.icao`). The same distinction governs aggregation, site health, and fail-closed display (see [Data outage detection](#data-outage-detection)).

5. **Aggregation Process**:
   1. Fetch all configured sources in parallel using `curl_multi`
   2. Parse each response into `WeatherSnapshot` with per-field observation times (METAR adapter sets `metarStationId` from source config)
   3. For wind group: Prefer on-field sources over supplemental remote data; among the same class, select freshest complete wind data
   4. For each other field: Prefer on-field over supplemental remote data when both have valid data; otherwise select freshest non-stale value
   5. Build aggregated result with `_field_source_map` (which source provided each field) and `_field_obs_time_map` (observation time for each field)
   6. Validate all fields against climate bounds (catches unit errors, sensor malfunctions)
   7. Fix pressure unit issues automatically (values > 100 inHg divided by 100)

6. **Example: NWS + METAR Aggregation**:
   
   When NWS is fresher:
   
   | Field | NWS (5 min old) | METAR (25 min old) | Selected |
   |-------|-----------------|---------------------|----------|
   | wind_speed | 4 kts | 6 kts | **NWS** (fresher) |
   | temperature | -2°C | -3°C | **NWS** (fresher) |
   | visibility | 10 SM | 10 SM | **NWS** (fresher) |
   | ceiling | null | 1100 ft | **METAR** (only source) |
   | cloud_cover | null | OVC | **METAR** (only source) |
   
   When METAR is fresher:
   
   | Field | NWS (25 min old) | METAR (5 min old) | Selected |
   |-------|------------------|-------------------|----------|
   | wind_speed | 6 kts | 3 kts | **METAR** (fresher) |
   | temperature | -2°C | -2°C | **METAR** (fresher) |

   **Note**: If a source is supplemental (remote, not co-located with the airport) and an on-field or co-located source has data, on-field wins regardless of freshness.

7. **Data Classes**:
   - `WeatherSnapshot`: Complete weather state from one source; `metarStationId` set for METAR source (station ICAO)
   - `WeatherReading`: Single field value with source and observation time
   - `WindGroup`: Grouped wind fields ensuring consistency

8. **Max Acceptable Ages** (per source type, used for staleness checks):
   - Tempest: 300 seconds (5 minutes)
   - Ambient/WeatherLink: 300 seconds (5 minutes). WeatherLink's actual data interval is set by Davis subscription (Basic 15m, Pro 5m, Pro+ ~1m); see [CONFIGURATION.md](CONFIGURATION.md) Weather Sources.
   - PWSWeather: 600 seconds (10 minutes)
   - SynopticData: 900 seconds (15 minutes)
   - NWS: 10800 seconds (3 hours) - same as failclosed threshold
   - METAR: 7200 seconds (2 hours)
   - Backup: Uses same thresholds as its source type


---

## Weather Data Calculations

### Temperature Conversions

**Celsius to Fahrenheit**:
- Formula: `°F = (°C × 9/5) + 32`
- Applied to: `temperature`, `dewpoint`
- Stored as: `temperature_f`, `dewpoint_f`
- Note: This conversion is performed server-side when storing data (see [Server-Side Conversions](#server-side-conversions-api-adapters))

### Dewpoint Calculations

**From Temperature and Humidity** (Magnus-Tetens Approximation):
- Used when dewpoint not provided by source
- Widely accepted empirical formula in meteorology with good accuracy for typical atmospheric conditions

**For detailed formulas, constants, and accuracy specifications, see [SAFETY_CRITICAL_CALCULATIONS.md](SAFETY_CRITICAL_CALCULATIONS.md#dewpoint-calculations)**

**Formula**:
```
γ = ln(RH/100) + [(b × T) / (c + T)]
Td = (c × γ) / (b - γ)
```

**Constants** (Alduchov and Eskridge, 1996):
- a = 6.1121 mb (saturation vapor pressure at 0°C, not used in dewpoint calc)
- b = 17.368 (dimensionless)
- c = 238.88°C

**Valid Range**: -40°C to +50°C (typical atmospheric conditions)
**Accuracy**: ±0.4°C within valid range

**Alternative Constants**: b=17.27, c=237.7 (Buck, 1981) - commonly used for 0°C to 50°C

**Sources**:
- Alduchov, O. A., and Eskridge, R. E. (1996): "Improved Magnus Form Approximation of Saturation Vapor Pressure", Journal of Applied Meteorology, 35(4)
- Lawrence, M. G. (2005): "The Relationship between Relative Humidity and the Dewpoint Temperature in Moist Air", Bulletin of the American Meteorological Society

**Humidity from Dewpoint** (Reverse Magnus):
- Used when humidity not provided but dewpoint is
- Formula: 
  ```
  e_sat = 6.112 × exp[(17.67 × T) / (T + 243.5)]
  e = 6.112 × exp[(17.67 × Td) / (Td + 243.5)]
  RH = (e / e_sat) × 100
  ```
- Where e_sat = saturation vapor pressure at temperature T (mb)
- e = actual vapor pressure at dewpoint Td (mb)
- Constants: 6.112 mb, 17.67, 243.5°C (Buck, 1981)

**Sources**:
- Buck, A. L. (1981): "New Equations for Computing Vapor Pressure and Enhancement Factor", Journal of Applied Meteorology, 20(12)
- World Meteorological Organization (WMO) Guide to Instruments and Methods of Observation (CIMO Guide)

### Dewpoint Spread

**Calculation**: `dewpoint_spread = temperature - dewpoint`
- Indicates how close temperature is to dewpoint
- Lower spread = higher humidity, potential for fog
- Displayed in same unit as temperature (°C or °F)

### Wind Calculations

**Peak Gust**:
- Live peak gust value measured through sampling within the last 10-20 minute period
- Provided directly by weather sources (no calculation needed)
- Displayed in runway wind section as "Peak Gust:"
- Validation: Must be >= wind_speed (gusts cannot be less than steady wind)
- Range: 0 to 242 knots (earth wind max + 10% margin)

**Gust Factor** (Gust Spread):
- Formula: `gust_factor = peak_gust - wind_speed`
- Represents the difference between peak gust and steady wind
- Allows pilots to apply "add half the gust factor to your approach speed" rule
- Displayed in knots
- Validation: 0 to 50 knots (reasonable gust spread range), or null if no gust factor

**Variable Wind Direction**:
- METAR may report "VRB" (variable) instead of numeric direction
- `wind_direction` is set to `"VRB"` by METAR adapter
- `addWindDirectionMagneticToWeather` sets `wind_direction_text` to `"VRB"` for display (no magnetic conversion)
- Display layers check `wind_direction_text === 'VRB'` and show "VRB" instead of `---`

### Pressure Altitude

**Formula** (per FAA handbooks):

```
Pressure Altitude = Station Elevation + [(29.92 - Altimeter Setting) × 1000]
```

**For detailed technical specifications and test methodology, see [SAFETY_CRITICAL_CALCULATIONS.md](SAFETY_CRITICAL_CALCULATIONS.md#pressure-altitude)**

**Purpose**: Indicates the altitude in the standard atmosphere corresponding to a particular pressure value
- It's the altitude indicated when the altimeter is set to 29.92 inHg
- Higher pressure altitude = reduced aircraft performance
- Used as input for density altitude calculation

**Standard Atmosphere Reference**:
- Sea level standard pressure: 29.92 inHg (1013.25 hPa)
- Pressure lapse rate: ~1 inHg per 1,000 feet

**Examples**:
- Altimeter = 29.92 inHg (standard) → PA = Field Elevation
- Altimeter < 29.92 inHg (low pressure) → PA > Field Elevation (worse performance)
- Altimeter > 29.92 inHg (high pressure) → PA < Field Elevation (better performance)

**Requirements**: 
- Station elevation (from airport config)
- Altimeter setting (from weather data, in inHg)

**Sources**:
- FAA Pilot's Handbook of Aeronautical Knowledge (FAA-H-8083-25)
- FAA Instrument Flying Handbook (FAA-H-8083-15B)

### Density Altitude

**SAFETY CRITICAL**: This calculation directly affects takeoff/landing performance decisions. An underestimated density altitude can lead to runway overruns or inability to climb.

**For detailed technical specifications, formulas, and test methodology, see [SAFETY_CRITICAL_CALCULATIONS.md](SAFETY_CRITICAL_CALCULATIONS.md#density-altitude)**

**Calculation Logic** (per FAA-H-8083-25C):

1. **Calculate pressure altitude from station conditions**:
   - Take the station elevation
   - Add 1000 feet for every inch of mercury below standard pressure (29.92 inHg)
   - Subtract 1000 feet for every inch of mercury above standard pressure
   - Result: Pressure altitude (PA)

2. **Determine standard temperature at that pressure altitude**:
   - Start with standard sea level temperature (15°C)
   - Subtract 2 degrees Celsius for every 1000 feet of pressure altitude
   - Result: ISA (International Standard Atmosphere) temperature

3. **Compare actual temperature to standard temperature**:
   - Measure the difference between actual temperature and ISA temperature
   - Use Celsius for this comparison (critical!)
   - Result: Temperature deviation from standard

4. **Calculate density altitude from the deviation**:
   - Add 120 feet of density altitude for every degree Celsius above ISA
   - Subtract 120 feet of density altitude for every degree Celsius below ISA
   - Result: Density altitude

**Critical calculation requirements**:
- Step 2 MUST use pressure altitude, not station elevation
- The 120 coefficient is for Celsius, NOT Fahrenheit (using Fahrenheit overestimates by ~80%)
- This matters most at high airports, on low-pressure days, and in hot conditions

**Plain English Summary**:
- If actual temperature equals ISA temperature → density altitude equals pressure altitude
- If actual temperature is hotter than ISA → density altitude is higher than pressure altitude (worse performance)
- If actual temperature is colder than ISA → density altitude is lower than pressure altitude (better performance)

**Real-World Example** (Denver International on hot summer day):
- Station elevation: 5,434 ft
- Altimeter setting: 24.50 inHg (5.42 inHg below standard)
- Temperature: 35°C (hot!)
- **Step 1**: PA = 5,434 + (5.42 × 1000) = 10,854 ft
- **Step 2**: ISA = 15 - (2 × 10.854) = -6.7°C
- **Step 3**: Deviation = 35 - (-6.7) = 41.7°C above ISA
- **Step 4**: DA = 10,854 + (120 × 41.7) = **15,858 ft**
- Result: Aircraft performs as if at 15,858 ft (3× field elevation!)

**Requirements**:
- Station elevation (ft)
- Temperature (Celsius)
- Altimeter setting (inHg)

**Testing**: 
- Reference tests: `tests/Unit/SafetyCriticalReferenceTest.php` (23 tests with known-good values from E6B manuals)
- Unit tests: `tests/Unit/WeatherCalculationsTest.php` (real-world scenarios)

**Sources**:
- FAA Pilot's Handbook of Aeronautical Knowledge (FAA-H-8083-25C)
- FAA Aviation Weather Handbook (FAA-H-8083-28)
- ICAO Standard Atmosphere (Doc 7488)

### Flight Category Calculation

**SAFETY CRITICAL**: Incorrect categorization could lead pilots to attempt VFR flight in marginal or IFR conditions, potentially leading to controlled flight into terrain (CFIT) or loss of control accidents.

**For detailed category definitions, decision logic, and special cases, see [SAFETY_CRITICAL_CALCULATIONS.md](SAFETY_CRITICAL_CALCULATIONS.md#flight-category)**

**Purpose**: Categorizes flight conditions (VFR, MVFR, IFR, LIFR) based on visibility and ceiling for situational awareness and flight planning.

**IMPORTANT**: These categories are for planning and situational awareness only. They do NOT represent the minimum weather requirements for VFR flight under 14 CFR § 91.155, which vary by airspace class and time of day.

**Categories** (FAA Standard):

**VFR (Visual Flight Rules)** - Green:
- Ceiling: Greater than 3,000 feet AGL
- Visibility: Greater than 5 statute miles
- Rule: **BOTH** conditions must be met

**MVFR (Marginal VFR)** - Blue:
- Ceiling: 1,000 to 3,000 feet AGL
- Visibility: 3 to 5 statute miles
- Rule: **Either** condition qualifies

**IFR (Instrument Flight Rules)** - Red:
- Ceiling: 500 to less than 1,000 feet AGL
- Visibility: 1 to **less than** 3 statute miles
- Rule: **Either** condition qualifies
- Note: 3 SM exactly is MVFR, not IFR

**LIFR (Low IFR)** - Magenta:
- Ceiling: Less than 500 feet AGL
- Visibility: Less than 1 statute mile
- Rule: **Either** condition qualifies

**Decision Logic**:
1. Categorize ceiling and visibility independently
2. For VFR: **BOTH** must be VFR (AND logic)
3. For all other categories: Use **WORST** case (most restrictive)
4. Category order (most to least restrictive): LIFR > IFR > MVFR > VFR

**Special Cases**:
- Unlimited ceiling (null/no clouds): Treated as VFR for ceiling
- Unlimited visibility (>10 SM or sentinel value): Treated as VFR for visibility
- Missing ceiling + VFR visibility: Assumes unlimited ceiling → VFR
- Missing visibility + VFR ceiling: Conservative → MVFR (cannot confirm VFR)

**Sources**:
- FAA Aeronautical Information Manual (AIM) Chapter 7, Section 7-1-6
- FAA Aviation Weather Handbook (FAA-H-8083-28A), Chapter 13
- 14 CFR § 91.155 (Basic VFR weather minimums - separate from categories)
- National Weather Service Directive on Aviation Weather Services

### Sun Calculations

Sunrise, sunset, and twilight times for display and night mode. Uses NOAA Solar Calculator formulas for FAA-aligned accuracy.

**Library**: `lib/sun/SunCalculator.php`  
**Primary Reference**: [NOAA GML Solar Calculator](https://gml.noaa.gov/grad/solcalc/solareqns.PDF)  
**Accuracy Goal**: ±1 minute for ±72° latitude (NOAA); high latitudes inherently harder, ±5 min is best achievable

**API** (`SunCalculator::getSunInfo($timestamp, $lat, $lon)`):
- Returns: `sunrise`, `sunset`, `civil_twilight_begin`, `civil_twilight_end`, `nautical_twilight_begin`, `nautical_twilight_end` (Unix timestamps UTC, or `null`)
- **Null** = event does not occur (polar day/night); **exceptions** = invalid input (never null for errors)

**Night Definition** (FAA 14 CFR §1.1):
- Night mode uses **civil twilight**, not sunrise/sunset
- **Night starts**: Evening civil twilight (sun 6° below horizon)
- **Night ends**: Morning civil twilight (sun 6° below horizon)

**Integration**:
- `lib/weather/utils.php`: `getSunInfoForAirport()`, `getSunriseTime()`, `getSunsetTime()`, `getDaylightPhase()`
- `pages/airport.php`: Night mode auto-switch uses `civil_twilight_end` → `civil_twilight_begin`

**Testing**:
- Fixtures: `tests/Fixtures/sun-noaa-reference.json` (Denver, Anchorage, Sydney, equator, London, Tokyo, Rovaniemi, Utqiaġvik)
- Tests: `tests/Unit/SunCalculatorTest.php`
- Tolerance: ±1 min lower latitudes; ±5 min high latitudes (inherently harder; fixture comparisons)

**References**:
- [NOAA Solar Calculator Equations](https://gml.noaa.gov/grad/solcalc/solareqns.PDF)
- [FAA 14 CFR §1.1](https://www.ecfr.gov/current/title-14/chapter-I/subchapter-A/part-1/subpart-A/section-1.1) - "Night" definition

---

## Weather Data Transformations

### Daily Tracking

The system tracks daily extremes that reset at local midnight:

#### Temperature Extremes
- **High Temperature**: Highest temperature observed today
- **Low Temperature**: Lowest temperature observed today
- **Reset Time**: Local midnight (based on airport timezone)
- **Storage**: JSON file with date-based keys (`YYYY-MM-DD`)
- **Update Logic**:
  - On each weather fetch, compare current temp to stored extremes
  - Update high if current > stored high
  - Update low if current < stored low
  - If current equals stored low, update timestamp to earliest observation
- **Observation Timestamps**: Tracks when each extreme was actually observed (not when fetched)

#### Peak Gust
- **Value**: Highest gust speed observed today
- **Reset Time**: Local midnight
- **Storage**: Same JSON structure as temperature extremes
- **Update Logic**: Only updates if current gust > stored peak
- **Observation Timestamp**: Tracks when peak gust occurred

**Date Key Calculation**:
- Uses airport's local timezone to determine "today"
- Ensures daily reset happens at local midnight, not UTC
- Example: Airport in PST (UTC-8) resets at 00:00 PST, not 00:00 UTC

**Data Persistence**:
- Stored in per-airport files: `cache/peak_gusts/{airport}.json` and `cache/temp_extremes/{airport}.json`
- One file per airport to reduce lock contention and isolate failures
- File locking prevents race conditions during concurrent updates
- Old entries (>2 days) automatically cleaned up
- Legacy single-file format (`cache/peak_gusts.json`, `cache/temp_extremes.json`) is migrated on first access
- **Fallback**: When daily tracking is empty, values are computed from weather history (if enabled)

### Staleness Handling

**Purpose**: Ensures pilots never see stale data without clear indication

**Staleness Threshold**: 3 hours (configurable via `MAX_STALE_HOURS`)

**Source-Specific Staleness**:
- Primary source fields checked against `last_updated_primary`
- METAR fields checked against `last_updated_metar`
- Fields nulled independently based on their source

**Fields Affected by Staleness**:
- **Primary Source**: Temperature, dewpoint, humidity, wind, pressure, precipitation, altitudes
- **METAR Source**: Visibility, ceiling, cloud cover
- **NOT Affected**: Daily tracking values (temp_high_today, temp_low_today, peak_gust_today) - these are valid historical data

**Staleness Check Flow**:
1. Calculate age of each source timestamp
2. If age >= threshold, null out fields from that source
3. Recalculate flight category if visibility/ceiling were nulled
4. Daily tracking values preserved (always valid for the day)

### Source Timestamp Extraction

**Purpose**: Centralized timestamp extraction for consistency across outage detection and status page

**Function**: `getSourceTimestamps($airportId, $airport)` in `lib/weather/source-timestamps.php`

**Extraction Logic**:
- **Primary Weather**: Reads from `cache/weather/{airport_id}.json`
  - Prefers `obs_time_primary`, falls back to `last_updated_primary`
  - Returns timestamp, age, and availability status
- **METAR**: Reads from same cache file
  - Prefers `obs_time_metar`, falls back to `last_updated_metar`
  - Detected if `metar_station` exists OR `weather_source.type === 'metar'`
- **Webcams**: Checks each configured webcam
  - Tries EXIF data first (via `getImageCaptureTimeForPage()`), falls back to `filemtime()`
  - Returns newest timestamp, total count, and stale count
  - Handles both JPG and WebP formats

**Error Handling**:
- Missing files: Returns timestamp 0, age PHP_INT_MAX
- Corrupted JSON: Returns timestamp 0, age PHP_INT_MAX
- Missing timestamps: Returns timestamp 0, age PHP_INT_MAX
- All errors suppressed with `@` and documented rationale

**Used By**:
- `checkDataOutageStatus()` - Outage banner detection
- `checkAirportHealth()` - Status page health checks
- Ensures consistent timestamp extraction across both features

### Webcam History Timestamp Handling

**Purpose**: Extract accurate capture timestamps from webcam images for history/time-lapse playback

**Function**: `getHistoryImageCaptureTime($filePath)` in `lib/webcam-history.php`

**Timestamp Detection Order**:
1. **AviationWX Bridge marker** → interpret `DateTimeOriginal` as UTC
2. **EXIF 2.31 `OffsetTimeOriginal`** → use the specified timezone offset
3. **GPS timestamp** → always UTC per EXIF specification
4. **`DateTimeOriginal` without marker** → assume local time (backward compatible)
5. **File mtime** → fallback when no EXIF data available

**Bridge Upload Detection**:
- Bridge uploads include "AviationWX-Bridge" in EXIF `UserComment` field
- Detected via `isBridgeUpload($filePath)` helper function
- Bridge uploads write EXIF timestamps in UTC for consistent time handling

**Source Interpretation Summary**:

| Scenario | EXIF UserComment | Interpretation |
|----------|-----------------|----------------|
| Bridge upload | Contains "AviationWX-Bridge" | DateTimeOriginal is UTC |
| Direct camera | No marker | DateTimeOriginal is local time |
| Any with GPS timestamp | N/A | GPSTimeStamp is always UTC |
| Any with OffsetTimeOriginal | N/A | Use specified offset |

**GPS Timestamp Parsing**:
- Uses `parseGPSTimestamp($gps)` helper function
- Handles EXIF rational values (numerator/denominator format)
- Protects against division by zero in rational values
- GPS timestamps are always interpreted as UTC per EXIF specification

**Backward Compatibility**:
- Direct camera uploads (Reolink, etc.) continue using local time interpretation
- No changes required for existing camera configurations
- Bridge marker detection is additive, not breaking

### Data Outage Detection

**Purpose**: Warn users when **on-field infrastructure** is offline (complete local site outage). This is distinct from `limited_availability` (off-grid/solar sites that expect periodic downtime; see [CONFIGURATION.md](CONFIGURATION.md)).

**On-field infrastructure** (sources that count toward site health):

- Non-METAR weather sources configured for the airport (Tempest, NWS, AWOSnet, etc.)
- Webcams
- **Co-located weather sources** only: sources that observe the airport itself (e.g. KSPB ASOS METAR while displaying KSPB; on-field Tempest; on-field AWOSnet). Co-located sources may use different power or internet than other on-site equipment; they still observe the field and are treated as local until they go stale.

**Supplemental remote weather does not count toward site health.** A remote source (e.g. KUAO METAR for 7S9) may continue reporting while Tempest and webcams are down during a power outage. Fresh supplemental remote data must **not** suppress the outage banner. The same rule applies to all remote weather source types under [Supplemental remote weather policy](#supplemental-remote-weather-policy).

**Outage threshold:** Default fail-closed staleness for on-field sources (`getStaleFailclosedSeconds()`, typically 3 hours). Airports with `limited_availability: true` use `getOutageBannerThresholdSeconds()` (default 30 minutes) and a different banner message.

**Detection logic:**

1. Uses shared `getSourceTimestamps()` to read timestamps from weather cache and webcam metadata
2. Builds the set of **on-field** sources (primary, backup, webcams, and co-located weather sources such as on-field METAR)
3. Site is **in outage** when **all** on-field sources exceed the outage threshold
4. Banner shows the newest stale timestamp among on-field sources (not supplemental remote data)
5. Banner clears when any on-field source recovers fresh data

**Display when in outage:**

- Standard banner copy: local data sources offline (not the `limited_availability` battery/solar message unless that flag is set)
- **Fail closed on supplemental remote weather:** fields from supplemental remote sources (typically METAR visibility, ceiling, and cloud_cover) are nulled in cache serve and API responses while the site is in outage, so pilots do not see uncorroborated remote data

**Examples:**

| Airport | On-field down | Supplemental remote | Co-located weather | Outage banner? |
|---------|---------------|---------------------|--------------------|----------------|
| 7S9 | Tempest + webcams stale | KUAO METAR fresh | n/a | **Yes**; hide supplemental fields |
| KSPB | Tempest stale | n/a | KSPB METAR fresh | **No** (on-field ASOS still up) |
| KSPB | Tempest + KSPB METAR stale | n/a | both stale | **Yes** |
| METAR-only (co-located) | n/a | n/a | METAR stale | **Yes** |

**Outage State File Persistence**:
- Creates `cache/outage_{airport_id}.json` when outage is first detected
- Preserves original outage start time across brief recoveries (grace period matches `getOutageBannerThresholdSeconds()` for the airport)
- Handles back-to-back outages as single continuous event
- Automatically cleans up after full recovery (grace period expires)
- Logs outage start and end events for operational visibility

**Fallback Chain for Outage Start Time** (on-field sources only):
1. Existing outage state file (preserves original start time)
2. Newest timestamp from stale on-field sources (via `getSourceTimestamps()`)
3. Webcam cache file modification times (if weather cache is lost)
4. Current time (final fallback)

**Display Behavior**:
- Red banner at top of page (similar to maintenance banner); standard local-outage copy unless `limited_availability` is set
- Only shown when airport is **NOT** in maintenance mode and all on-field infrastructure is stale
- Supplemental remote weather fields are hidden while the banner is active
- Includes newest on-field stale timestamp to help identify when the local outage started

**Frontend Updates**:
- Client-side checks every 30 seconds for immediate feedback
- Server-side API checks every 2.5 minutes (`/api/outage-status.php`) to sync with backend state
- Banner updates automatically when data recovers
- Timestamp display updates every 60 seconds

**Webcam Staleness Warning**:
- Individual webcam timestamps show warning emoji (⚠️) when age exceeds fail-closed threshold (default 3 hours)
- At fail-closed: image replaced with stale overlay (dimmed last frame + "Live image unavailable. Tap for time-lapse history.") if history has frames; otherwise placeholder

### Data Merging with Fallback

When new data is fetched but some fields are missing:

**Merge Strategy**:
1. Start with new data as base
2. For each missing field, check if old cached value exists
3. If old value exists and is not stale, preserve it
4. If old value is stale, leave field as null

**Preservable Fields**:
- Temperature, dewpoint, humidity
- Wind speed, direction, gusts
- Pressure, altitudes
- Visibility, ceiling, cloud cover

**Special Cases**:
- **Precipitation**: If missing from new data, set to 0 (daily value, should not preserve yesterday's)
- **METAR Fields**: If METAR was successfully fetched but field is explicitly null (unlimited ceiling), always overwrite old value
- **Daily Tracking**: Always preserved (valid historical data)

**Purpose**: Provides graceful degradation - if one source fails, last known good values are shown (if not stale)

---

## Weather Data Caching and Staleness

### Cache Strategy: Stale-While-Revalidate

**Pattern**: Serve stale cache immediately, refresh in background

**Flow**:
1. **Request Received**: Check cache file age
2. **Cache Fresh** (< refresh interval): Serve immediately, no fetch
3. **Cache Stale** (≥ refresh interval): 
   - Serve stale cache immediately (if exists)
   - Flush response to client
   - Continue script execution in background
   - Fetch fresh data
   - Update cache file
4. **No Cache**: Fetch fresh data, serve, cache

**Benefits**:
- Fast response times (always serve immediately)
- Fresh data (background refresh keeps cache current)
- Graceful degradation (stale data better than no data)

### Cache File Structure

**Location**: `cache/weather/{airport_id}.json`

**Content**: Complete weather data object with all fields:
- Raw measurements (temperature, wind, etc.)
- Calculated values (dewpoint spread, altitudes, etc.)
- Daily tracking (temp_high_today, peak_gust_today, etc.)
- Timestamps (last_updated, obs_time_primary, etc.)
- Flight category and CSS class

### Weather History Storage

**Purpose**: Maintains a rolling 24-hour history of weather observations for API access and analysis.

**Location**: `cache/weather/history/{airport_id}.json`

**Storage Format**: JSON file containing:
- `airport_id`: Airport identifier
- `updated_at`: Last update timestamp
- `retention_hours`: Retention period (default: 24 hours)
- `observations`: Array of historical observations

**Observation Structure**: Each observation includes:
- Weather field values (temperature, wind, pressure, etc.)
- `obs_time`: Unix timestamp of observation
- `obs_time_iso`: ISO 8601 formatted timestamp
- `field_sources`: Map of field names to source identifiers (e.g., `{"temperature": "tempest", "visibility": "metar"}`)
- `sources`: Array of all unique sources used in this observation (e.g., `["metar", "tempest"]`)

**Source Attribution**:
- Each observation records which source provided each field via `field_sources` map
- The `sources` array lists all unique sources that contributed data to the observation
- Source identifiers match those used in aggregation (e.g., `"tempest"`, `"metar"`, `"ambient"`, `"weatherlink"`, `"pwsweather"`, `"synopticdata"`, `"backup"`)
- Only included when source information is available (backward compatible with older observations)

**Append Logic**:
- Observations are appended when weather cache is updated
- Deduplication: Only stores observations with unique `obs_time` values
- Automatic pruning: Removes observations older than retention period
- Safety limit: Maximum 1,500 observations per airport

**API Access**: Available via Public API endpoint `/v1/airports/{id}/weather/history` with optional time filtering and resolution downsampling (all, hourly, 15min).

**Wind Rose Petals**: `computeWindRose()` derives 16-sector wind distribution from observations in a configurable rolling window. Window size is set by `config.public_api.wind_rose_window_hours` (default 1). Observations with wind speed below `CALM_WIND_THRESHOLD_KTS` (3 knots) are excluded. Requires at least 2 valid observations. Result is added to weather cache as `last_hour_wind` when `config.public_api.weather_history_enabled` is true. Petals extend in direction wind is FROM (meteorological convention). Arrow shows direction wind is blowing TOWARD (windsock convention).

**API Format**: The Internal and Public APIs return `last_hour_wind` as an object: `{ sectors: number[16], sector_labels: string[16], reference: "magnetic_north", unit: "knots", period_label: string }`. Sectors are ordered N, NNE, NE, ENE, E, ESE, SE, SSE, S, SSW, SW, WSW, W, WNW, NW, NNW. `period_label` is derived from `wind_rose_window_hours` (e.g. "last hour", "last 3 hours") or overridden by `wind_rose_period_label`. Null when insufficient observations.

### Refresh Intervals

**Per-Airport Configuration**:
- `weather_refresh_seconds` in airport config (default: 60 seconds)
- Can be customized per airport based on source update frequency

**Cache Age Checks**:
- Fresh: Age < refresh interval → serve immediately
- Stale: Age ≥ refresh interval → serve stale, refresh in background
- Too Stale: Age ≥ 3 hours → null out stale fields before serving

### Cache Invalidation

**Automatic**:
- Background refresh updates cache on stale requests
- Fresh data always overwrites cache
- Daily tracking updates don't invalidate cache (separate files)

**Manual**:
- `?force_refresh=1` query parameter forces fresh fetch
- Cron job requests (detected by User-Agent) always force refresh

---

## Webcam Data Fetching

### Overview

Webcam images are fetched from various source types and cached as JPEG files. The system uses a **unified webcam worker** that handles both pull-type (fetched) and push-type (uploaded) cameras through a common architecture.

### Unified Webcam Worker Architecture

The webcam processing pipeline uses three main components:

1. **AcquisitionStrategy Interface** (`lib/webcam-acquisition.php`)
   - `PullAcquisitionStrategy`: Fetches images from remote sources (MJPEG, RTSP, static URLs, federated API)
   - `PushAcquisitionStrategy`: Processes images uploaded via FTP/SFTP
   - Returns `AcquisitionResult` with image path, timestamp, source type, and metadata

2. **ProcessingPipeline** (`lib/webcam-pipeline.php`)
   - Standardized validation, variant generation, and promotion
   - Single image load through pipeline (GD resource loaded once, passed through all steps)
   - Returns `PipelineResult` with final image path and metadata

3. **WebcamWorker** (`lib/webcam-worker.php`)
   - Orchestrates acquisition strategy and processing pipeline
   - Handles locking, circuit breaker, and cleanup
   - Returns `WorkerResult` with status and metadata

### Scheduling Architecture

**WebcamScheduleQueue** (`lib/webcam-schedule-queue.php`)
- Uses `SplMinHeap` for O(log N) scheduling instead of O(N) scan
- Cameras ordered by `next_due_time` 
- Scheduler runs every 1 second, extracts ready cameras from queue
- Re-inserts cameras with calculated next due time after processing

**Refresh Rate Configuration Hierarchy**:
1. Camera-specific `refresh_seconds` (highest priority)
2. Airport-level `webcam_refresh_seconds`
3. Global `webcam_refresh_seconds`
4. Default: 60 seconds

**Rate Bounds** (enforced):
- Minimum: 10 seconds (`MIN_WEBCAM_REFRESH`)
- Maximum: 3600 seconds (`MAX_WEBCAM_REFRESH`)

### Source Types (Pull Cameras)

#### 1. MJPEG Stream (Default)
- **Protocol**: HTTP MJPEG stream
- **Detection**: Default if URL doesn't match other patterns
- **Fetch Method**: 
  - Downloads stream data
  - Extracts first complete JPEG frame (detected by JPEG end marker `0xFF 0xD9`)
  - Stops after one frame received
  - Validates JPEG format and size
- **Timeouts**: 
  - Connection: 10 seconds
  - Overall: 30 seconds
  - Max size: 10MB

#### 2. RTSP Stream
- **Protocol**: RTSP or RTSPS (secure)
- **Detection**: URL starts with `rtsp://` or `rtsps://`
- **Fetch Method**: 
  - Uses `ffmpeg` to capture single frame
  - Supports TCP and UDP transport (configurable)
  - RTSPS always uses TCP
  - 3 attempts with exponential backoff (1s, 5s, 10s delays before each attempt)
  - Error frame detection rejects Blue Iris error screens and triggers retry
- **Configuration**:
  - `rtsp_transport`: 'tcp' or 'udp' (default: 'tcp')
  - `rtsp_fetch_timeout`: Connection timeout in seconds
  - `rtsp_max_runtime`: Maximum ffmpeg runtime (default: 6 seconds)
- **Error Classification**:
  - Timeout, connection, DNS errors → transient (normal backoff)
  - Authentication, TLS errors → permanent (2x backoff)

#### 3. Static JPEG
- **Detection**: URL ends with `.jpg` or `.jpeg`
- **Fetch Method**: 
  - Simple HTTP download
  - **HTTP conditional + checksum**: Sends `If-None-Match` when ETag cached; on 304 skips download. On 200, compares SHA-256 checksum to cached; skips processing when unchanged. Prevents misrepresenting image age.
  - Validates JPEG format
  - Saves directly to staging

#### 4. Static PNG
- **Detection**: URL ends with `.png`
- **Fetch Method**: 
  - Downloads PNG image
  - **HTTP conditional + checksum**: Same as Static JPEG (ETag + checksum skip when unchanged)
  - Converts to JPEG using GD library
  - Quality: 85%
  - Saves to staging

#### 5. Federated API
- **Detection**: URL contains `/api/v1/webcams/` and `aviationwx.org` or `localhost`
- **Fetch Method**:
  - Fetches latest image from another AviationWX instance
  - **HTTP conditional + checksum**: Same as Static JPEG (ETag + checksum skip when unchanged)
  - Supports API key authentication
  - Validates image data before saving

### Source Types (Push Cameras)

- **Type**: `type: 'push'` or has `push_config`
- **Behavior**: Images uploaded by cameras via SFTP/FTP/FTPS
- **Protocol Support**: Both FTP and SFTP are enabled for each push camera with the same credentials
- **Directory Structure** (separate hierarchies for FTP and SFTP):
  - FTP: `/cache/ftp/{airport}/{username}/` (ftp:www-data 2775)
  - SFTP Chroot: `/var/sftp/{username}/` (root:root 755)
  - SFTP Upload: `/var/sftp/{username}/files/` (ftp:www-data 2775)
- **Upload Paths**:
  - FTP: Upload to `/` (lands in FTP directory)
  - SFTP: Upload to `/files/` (chrooted, must use subdirectory)
- **Processing**: Webcam processor checks both FTP and SFTP directories for each camera
- **Note**: SFTP uses `/var/sftp/` (outside cache) because SSH chroot requires ALL parent directories to be root-owned
- **Chroot repair**: `repair-sftp-chroot-permissions.sh` runs from `sync-push-config.php`, `set-cache-permissions.sh`, and `create-sftp-user.sh` so `{username}/` stays `root:root` and `files/` stays `ftp:www-data` (see [OPERATIONS.md](OPERATIONS.md#bridge--sftp-uploads-fail-chroot-permissions))
- **Upload Sources**:
  - **Direct camera uploads**: Cameras upload via SFTP/FTP/FTPS with local time EXIF
  - **Bridge uploads**: AviationWX-Bridge uploads with UTC EXIF and marker in UserComment
- **Supported Upload Formats**: JPEG, PNG, WebP
- **Subfolder Support**: Cameras that create date-based folder structures (e.g., `2026/01/06/image.jpg`) are fully supported:
  - Recursive search up to 10 levels deep
  - Files found and processed regardless of folder structure
  - Empty folders automatically cleaned up after processing
- **Processing**:
  - PNG always converted to JPEG (we don't serve PNG)
  - Original format preserved for JPEG, WebP (no redundant conversion)
  - Missing formats generated in background (JPEG, WebP)
  - Mtime synced to match source image's capture time
- **Timestamp Handling**:
  - Bridge uploads: EXIF `DateTimeOriginal` interpreted as UTC
  - Direct uploads: EXIF `DateTimeOriginal` interpreted as local time
  - Detection via "AviationWX-Bridge" marker in EXIF UserComment
  - **Timestamp Drift Validation**: Rejects images where EXIF timestamp differs from upload time by > 2 hours (indicates misconfigured camera clock)
- **Upload Stability Detection** (Adaptive):
  - Files must achieve stability (size + mtime unchanged) before processing
  - **Adaptive checking**: Starts conservative (20 consecutive stable checks), optimizes based on camera history
  - **P95-based optimization**: Uses 95th percentile of successful upload times to determine required checks
  - **Minimum checks**: 5 (after optimization, 2.5 seconds verification)
  - **Maximum checks**: 20 (conservative default, 10 seconds verification)
  - **Feedback loop**: High rejection rate (>5%) triggers more conservative behavior
  - **Metrics tracking**: Rolling window of last 100 uploads per camera stored in APCu
- **File Age Limits** (Fail-Closed Protection):
  - **Minimum age**: 3 seconds (files must age before checking, prevents checking mid-transfer)
  - **Maximum age**: 30 minutes default (configurable 10min-2hr per camera)
  - Files older than max age are considered abandoned/stuck and automatically deleted
  - Prevents worker from repeatedly checking files that will never complete
  - Tracks as rejection for metrics (unhealthy camera indicator)
- **State File Validation**:
  - Graceful recovery from corrupted `state.json` files
  - Logs warning and resets to current time (fail-closed: won't reprocess old uploads)
- **Batch Processing** (Backlog Handling):
  - Push cameras process **multiple files per worker run** to efficiently clear backlogs
  - **Processing order**: Newest file first (pilot safety), then oldest-to-newest (prevent aging out)
  - **Batch limit**: Up to 30 files per worker run (`PUSH_BATCH_LIMIT`)
  - **Extended timeout**: When ≥10 files pending, worker timeout extends to 5 minutes (`PUSH_EXTENDED_TIMEOUT_SECONDS`)
  - **Example**: 60-file backlog clears in ~2 worker runs instead of 60 runs
  - Files are moved (not copied) after processing, so they won't be re-processed

### Source Type Detection

**Order**:
1. Check `type` field in camera config (if `push`, use `PushAcquisitionStrategy`)
2. Check for `push_config` in camera config (implies push type)
3. For pull cameras, detect from URL:
   - Check URL protocol (RTSP/RTSPS)
   - Check for federated API pattern
   - Check file extension (.jpg, .jpeg, .png)
   - Default to MJPEG

### Worker Process Flow

1. **Worker Initialization**
   - `WebcamWorker` created with airport/camera config
   - `AcquisitionStrategyFactory` selects appropriate strategy
   - `ProcessingPipeline` initialized

2. **Lock Acquisition** (Hybrid Strategy)
   - Primary: ProcessPool prevents duplicate jobs
   - Secondary: `flock()` file lock for crash resilience
   - Lock file: `cache/webcams/{airport}/{cam}/worker.lock`
   - Non-blocking attempt, skip if lock held

3. **Circuit Breaker Check**
   - Checks if camera is in backoff period
   - Skips processing if circuit breaker open
   - Error severity affects backoff duration

4. **Orphaned Staging Cleanup**
   - Removes incomplete staging files from crashed workers
   - Files older than `FILE_LOCK_STALE_SECONDS` are cleaned up

5. **Image Acquisition**
   - `AcquisitionStrategy.acquire()` called
   - Strategy handles source-specific fetch/scan logic
   - Returns `AcquisitionResult` with staging file path

6. **Processing Pipeline**
   - **Single Image Load**: GD resource loaded once from staging file
   - **Error Frame Detection**: Uniform color, pixelation, Blue Iris errors
   - **EXIF Validation**: Ensures valid timestamp, adds if missing
   - **EXIF Normalization**: Converts to UTC, adds GPS timestamp fields
   - **Variant Generation**: Creates 1080p, 720p, 360p variants
   - **Format Generation**: Creates JPEG and WebP formats
   - **Atomic Promotion**: Moves to final location, updates symlinks
   - **History Cleanup**: Removes old timestamped files

7. **Lock Release**
   - `flock()` released via `releaseLock()` (also registered as shutdown function)
   - Lock file removed

### Error Handling

**Failure Recording**:
- Records failure with severity (transient/permanent)
- Updates circuit breaker state
- Logs error details with context

**Error Classification**:
- Timeout, connection, DNS → transient (normal backoff)
- Authentication, TLS, permanent config errors → permanent (2x backoff)

**Fallback Behavior**:
- Failed acquisition: Stale cache served by API
- No cache: Serves placeholder image
- **Server-Side Staleness Enforcement**: API returns 503 for images older than 3 hours (fail-closed safety)
- **Stale Overlay**: When 503 (or load error), client tries history API first; if frames exist, shows latest with dimmed overlay "Live image unavailable. Tap for time-lapse history." and timestamp; otherwise placeholder

---

## Webcam Data Processing

### Image Caching

**Cache Location**: `cache/webcams/{airport_id}/{cam_index}/`

**Directory Structure** (date/hour subdirs limit files per directory):
- `{YYYY-MM-DD}/{HH}/` - Date and hour subdirs (UTC)
- Timestamped files: `{timestamp}_{variant}.{ext}` within date/hour dirs
- Symlinks at camera root: `current.{ext}`, `original.{ext}` → relative path to latest

**File Naming**:
- Current: `current.{ext}` - symlink to latest (e.g. `2026-02-24/14/1703980800_720.jpg`)
- Original: `original.{ext}` - symlink to latest original
- Timestamped: `{YYYY-MM-DD}/{HH}/{timestamp}_{variant}.{ext}`
- Variants: `original`, `1080`, `720`, `360` (height-based)
- Formats: JPEG (`.jpg`), WebP (`.webp`)

**Atomic Writes**:
- Writes to temporary file first: `{cache_file}.tmp.{pid}.{timestamp}.{random}`
- Validates write success
- Atomic rename to final filename
- Prevents corruption from concurrent writes

**EXIF Metadata Preservation**:
- Original images have EXIF metadata (DateTimeOriginal, Description, Rights, etc.)
- When generating variants and formats, EXIF is copied using `exiftool -TagsFromFile`
- Ensures all cached files (variants, WebP) have correct capture timestamps
- Critical for accurate "Last Updated" display on frontend
- Function: `copyExifMetadata($source, $dest)` in `lib/exif-utils.php`

**Filename Timestamp Parsing** (for server-generated images without EXIF):
- IP cameras often embed timestamps in filenames (e.g., `20251229210421.jpg`)
- `parseFilenameTimestamp()` in `lib/exif-utils.php` extracts and validates these
- **12-hour mtime window**: Extracted timestamp must be within ±12h of file mtime (`FILENAME_TIMESTAMP_MTIME_WINDOW_HOURS`)
- Reduces false positives from product IDs, serial numbers, or coincidental digit sequences
- Covers timezone differences and typical upload delays; override via `define()` before `constants.php` loads if needed

### Format Generation

**Multi-Format Support**:
- System generates JPEG and WebP formats from source images (if enabled in config)
- Format generation is globally configurable via `webcam_generate_webp` flag
- Default: WebP disabled (only JPEG generated) to control resource usage
- All generation runs synchronously in parallel with `nice -n 10` priority (low priority to avoid interfering with normal operations)
- Mtime automatically synced to match source image's capture time (EXIF or filemtime)
- EXIF metadata is copied from source to all generated formats using `copyExifMetadata()`
- Formats generated in background, may not be immediately available
- Generation jobs are logged (start and result) for monitoring and troubleshooting

**JPEG to WebP**:
- Uses ffmpeg: `nice -n 10 ffmpeg -i input.jpg -frames:v 1 -q:v 90 -compression_level 6 output.webp`
- Quality: 90 (0-100 scale, higher = better quality) - configurable via `config.webcam_webp_quality`
- Compression: Level 6 (0-6 scale)
- Priority: `nice -n 10` (low priority to avoid interfering with normal operations)
- Mtime sync: `touch -t {timestamp} output.webp` (chained after generation)
- EXIF metadata copied from source JPEG using `exiftool -TagsFromFile`
- Only runs if `webcam_generate_webp` is enabled in config

**Variant Generation**:
- Original images are downscaled to configured heights (default: 1080, 720, 360)
- Width calculated to preserve aspect ratio, capped at 3840px for ultra-wide cameras
- EXIF metadata is copied from original to all variants using `copyExifMetadata()`
- Variants stored as `{timestamp}_{height}.{format}` (e.g., `1703700000_720.jpg`)
- `current.jpg` symlink points to primary variant (720p by default)
- `original.jpg` symlink points to full-resolution original

**Purpose**: 
- WebP provides good compression (smaller than JPEG)
- JPEG served as fallback for older browsers
- Format priority: WebP → JPEG (based on browser support and availability)
- Variants reduce bandwidth for mobile/small displays

### FAA Profile Transformation (On-Demand)

**Purpose**: Generate FAA WCPO (Weather Camera Program Office) compliant images for third-party integration.

**API Endpoint**:
```
GET /v1/airports/{id}/webcams/{cam}/image?profile=faa
```

**Behavior**:
1. Applies configurable crop margins (percentages) to exclude edge content (timestamps, watermarks)
2. Center-crops the safe zone to 4:3 aspect ratio
3. Quality-caps output: 1280x960 if source supports it, otherwise 640x480 (no upscaling)
4. Always outputs JPEG format

**Configuration** (see `CONFIGURATION.md#faa-profile-crop-margins`):
- Global default: `config.faa_crop_margins` (percentage-based margins)
- Per-webcam override: `webcams[].crop_margins`
- Built-in fallback: `{ top: 7, bottom: 4, left: 0, right: 4 }`

**Margin Calculation**:
- Percentages scale with source resolution (handles 720p to 4K sources)
- Example: 5% top margin = 54px on 1080p, 108px on 4K

**Caching**:
- FAA-transformed images cached as `{timestamp}_faa.jpg`
- Cached per-camera, invalidated when source image changes

**Quality-Capping Logic**:
```
Source (1920x1080) → Margins (5% top) → Safe Zone (1920x1026)
Safe Zone → Center-crop to 4:3 → 1368x1026
1368x1026 >= 1280x960? YES → Output 1280x960
Otherwise → Output 640x480 (FAA minimum)
```

**Module:** `lib/image-transform.php` - `transformImageFaa()`, `getFaaTransformedImagePath()`

### Webcam Metadata Caching

**Purpose**: Store and serve webcam metadata (timestamp, name, formats) efficiently.

**APCu Cache** (`lib/webcam-metadata.php`):
- Webcam metadata cached in APCu with 24-hour TTL
- Key format: `webcam_meta_{airportId}_{camIndex}`
- Stores: timestamp, name, available formats, variant information

**CLI/FPM Isolation Handling**:
- PHP CLI (scheduler) and PHP-FPM (web) have separate APCu memory pools
- When scheduler updates images, FPM's APCu cache may have stale metadata
- Solution: `getWebcamMetadata()` validates cached timestamp against latest file
- If cached timestamp doesn't match `getLatestImageTimestamp()`, cache is rebuilt
- Ensures frontend always displays accurate "Last Updated" times

**Metadata Retrieval Flow**:
1. Check APCu for cached metadata
2. Validate cached timestamp matches latest image file timestamp
3. If stale or missing, rebuild metadata from file
4. Store rebuilt metadata in APCu
5. Return metadata to caller

### Cache Serving

**Endpoint**: `/webcam.php?airport={id}&cam={index}`

**Request Types**:
1. **Image Request**: Returns JPEG or WebP image
2. **Timestamp Request**: `?mtime=1` returns JSON with file modification time

**Serving Logic**:
1. Check if explicit format parameter (`?fmt=webp`) → if generating, return HTTP 202
2. Check explicit format parameter → if ready, serve immediately (HTTP 200)
3. Check if format disabled but explicitly requested → return HTTP 400
4. Check Accept header for format preference (if no explicit `fmt=`) → serve best available (HTTP 200)
5. Check if all formats from same stale cycle → serve most efficient available (HTTP 200)
6. Fallback to JPEG (always available, HTTP 200)
7. If no formats available → serve placeholder (HTTP 200)

**HTTP 202 Response (Format Generating)**:
- Only returned for explicit `fmt=webp` requests
- Indicates format is actively generating in current refresh cycle
- Includes `Retry-After` header (5 seconds)
- Client can wait briefly or use fallback immediately
- Not returned for old cycles (generation failed) or no explicit format requests

**Format Priority**:
- Explicit `fmt` parameter (highest priority, may return 202 if generating)
- WebP (if browser supports via Accept header and enabled)
- JPEG (fallback, always available, always enabled)

**Refresh Cycle Detection**:
- Uses JPEG mtime vs refresh interval to determine if image is from current cycle
- Current cycle + format missing = generating (return 202)
- Old cycle + format missing = generation failed (return 200 with fallback)
- All formats from same stale cycle = serve most efficient available

**Cache Headers**:
- Fresh cache: `Cache-Control: public, max-age={remaining_seconds}`
- Stale cache: `Cache-Control: public, max-age={refresh_interval}, stale-while-revalidate={seconds}`
- Placeholder: `Cache-Control: public, max-age={placeholder_ttl}`
- 202 response: `Cache-Control: no-cache, no-store, must-revalidate, max-age=0`

**Client-Side Behavior**:
- HTML images: No `fmt=` parameter → always get HTTP 200 immediately (server respects Accept header)
- JavaScript refreshes: Explicit `fmt=` parameter → can get HTTP 202 if format generating
- Staggered refreshes: Random 20-30% offset to distribute load away from cron spike
- Format retry: Fixed 5-second backoff, max 10-second wait, silent timeout
- Cleanup: Cancels retries and timeouts on page unload

### Background Refresh

**Pattern**: Same stale-while-revalidate as weather

**Flow**:
1. Check cache age
2. If stale, serve immediately
3. Trigger background refresh (if not already refreshing)
4. Background refresh uses same fetch process with locking

**Locking**: Prevents multiple concurrent refreshes of same camera

---

## NOTAM Data Fetching

### Overview

NOTAM (Notice to Air Missions) data is fetched from the FAA's NMS (NOTAM Management System) API to provide pilots with critical airspace information including:
- **Aerodrome Closures**: Runway and airport closures (CLSD/CLOSED; hazard-only text excluded)
- **TFRs**: Temporary Flight Restrictions affecting the airport's airspace

### Fetching Strategy

The system uses a **dual query strategy** to capture both airport-specific NOTAMs and nearby TFRs:

1. **Location Query** (when an identifier is configured): Fetches NOTAMs for the airport via `notamResolveLocationQueryCode()` (ICAO, then IATA, then FAA)
2. **Geospatial Query** (if coordinates available): Fetches TFR-focused airspace NOTAMs with `feature=AIRSPACE`, then applies a cheap XML pre-filter before parse (closures still come from the location query)

Both queries are executed sequentially through `lib/notam/http.php`. A shared file-backed token bucket (`lib/notam/rate-limit.php`, `NOTAM_RATE_LIMIT_REQUESTS_PER_MINUTE` under the documented 60/min cap) paces outbound calls per `notam_api_client_id` + base URL. Workers wait for a token (up to `NOTAM_RATE_LIMIT_MAX_WAIT_SECONDS`, then fail open) rather than skipping the fetch.

On HTTP **429** or **503**, `lib/notam/circuit-breaker.php` records a shared `global_notam_{fingerprint}` pause for the credential. While active, location and geo queries defer without calling NMS. One in-fetch retry runs after a capped `Retry-After` wait (`NOTAM_429_RETRY_MAX_WAIT_SECONDS`) before the pause is extended.

**Observability**: NMS request outcomes increment counters in `cache/notam_health.json` via `lib/notam-health.php` (scheduler flush every 60 seconds). HTTP **429** responses increment `upstream_429` and per-endpoint `upstream_429_{location|geo|auth}` counters. On the status page, expand **NOTAM Data Fetching** to see per-endpoint 429 counts for the last hour.

### NMS API Authentication

The system uses OAuth bearer token authentication:
- Tokens are obtained from the NMS API using client credentials
- Tokens are cached and refreshed automatically before expiration (60-second buffer)
- Authentication failures prevent NOTAM fetching but don't affect other airport data

### Location-Based Query

**Purpose**: Fetches NOTAMs specifically issued for an airport.

**Endpoint**: `{base_url}/nmsapi/v1/notams?location={code}`

**Behavior**:
- Uses the first configured identifier from `notamResolveLocationQueryCode()`: ICAO, then IATA, then FAA (covers FAA-only fields such as `03S`)
- Does **not** query `formerly` codes; those are used only when matching NOTAM `location` fields during filtering
- Returns NOTAMs with the airport as the affected location
- Effective for aerodrome closures and airport-specific restrictions

### Geospatial Query

**Purpose**: Fetches NOTAMs affecting airspace near the airport, particularly TFRs.

**Endpoint**: `{base_url}/nmsapi/v1/notams?latitude={lat}&longitude={lon}&radius={nm}&feature=AIRSPACE`

**Behavior**:
- Uses airport coordinates from configuration
- Default radius: 10 NM (`NOTAM_GEO_RADIUS_DEFAULT`)
- NMS `feature` filter: `NOTAM_GEO_QUERY_FEATURE` (default `AIRSPACE`) to reduce non-TFR payload size
- Raw AIXM rows are pre-filtered with `notamFilterGeoXmlForTfrParsing()` (same TFR keywords as `isTfr()`) before XML parse; runway/obstacle rows from geo are skipped intentionally
- When NMS returns geo rows but the pre-filter drops all of them, the fetch still counts as a successful geo query (HTTP 200) and logs `notam fetcher: geo prefilter dropped all AIXM rows` for ops visibility
- **Important**: The API returns NOTAMs by ARTCC (Air Route Traffic Control Center), not strict geographic proximity. A TFR in the same ARTCC may be returned even if outside the specified radius.

### Response Format

The NMS API returns NOTAMs in AIXM 5.1.1 XML format embedded within a JSON wrapper:
- `data.aixm`: Array of AIXM XML strings, one per NOTAM
- When there are no NOTAMs, NMS may omit `data.aixm` and return `{"status":"Success","data":{}}`
- `notamExtractAixmRowsFromNmsResponse()` treats a missing or null `data.aixm` as an empty row list
- Each XML string contains the full NOTAM details

---

## NOTAM Data Processing

### Parsing Flow

1. **XML Parsing**: Each AIXM XML string is parsed using SimpleXML
2. **Field Extraction**: Key fields are extracted from the XML structure:
   - `id`: Public NOTAM identifier. ICAO format uses series + number + year (e.g., `A1234/2026`). DOM/local NOTAMs without a series use `event:simpleText` (e.g., `06/001/2026` from `!SPB 06/001 ...`).
   - `type`: N (New), R (Replace), or C (Cancel)
   - `location`: ICAO location code (may be FIR/ARTCC code for TFRs)
   - `code`: Q-code / selection code when present (often empty on FAA scenario 86 runway closures)
   - `scenario`: FAA NMS scenario (runway closure is `86`, see `NOTAM_FAA_SCENARIO_RUNWAY_CLOSURE`)
   - `aixm_runway_event`: True when the AIXM payload includes a runway member
   - `text`: Full NOTAM text
   - `start_time_utc`: Effective start time
   - `end_time_utc`: Effective end time (null for permanent NOTAMs)
   - `airport_name`: Airport name from FAA extension fields
3. **Deduplication**: NOTAMs are deduplicated by public `id`, or by a location/start/text fingerprint when `id` is empty (DOM format)

### Relevance Filtering

Not all returned NOTAMs are relevant to the airport. The system filters for two types:

#### Cancellation NOTAMs (Excluded)

**Cancellation NOTAMs are excluded** from display because they indicate a restriction has been **lifted** (good news, not a warning). A NOTAM is identified as a cancellation if:
- **Type field**: `type='C'` (Cancel) in the parsed NOTAM data
- **Text contains**: `NOTAMC` (NOTAM Cancel identifier)
- **Text ends with**: "CANCELED" or "CANCELLED"

Example: `A0261/26 NOTAMC A0248/26 ... RWY 10R/28L CLSD CANCELED` means the runway closure from NOTAM A0248/26 is **canceled** (runway is now open).

#### Aerodrome Closures

A NOTAM is classified as an aerodrome closure if (and not a cancellation). Scope must be runway or aerodrome level (`notamRestrictionScopeIsRunwayOrAerodrome()` in `lib/notam/filter.php`):

- **Q-code path**: Code starts with `QMR` (runway) or `QFA` (aerodrome)
- **FAA AIXM path**: `scenario` is `86` (`NOTAM_FAA_SCENARIO_RUNWAY_CLOSURE`) and/or `aixm_runway_event` is true (DOM runway closures often omit Q-code)
- **Text fallback** (only when `code` is empty): Phrases such as `RWY ... CLSD`, `AD AP CLSD`, or `ARPT/AIRPORT ... CLSD`. Taxiway-only closures (`TWY`, `APRON`, `RAMP`) are excluded. Explicit `QMX`/`QMA`/`QMP` Q-codes are always excluded.

Additionally:
- **Text indicates closure**: Contains `CLSD` or `CLOSED` (not hazard-only phrases such as `UNSAFE` or `HAZARD`)
- **Location matches**: The NOTAM location matches the airport's ICAO, IATA, FAA code, or historical identifiers (or airport name fallback for geo query rows)

#### TFR Detection

A NOTAM is classified as a TFR if (and not a cancellation) its text contains any of:
- "TFR" (explicit abbreviation)
- "TEMPORARY FLIGHT RESTRICTION" (full phrase)
- Both "RESTRICTED" and "AIRSPACE" (combined indicators)

### TFR Geographic Relevance

**Design context**: The NMS API can return TFR NOTAMs for a broad airspace region (for example the same ARTCC as the airport). `lib/notam/filter.php` applies geographic rules so dashboard banners align with the airport position and the geometry described in the NOTAM text.

A TFR is treated as relevant to an airport when any of these holds:

1. The NOTAM `location` field matches an airport identifier (ICAO, IATA, FAA, or historical `formerly` codes).
2. The NOTAM `airport_name` field matches the airport name (word-boundary rules prevent substring false positives).
3. The TFR text explicitly mentions the airport name or an identifier (same word-boundary rules).
4. **Geographic geometry** applies: the airport has configured `lat`/`lon`, and the case falls into one of the paths below.

#### Coordinate parsing (all paths)

All coordinate pairs use the same pattern scan over the NOTAM `text` field. Each decoded pair must use **minutes and seconds in 0--59**, **latitude degrees 0--90** (no fractional overflow beyond the pole), **longitude degrees 0--180** with the same pole rule at 180°, and **N/S/E/W** hemispheres; otherwise the pair is dropped so bad text cannot silently move geometry.

- **Format**: `DDMMSSN/S` then `DDDMMSSW/E` (e.g., `413900N1122300W`)
- **Meaning**: Degrees, minutes, seconds with hemisphere indicator
- **Example**: `413900N1122300W` = 41°39'00"N, 112°23'00"W

#### Radius parsing (circle TFR path only)

When any of these patterns matches, the NOTAM is treated as a **circle** TFR for geometry (first coordinate pair in document order is the center):

- `5NM RADIUS` or `5 NM RADIUS`
- `RADIUS OF 5NM`
- `WITHIN 5NM` (numeric nautical miles)
- `5 NAUTICAL MILE RADIUS`

Parsed values must lie between `TFR_RADIUS_MIN_NM` and `TFR_RADIUS_MAX_NM` in `lib/constants.php`.

#### Circle TFR geometry

For a circle TFR, **haversine** distance in nautical miles is computed between the airport and the circle center. The TFR is relevant when:

```
distance NM ≤ parsed radius NM
```

Parsed circle geometry follows the published restriction only; `TFR_RELEVANCE_BUFFER_NM` applies to **polygon** edges (see below), not to expanding a stated circle radius.

#### Polygon TFR geometry

When **no** circle radius is parsed and **three or more** distinct vertices remain after parsing, the NOTAM uses the **polygon** path:

- **Closed ring requirement**: The ring is valid only if the first and last decoded vertices coincide (after dropping consecutive duplicates), **or** the text contains the phrase **POINT OF ORIGIN** with at least three vertices (implicit closure to the first vertex). Otherwise geographic relevance is **not** applied for the polygon (fail closed).
- **Plane and units**: Vertices and the airport are projected to a local east/north plane in **nautical miles** using the mean vertex latitude as the tangent reference. This matches small CONUS incident polygons; `cos(latitude)` near zero rejects the projection (fail closed).
- **Non-degenerate ring**: The absolute signed shoelace double-area in that plane must be at least `TFR_POLYGON_MIN_ABS_DOUBLE_AREA_NM2` in `lib/notam/filter.php`. Smaller values are treated as a degenerate ring and excluded.
- **Inside or near boundary**: A **ray-casting** point-in-polygon test runs in the projected plane. If the airport is outside the ring, the shortest distance to any polygon edge (NM in that plane) must be **≤ `TFR_RELEVANCE_BUFFER_NM`** for relevance.

Inside the polygon, relevance does **not** depend on distance to a single center point; any interior position matches.

#### Legacy point TFR (no radius, fewer than three vertices)

When no radius is parsed and fewer than three vertices are available, the first coordinate is used as a center with **`TFR_DEFAULT_RADIUS_NM`** (30 NM) and haversine distance with **no** NM buffer beyond that default disk. This covers sparse coordinate text that is not a closed polygon.

#### Conservative filtering (safety-critical)

Geographic relevance is **withheld** when coordinate geometry cannot be established, a polygon ring is not provably closed, or the projected polygon is degenerate. Missing airport coordinates also skip geometry (identifier and text rules may still match). Omitting uncertain geometry avoids showing distant or mis-scoped TFR banners that could reduce trust in the NOTAM strip.

### Status Classification

Each NOTAM is classified by temporal status (`classifyNotamDisplayStatusAt()` in `lib/notam/filter.php`):
- **active**: Inside an effective window (or envelope when no segments)
- **inactive_scheduled**: Inside the NOTAM envelope but between EFFECTIVE windows
- **upcoming_today**: First window starts later today (airport local midnight boundaries)
- **upcoming_future**: First window starts after today
- **expired**: Past the last effective end
- **unknown**: Missing or invalid start time

**Cache filter** (`notamIsBannerRelevantStatus()`): retains `active`, `inactive_scheduled`, `upcoming_today`, and `upcoming_future` rows whose first window is within `NOTAM_BANNER_UPCOMING_FUTURE_HORIZON_SECONDS` (48 hours).

**Serve filter** (`api/notam.php`): drops `expired` and `unknown`; returns the same banner-eligible statuses as the cache (re-validated at request time). Serve-time **event deduplication** (`deduplicateBannerNotams()` in `lib/notam/banner.php`) merges paired NOTAM rows for the same restriction (for example A-series and numeric ids for one fire TFR) before the API responds; there is no row cap after dedup.

**Dashboard banners** (`pages/airport.php`): red **ACTIVE**, purple **SCHEDULED BREAK**, orange **UPCOMING** for `upcoming_today` and `upcoming_future`.

### Caching

Filtered NOTAMs are cached per airport:
- **Location**: `cache/notam/{airport_id}.json` (via `notamCacheFilePath()`)
- **Content**: Array of filtered NOTAMs with status
- **Refresh**: Configurable via `notam_refresh_seconds` (default: 600 seconds / 10 minutes). The scheduler staggers per-airport due times across the refresh window and starts at most `NOTAM_SCHEDULER_MAX_ENQUEUE_PER_LOOP` (default 1) new NOTAM worker per scheduler tick so NMS traffic stays near 1 req/s without bursting when many airports become due together.
- **Atomic writes**: `notamWriteCacheFile()` writes a temp file then renames into place
- **Refresh failure backoff**: `scripts/fetch-notam.php` records `cache/notam/{airport_id}.fetch-attempt` (without changing cache payload or `mtime`) when a worker refresh fails so `notamShouldEnqueueRefresh()` backs off for `NOTAM_FETCH_FAILURE_BACKOFF_SECONDS` before re-enqueueing. This applies to:
  - **Hard NMS failure**: every attempted query fails (missing credentials, HTTP error, invalid JSON, or malformed `data.aixm`) - existing cache is preserved instead of overwriting with an empty result
  - **Worker exception**: uncaught error during fetch/parse/filter
  - **Cache write failure**: NMS succeeded but `notamWriteCacheFile()` could not persist (existing cache file, if any, is left unchanged)
- **HTTP 200 with no NOTAMs**: missing, null, or empty `data.aixm` is a successful fetch; cache is updated (including to an empty `notams` list). Filtered-to-zero rows (for example cancellations) also update the cache when raw AIXM was present.

### Serve-Time Status Re-validation

**Safety-critical**: NOTAMs may expire between cache time and serve time. The API re-validates each NOTAM's status at serve time:

1. **Status recompute**: `revalidateNotamStatus()` runs segment-aware classification at request time
2. **Banner filter**: `notamIsBannerRelevantStatus()` drops `expired` and `unknown`, and re-applies the 48-hour `upcoming_future` horizon at serve time
3. **Timezone alignment**: Uses airport local timezone for today vs future buckets

This ensures pilots never see expired NOTAMs, even if the cache has not refreshed yet. The airport timezone alignment ensures consistent behavior with the initial status determination.

### Failclosed Behavior

**Safety-critical**: If the NOTAM cache is too old, the system fails closed (returns empty rather than stale data).

**3-Tier Staleness Model** (defaults in `lib/constants.php`; override with `notam_stale_*_seconds` in config):
- **Warning** (default 900s / 15 min): Logs stale cache; scheduler refresh on next cycle
- **Error** (default 1800s / 30 min): Same serve behavior; stronger operational signal in logs
- **Failclosed** (default 3600s / 1 hr): Returns empty NOTAM array, logs warning

Note: `api/notam.php` sets `refreshing: true` when cache exceeds TTL; actual NMS refresh is performed by `scripts/fetch-notam.php` via the scheduler, not inline on the HTTP request.

When failclosed:
- Response includes `failclosed: true` flag
- `failclosed_reason` explains why data was withheld
- Better to show no NOTAMs than potentially outdated restriction info

### API Response

The `/api/notam.php` endpoint serves cached NOTAM data:
- Loads cached data (or returns empty if no cache or failclosed)
- Re-validates NOTAM status at serve time (filters expired)
- Converts UTC times to airport local timezone for display
- Adds official FAA NOTAM links for each NOTAM
- Returns JSON array of formatted NOTAMs

### Airports directory TFR map layer

The airports network map (`pages/airports.php`, served at `airports.aviationwx.org`) draws geo-relevant TFRs from an aggregated GeoJSON layer. This path is separate from per-airport dashboard banners: banners list every deduplicated event row; the map shows one drawable shape per geometry.

**Build** (`notamTfrMapLayerBuildPayload()` in `lib/notam/map-layer.php`; cache orchestration in `lib/notam/map-layer-cache.php`):

1. Read each listed airport NOTAM cache (`cache/notam/{airport_id}.json`).
2. Keep TFR rows where `classifyNotamDisplayStatusAt()` is not `expired` or `unknown`.
3. Skip duplicate global NOTAM `id` values while scanning caches.
4. Emit a GeoJSON Feature per row: polygon outer ring from decoded vertices, or a Point + `radius_nm` for circle TFRs (client draws `L.circle` in meters).
5. **Geometry deduplication** (`notamTfrMapLayerDeduplicateFeaturesByGeometry()`): features that share the same drawable geometry key collapse to one feature. When keys collide, keep the highest-priority status:

   | Rank (`NOTAM_TFR_MAP_STATUS_PRIORITY`) | Status |
   |----------------------------------------|--------|
   | 0 (wins) | `active` |
   | 1 | `inactive_scheduled` |
   | 2 | `upcoming_today` |
   | 3 | `upcoming_future` |

   Lower rank wins (`notamTfrMapLayerStatusPriority()`).

   Equal-priority ties break on lower `notam_id` (lexicographic). This is **not** the dashboard banner event fingerprint (`notamBannerEventFingerprint()`): map keys use rounded circle center + radius, or polygon ring vertices in ring order, so paired A/numeric NOTAMs and same-shape upcoming series merge for display only.

**Style bucket** (`notamTfrMapLayerStyleBucket()`): `active` (red stroke/fill) only when status is `active`; all other retained statuses map to `upcoming` (amber), including `inactive_scheduled`.

**Serve** (`notamTfrMapLayerServeOrRebuild()`):

1. **Shared disk cache** (`cache/notam/tfr-map-layer.json`, `lib/notam/map-layer-cache.php`) stores drawable geometry and `map_layer_build_token` (`{deploy SHA}-v{N}` from {@see getGitSha()} and {@see NOTAM_TFR_MAP_LAYER_LOGIC_VERSION}, or `logic-v{N}` when SHA is unavailable). Rebuild when the aggregate file is missing or invalid, older than `getNotamCacheTtlSeconds()`, any listed per-airport NOTAM cache file is newer than the aggregate, the build token no longer matches (code deploy or logic-version bump), or the aggregate has no features while listed sources contain drawable TFR geometry (empty-aggregate gap when source mtimes did not advance).
2. **Single-flight rebuild**: `flock()` on `tfr-map-layer.rebuild.lock` so concurrent requests do not all parse geometry; waiters serve the existing aggregate when present.
3. **Serve-time status revalidation** (`notamTfrMapLayerRevalidatePayload()` in `map-layer-cache.php`): one pass over listed airport caches, then `revalidateNotamStatus()` and `isTfr()` parity with `api/notam.php`, refresh `map_layer_style` / `status_line`, drop expired rows, and re-apply geometry dedup.
4. **HTTP cache**: `GET /api/notam-map.php` sends `Cache-Control` with `NOTAM_API_CACHE_TTL_SECONDS` / `NOTAM_API_CACHE_SWR_SECONDS` (same as `api/notam.php`). JSON `cache_ttl_seconds` is the disk/client poll TTL (`getNotamCacheTtlSeconds()`), not the HTTP window.

Production access is browser-only (`lib/notam/map-api-access.php`).

**Safety**: Geometry dedup prefers the currently active restriction so an overlapping upcoming NOTAM cannot mask an active TFR on the directory map.

---

## Data Display on Dashboard

### Airport "Last updated": observation time vs fetch time

**Safety-critical distinction**: The airport page line that tells the pilot **when the weather snapshot reflects conditions** must use **observation time** (when the METAR was issued or the sensor reading was taken), not **server fetch time** (when our cache last retrieved data). Showing fetch time can make conditions look newer than they are and mislead go/no-go decisions.

**Pipeline fields** (from `WeatherAggregator` and `normalizeAggregateLastUpdatedTimes` in `lib/weather/aggregate-timestamps.php`):

| Role | Fields |
|------|--------|
| Observation | `obs_time_primary`, `obs_time_metar`, per-field `_field_obs_time_map` |
| Fetch / cache | `last_updated`, `last_updated_primary`, `last_updated_metar` |

**Two policies on purpose**:

1. **Aggregate JSON `last_updated` / `last_updated_iso`**: PHP computes a **maximum** across observation and fetch candidates so the payload carries a single "freshest metadata" stamp for staleness and APIs. That max can be driven by fetch time when it is newer than observation metadata.
2. **Human "Last updated" on the airport dashboard**: The browser uses `pickObservationUnixTimestamp` and `lastUpdatedDateFromWeather` in `public/js/weather-timestamp-utils.js`, which take the **maximum of observation candidates only**, then fall back to fetch times **only if** no observation metadata exists. `pickWeatherUnixTimestamp` (max of all candidates) is for diagnostics only and must not drive the pilot-facing line.

**Invariant:** When observation metadata exists, the dashboard must not show a fetch time that is newer than the underlying observation time. Automated tests in `tests/js/weather-timestamp-utils.test.js` enforce this split.

### Weather Data Display

#### Flight Category Display
- **Location**: Top of weather section
- **Format**: Category name (VFR/MVFR/IFR/LIFR) with emoji
- **Color Coding**: CSS class `status-{category}` (lowercase)
- **Timestamp**: Shows observation time for visibility/ceiling (if METAR available)

#### Temperature Display
- **Current Temperature**: 
  - Value in selected unit (°C or °F)
  - Displayed with one decimal place (e.g., "72.5°F" or "22.3°C")
  - No timestamp (current reading)
- **Today's High**:
  - Highest temperature observed today
  - Displayed with one decimal place
  - Timestamp showing when high was observed
  - Resets at local midnight
- **Today's Low**:
  - Lowest temperature observed today
  - Displayed with one decimal place
  - Timestamp showing when low was observed
  - Resets at local midnight

#### Wind Display
- **Wind Speed**: User-selectable unit (knots, mph, or km/h)
  - Default: Knots (kts)
  - Toggle cycles: kts → mph → km/h → kts
  - Preference stored in cookies/localStorage
- **Wind Direction**: Degrees (or "VRB" if variable)
- **Gust Speed**: Same unit as wind speed (user-selected)
- **Gust Factor**: Additional speed from gusts (same unit)
- **Visual**: Wind rose/compass with current wind arrow and last-hour petal distribution
- **Peak Gust Today**:
  - Highest gust observed today (displayed in user-selected unit)
  - Timestamp showing when peak occurred
  - Resets at local midnight

#### Moisture Display
- **Dewpoint**: Temperature in selected unit
  - Displayed with one decimal place (e.g., "65.2°F" or "18.4°C")
- **Dewpoint Spread**: Temperature minus dewpoint
  - Displayed with one decimal place
- **Humidity**: Percentage (0-100%)

#### Aviation Conditions (METAR Data)
- **Visibility**: Statute miles (or km if metric)
  - Timestamp from METAR observation time
- **Ceiling**: Feet AGL (or meters if metric)
  - "Unlimited" if no ceiling (FEW/SCT clouds)
  - Timestamp from METAR observation time
- **Cloud Cover**: FEW/SCT/BKN/OVC (if available)

#### Pressure and Altitude
- **Pressure**: Altimeter setting in inHg
- **Pressure Altitude**: Calculated from elevation and pressure
- **Density Altitude**: Calculated from elevation, pressure, and temperature

#### Precipitation and Daylight
- **Rainfall Today**: Inches (or cm if metric)
  - Daily accumulation (resets at local midnight)
- **Sunrise / Sunset**: Local time in airport timezone
  - Computed by `lib/sun/SunCalculator.php` (NOAA formulas)
  - See [Sun Calculations](#sun-calculations) for details
- **Night Mode**: Auto-switches on mobile after evening civil twilight until morning civil twilight (FAA definition)

### Webcam Display

#### Image Display
- **Format**: JPEG or WebP (based on browser support)
- **Refresh**: Automatic refresh based on cache age
- **Loading State**: Shown during fetch
- **Stale Overlay**: When image exceeds fail-closed threshold but history has frames, shows last image dimmed with overlay "Live image unavailable. Tap for time-lapse history." and timestamp; user can tap to open history player
- **Placeholder**: Shown when no image exists or history unavailable (no frames, history disabled)

#### Timestamp Display
- **Last Updated**: Shows when image was captured
- **Format**: Relative time ("2 minutes ago") or absolute time
- **Update Frequency**: Checks timestamp periodically
- **Staleness Warning**: Warning emoji (⚠️) appears when webcam age exceeds `MAX_STALE_HOURS` (3 hours)

### NOTAM Display

#### Banner Display
- **Location**: `#notam-banner-container` below status banners on the airport page
- **Visibility**: Shown when `api/notam.php` returns `active`, `inactive_scheduled`, `upcoming_today`, or `upcoming_future` rows
- **Types Displayed**:
  - **Aerodrome Closures**: Runway or airport closures
  - **TFRs**: Temporary flight restrictions that pass relevance rules (including circle, polygon, or legacy geometry in [TFR Geographic Relevance](#tfr-geographic-relevance))
- **Refresh**: Client polls `/api/notam.php` every 180 seconds

#### NOTAM Content
- **ID**: NOTAM identifier with link to official FAA source when `id` is present
- **Status labels**: ACTIVE NOTAM, SCHEDULED BREAK, or UPCOMING NOTAM
- **Headline**: Short parsed summary (`banner_headline`) plus schedule line; full NOTAM text remains below
- **Message**: Full NOTAM text (all deduplicated matching rows shown; no collapse)
- **Effective Times**: Envelope or segment window times in airport local timezone

#### Visual Indicators
- **Icons**: 🚨 active, ⏸️ scheduled gap, ⚠️ upcoming
- **Color Coding**: Red active, purple scheduled, orange upcoming (see `.notam-banner-*` in `public/css/styles.css`)

#### Airports directory map (TFR layer)

- **Location**: Leaflet map on `pages/airports.php` (`airports.aviationwx.org`)
- **Data**: `GET /api/notam-map.php` (aggregated GeoJSON; see [Airports directory TFR map layer](#airports-directory-tfr-map-layer))
- **Geometry**: Polygons or true-radius circles (`L.circle`); tap/click opens popup with NOTAM id, schedule line, vertical limits, FAA link
- **Styling**: Red active, amber upcoming (includes scheduled gaps between EFFECTIVE windows)
- **Refresh**: Client polls on the same cadence as NOTAM cache TTL

### Data Refresh Behavior

#### Automatic Refresh
- **Weather**: Refreshes every 60 seconds (or per-airport interval)
- **Webcam**: Refreshes when cache age exceeds refresh interval
- **Client-Side**: JavaScript polls for updates

#### Stale Data Handling
- **Indication**: "Stale" flag in response
- **Behavior**: Client schedules immediate refresh if data is stale
- **Display**: Data shown but marked as potentially outdated

#### Error Handling
- **Network Errors**: Retries with exponential backoff
- **API Errors**: Shows last known good data (if available)
- **Missing Data**: Fields show "--" or "---"
- **Placeholder Images**: Shown for unavailable webcams

### Unit Conversions

#### Centralized Conversion Libraries

All unit conversions use centralized libraries with verified conversion factors:

**PHP Library**: `lib/units.php`
- Contains all conversion constants (ICAO, FAA, BIPM sources)
- Functions: `celsiusToFahrenheit()`, `hpaToInhg()`, `knotsToMph()`, etc.
- Used by adapters for API response parsing
- Used by `WeatherReading::convertTo()` for runtime conversions

**JavaScript Library**: `public/js/units.js`
- Identical conversion factors as PHP library
- Used for client-side display conversions
- Namespace: `AviationWX.units.*`
- Example: `AviationWX.units.celsiusToFahrenheit(15)`

**TDD Verified**: All conversion factors verified with 70+ tests in:
- PHP: `tests/Unit/SafetyCriticalReferenceTest.php`
- JavaScript: `tests/js/unit-conversion.test.js`

#### Server-Side Conversions (API Adapters)

These conversions happen when parsing data from external APIs to standardize to internal format:

**Temperature**:
- Fahrenheit to Celsius: `°C = (°F - 32) / 1.8`
  - Used by: Ambient Weather, WeatherLink
  - Alternative form: `°C = (°F - 32) × 5/9`
- Celsius to Fahrenheit: `°F = (°C × 9/5) + 32`
  - Used for: Storing `temperature_f` and `dewpoint_f` fields
  - Applied to: Temperature, dewpoint

**Wind Speed**:
- Meters per second to knots: `kts = m/s × 1.943844`
  - Used by: Tempest WeatherFlow API
- Miles per hour to knots: `kts = mph × 0.868976`
  - Used by: Ambient Weather, WeatherLink
- Note: METAR provides wind in knots directly (no conversion)

**Pressure**:
- Millibars/hectopascals to inches of mercury: `inHg = mb / 33.8639`
  - Used by: Tempest WeatherFlow API, METAR API (aviationweather.gov)
  - Note: Ambient Weather provides pressure in inHg directly. METAR API returns altim in hPa (hectopascals/millibars) and requires conversion.
- **Safety Validation**: After aggregation, if pressure > 100 inHg (impossible value), the system automatically divides by 100 to correct unit issues.
  - Catches API responses in wrong units (hundredths of inHg, or Pascals instead of hectopascals)
  - Normal atmospheric pressure range is 28-32 inHg, so values > 100 indicate a unit conversion problem
  - Critical for flight safety: incorrect pressure causes dangerous pressure altitude miscalculations

**Weather Data Validation Layer**:

After aggregation, all weather fields pass through `validateWeatherData()` in `lib/weather/validation.php`. This defense-in-depth layer:

1. **Checks each field against climate bounds** - Validates temperature, pressure, humidity, wind, etc. are within physically possible ranges
2. **Nulls dangerously out-of-range values** - For safety-critical fields like pressure (>70 or <10 inHg) and temperature (>70°C or <-100°C), invalid values are nulled to prevent dangerous calculations
3. **Logs warnings for monitoring** - All out-of-bounds values are logged with field name, value, and reason
4. **Records validation metadata** - Issues are recorded in `_validation_issues` field for API consumers

This catches:
- API format changes (e.g., new API version returning different units)
- Sensor malfunctions (e.g., stuck or erratic readings)
- Unit conversion errors that slip through adapter-specific fixes

**Precipitation**:
- Millimeters to inches: `inches = mm × 0.0393701`
  - Used by: Tempest WeatherFlow, WeatherLink
  - Note: Ambient Weather provides precipitation in inches directly

**Time**:
- Milliseconds to seconds: `seconds = milliseconds / 1000`
  - Used by: Ambient Weather API (observation timestamp)

#### Client-Side Conversions (Display)

These conversions happen in the browser for user display preferences:

**Temperature**:
- Server provides both °C and °F (pre-calculated)
- Client toggles display unit (no calculation needed)
- Calculations use stored Celsius values
- Conversion formulas (for reference):
  - Celsius to Fahrenheit: `°F = (°C × 9/5) + 32`
  - Fahrenheit to Celsius: `°C = (°F - 32) × 5/9`
- Dewpoint spread conversion: Same as temperature (multiply by 9/5 for °F)

**Wind Speed**:
- Server stores wind speeds in knots (aviation standard)
- Client converts for display based on user preference
- Conversion formulas:
  - Knots to miles per hour: `mph = kts × 1.15078`
  - Knots to kilometers per hour: `km/h = kts × 1.852`
- Default unit: Knots (kts)
- User can toggle between: kts, mph, km/h
- Preference persists across sessions via cookies
- Applied to: Wind speed, gust speed, gust factor, peak gust today

**Distance (Visibility)**:
- Statute miles to kilometers: `km = SM × 1.609344`
- Kilometers to statute miles: `SM = km / 1.609344`
- Default unit: Statute miles (SM)
- User can toggle between: SM, km
- Preference stored in cookies

**Distance (Ceiling/Altitude)**:
- Feet to meters: `m = ft × 0.3048`
- Meters to feet: `ft = m / 0.3048` (or `ft = m × 3.28084`)
- Default unit: Feet (ft)
- User can toggle between: ft, m
- Preference stored in cookies
- Applied to: Ceiling, pressure altitude, density altitude

**Precipitation**:
- Inches to centimeters: `cm = in × 2.54`
- Centimeters to inches: `in = cm / 2.54`
- Default unit: Inches (in)
- User can toggle between: in, cm
- Preference stored in cookies
- Applied to: Rainfall today

---

## Airport country resolution (geometry aggregate)

This path is separate from weather, webcam, and NOTAM pipelines. It supplies **geometry-only** ISO 3166-1 alpha-2 hints for each airport coordinate for `loadConfig()` merge and downstream effective country / external link logic.

### Build (`scripts/refresh-airport-country-resolution.php`)

1. CLI sets `AVIATIONWX_SKIP_COUNTRY_RESOLUTION_MERGE` and calls `loadConfig()` so the worker does not merge a half-built aggregate.
2. Reads current `airports.json` from disk and computes **SHA-256** for `config_sha256` in the output.
3. Loads bundled Admin-0 polygons from `data/geo/ne_110m_admin_0_countries.geojson`, normalizes rings, and runs point-in-polygon per airport lat/lon.
4. Writes **`cache/airport_country_resolution.json`** atomically (temp file + `rename()`), including `version`, `generated_at`, `config_sha256`, `boundary_dataset`, and per-airport `iso_country` rows (geometry only).

### Merge (`loadConfig()` via `lib/airport-country-resolution-merge.php`)

- After config validates, the aggregate is read if present. Merge applies **only** when `config_sha256` matches the current file and `version` matches; otherwise merge is skipped, any existing `_country_resolution_geo_iso` keys are removed from the in-memory config (fail closed for auto geometry, including stale APCu rows).
- Matched rows set internal `_country_resolution_geo_iso` on each airport. Effective country for the UI and Public API follows the precedence chain documented in **CONFIGURATION.md** (operator `iso_country`, ICAO, FAA, geometry, address).

### Scheduler (`scripts/scheduler.php`)

- Evaluates whether to run the worker at **most once per hour** (`COUNTRY_RESOLUTION_SCHEDULER_CHECK_INTERVAL`), with an **immediate** evaluation on the first eligible loop iteration after startup (same pattern as other infrequent tasks: avoid work every 1s tick).
- Runs the worker when the aggregate is missing, unreadable, invalid, schema mismatch, **SHA mismatch** (any `airports.json` edit), or the aggregate file is **older than** `COUNTRY_RESOLUTION_AGGREGATE_MAX_AGE_SECONDS` (default **30 days**) so geometry-derived country is refreshed on a long cadence unless the config changed first.

### Cache cleanup (`scripts/cleanup-cache.php`)

- **Push FTP/SFTP inbox debris:** Same keep-list as above. Non-image debris is deleted when older than `config.cleanup_push_upload_debris_max_age_seconds` (default **3 hours**). **`scripts/cleanup-push-upload-debris.php`** runs **hourly** via cron; **`cleanup-cache.php`** still runs the same step daily as a backup.
- Layer-2 age cleanup for the aggregate uses a **90-day** threshold so a healthy file is not deleted while the 30-day policy still considers it usable between scheduler passes.

---

## Summary

This system provides a robust, fault-tolerant data pipeline that:

1. **Fetches** weather from multiple sources (primary + METAR) in parallel when possible
2. **Processes** raw API data into standardized format with unit conversions
3. **Calculates** aviation-specific metrics (altitudes, flight category, dewpoint)
4. **Tracks** daily extremes (temperature highs/lows, peak gusts) that reset at local midnight
5. **Handles** staleness gracefully by nulling old data and preserving last known good values
6. **Caches** data with stale-while-revalidate pattern for fast responses
7. **Fetches** webcam images from various protocols (MJPEG, RTSP, static)
8. **Displays** all data with proper timestamps, units, and formatting

The system prioritizes **safety** (accurate, timely data), **performance** (fast responses), and **reliability** (graceful degradation when sources fail). Policy rules for supplemental remote weather, staleness, and outage detection are stated as required behavior throughout this document; they are not tracked here as a delivery checklist.
