# Weather and Webcam Data Flow Documentation

This document describes how weather and webcam data is fetched, processed, calculated, transformed, and displayed in the AviationWX dashboard. This is written in human-readable format to serve as a reference for understanding the complete data pipeline.

## Table of Contents

1. [Weather Data Fetching](#weather-data-fetching)
2. [Weather Data Processing](#weather-data-processing)
3. [Weather Data Calculations](#weather-data-calculations)
4. [Weather Data Transformations](#weather-data-transformations)
5. [Weather Data Caching and Staleness](#weather-data-caching-and-staleness)
6. [Webcam Data Fetching](#webcam-data-fetching)
7. [Webcam Data Processing](#webcam-data-processing)
8. [Data Display on Dashboard](#data-display-on-dashboard)

---

## Weather Data Fetching

### Overview

Weather data is fetched from multiple sources and combined to provide a complete picture:
- **Primary Source**: Tempest, Ambient Weather, WeatherLink, PWSWeather.com, SynopticData.com, or METAR-only
- **METAR Supplement**: Aviation weather data (visibility, ceiling, cloud cover) from aviationweather.gov

### Fetching Strategy

The system uses two fetching strategies:

1. **Asynchronous Fetching** (Parallel Requests)
   - Used when both primary source and METAR are needed
   - Fetches both sources simultaneously using `curl_multi`
   - Faster overall response time
   - Used for: Tempest, Ambient Weather, PWSWeather.com, SynopticData.com sources
   - **Backup Source**: Fetched in parallel when primary exceeds 4x refresh interval (warm-up period)

2. **Synchronous Fetching** (Sequential Requests)
   - Used when async is not applicable
   - Fetches primary source first, then METAR if needed
   - Used for: WeatherLink (requires custom headers), METAR-only sources

### Backup Weather Source

**Purpose**: Provides redundancy when primary weather source becomes stale or fails.

**Activation Logic**:
- **4x Threshold (Warm-up)**: Backup fetching begins when primary age >= 4x refresh interval
- **5x Threshold (Activation)**: Backup data is ready when primary exceeds 5x refresh interval
- **Continuous Fetching**: Backup continues fetching on every cycle while primary is stale
- **Field-Level Fallback**: Each weather field uses the best available data from primary or backup

**Fetching Flow**:
1. Check if backup should be fetched (4x threshold or missing fields)
2. Fetch backup in parallel with primary and METAR (if applicable)
3. Parse backup data using same adapters as primary
4. Store backup timestamps (`last_updated_backup`, `obs_time_backup`)
5. Merge with primary on field-by-field basis

**Recovery Logic**:
- Primary must be healthy (fresh and valid) for 5 consecutive cycles before switching back
- Recovery counter resets to 0 on any staleness or invalid field
- Prevents rapid switching between sources (hysteresis)

### Primary Weather Sources

#### Tempest WeatherFlow API
- **Endpoint**: `https://swd.weatherflow.com/swd/rest/observations/station/{station_id}?token={api_key}`
- **Data Provided**:
  - Temperature (Celsius)
  - Humidity (%)
  - Pressure (mb, converted to inHg)
  - Wind speed (m/s, converted to knots)
  - Wind direction (degrees)
  - Gust speed (m/s, converted to knots)
  - Dewpoint (Celsius)
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

### METAR Supplementation

When a primary source is configured, METAR data is fetched to supplement:
- **Visibility**: Required for flight category calculation
- **Ceiling**: Required for flight category calculation
- **Cloud Cover**: Additional aviation context

METAR fetching logic:
1. Attempts primary `metar_station` first
2. If primary fails and `nearby_metar_stations` are configured, tries each nearby station sequentially
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

The new unified weather pipeline uses `WeatherAggregator` with `AggregationPolicy` to combine data from all configured sources:

1. **Source Priority** (highest to lowest):
   - Tempest
   - Ambient
   - WeatherLink
   - PWSWeather
   - SynopticData
   - METAR

2. **Field-Specific Rules**:
   - **Wind Group** (speed, direction, gust): Must come from single source as a complete unit
   - **Visibility, Ceiling, Cloud Cover**: METAR is always preferred when available
   - **Other Fields**: Freshest valid data wins among all configured sources

3. **Aggregation Process**:
   1. Fetch all configured sources in parallel
   2. Parse each response into `WeatherSnapshot` with per-field observation times
   3. For each field, select best value based on:
      - Is field from preferred source type?
      - Is data within max acceptable age for this source?
      - Is observation time newer than other sources?
   4. Build aggregated result with `_field_source_map` and `_field_obs_time_map`

4. **Data Classes**:
   - `WeatherSnapshot`: Complete weather state from one source
   - `WeatherReading`: Single field value with source and observation time
   - `WindGroup`: Grouped wind fields ensuring consistency

5. **Max Acceptable Ages** (per source type):
   - Tempest: 300 seconds (5 minutes)
   - Ambient/WeatherLink: 300 seconds (5 minutes)
   - PWSWeather: 600 seconds (10 minutes)
   - SynopticData: 900 seconds (15 minutes)
   - METAR: 7200 seconds (2 hours)


---

## Weather Data Calculations

### Temperature Conversions

**Celsius to Fahrenheit**:
- Formula: `°F = (°C × 9/5) + 32`
- Applied to: `temperature`, `dewpoint`
- Stored as: `temperature_f`, `dewpoint_f`
- Note: This conversion is performed server-side when storing data (see [Server-Side Conversions](#server-side-conversions-api-adapters))

### Dewpoint Calculations

**From Temperature and Humidity** (Magnus Formula):
- Used when dewpoint not provided by source
- Formula: `gamma = ln(humidity/100) + (b × tempC) / (c + tempC)`
- `dewpoint = (c × gamma) / (b - gamma)`
- Constants: a=6.1121, b=17.368, c=238.88

**Humidity from Dewpoint** (Reverse Magnus):
- Used when humidity not provided but dewpoint is
- Formula: `humidity = (e / esat) × 100`
- Where `e` and `esat` calculated using Magnus formula

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
- Preserved as string "VRB" for display
- Wind visual may show different representation

### Pressure Altitude

**Formula**: `Pressure Altitude = Station Elevation + (29.92 - Altimeter) × 1000`

**Purpose**: Indicates aircraft performance at current pressure
- Higher pressure altitude = reduced aircraft performance
- Used for takeoff/landing distance calculations

**Requirements**: 
- Station elevation (from airport config)
- Altimeter setting (from weather data, in inHg)

### Density Altitude

**Formula**: 
1. Calculate pressure altitude first
2. `Standard Temp (°F) = 59 - (0.003566 × elevation)`
3. `Actual Temp (°F) = (tempC × 9/5) + 32`
4. `Density Altitude = Elevation + (120 × (Actual Temp - Standard Temp))`

**Purpose**: Accounts for both pressure AND temperature effects
- Higher density altitude = significantly reduced aircraft performance
- Critical for hot/high altitude operations

**Requirements**:
- Station elevation
- Temperature (Celsius)
- Pressure/Altimeter (inHg)

### Flight Category Calculation

**Purpose**: Categorizes flight conditions (VFR, MVFR, IFR, LIFR) based on visibility and ceiling

**Categories** (FAA Standard):
- **LIFR** (Low Instrument Flight Rules): Visibility < 1 SM OR Ceiling < 500 ft
- **IFR** (Instrument Flight Rules): Visibility 1-3 SM OR Ceiling 500-999 ft
- **MVFR** (Marginal VFR): Visibility 3-5 SM OR Ceiling 1,000-2,999 ft
- **VFR** (Visual Flight Rules): Visibility > 3 SM AND Ceiling ≥ 1,000 ft

**Calculation Logic**:
1. Categorize visibility separately
2. Categorize ceiling separately
3. Use **worst-case rule**: Most restrictive category wins
4. **Exception**: VFR requires BOTH conditions to be VFR (or better)
   - If visibility is VFR but ceiling is unknown/unlimited, assume VFR
   - If ceiling is VFR but visibility unknown, assume MVFR (conservative)

**Special Cases**:
- Unlimited ceiling (null) = no ceiling restriction = VFR for ceiling
- Missing visibility = cannot determine category (may use ceiling only)
- Missing both = null category

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
- Stored in `cache/temp_extremes.json` and `cache/peak_gusts.json`
- File locking prevents race conditions during concurrent updates
- Old entries (>2 days) automatically cleaned up

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
- **Primary Weather**: Reads from `cache/weather_{airport_id}.json`
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

**Purpose**: Warn users when all data sources are offline (complete site outage)

**Outage Threshold**: 1.5 hours (configurable via `DATA_OUTAGE_BANNER_HOURS`)

**Detection Logic**:
- Uses shared `getSourceTimestamps()` function to extract timestamps from all configured sources
- Checks all configured data sources (primary weather, METAR, webcams)
- Banner appears only when **ALL** sources exceed the threshold
- Banner shows newest timestamp among all stale sources to identify outage start time
- Banner automatically hides when any source recovers

**Outage State File Persistence**:
- Creates `cache/outage_{airport_id}.json` when outage is first detected
- Preserves original outage start time across brief recoveries (grace period: 1.5 hours)
- Handles back-to-back outages as single continuous event
- Automatically cleans up after full recovery (grace period expires)
- Logs outage start and end events for operational visibility

**Fallback Chain for Outage Start Time**:
1. Existing outage state file (preserves original start time)
2. Newest timestamp from stale sources (via `getSourceTimestamps()`)
3. Webcam cache file modification times (if weather cache is lost)
4. Current time (final fallback)

**Display Behavior**:
- Red banner at top of page (similar to maintenance banner)
- Only shown when airport is **NOT** in maintenance mode
- Message indicates data is stale and cannot be trusted
- Includes newest data timestamp to help identify when outage started

**Frontend Updates**:
- Client-side checks every 30 seconds for immediate feedback
- Server-side API checks every 2.5 minutes (`/api/outage-status.php`) to sync with backend state
- Banner updates automatically when data recovers
- Timestamp display updates every 60 seconds

**Webcam Staleness Warning**:
- Individual webcam timestamps show warning emoji (⚠️) when age exceeds `MAX_STALE_HOURS` (3 hours)
- Warning appears before timestamp in "Last updated:" label
- Provides immediate visual feedback for stale webcam data

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

**Location**: `cache/weather_{airport_id}.json`

**Content**: Complete weather data object with all fields:
- Raw measurements (temperature, wind, etc.)
- Calculated values (dewpoint spread, altitudes, etc.)
- Daily tracking (temp_high_today, peak_gust_today, etc.)
- Timestamps (last_updated, obs_time_primary, etc.)
- Flight category and CSS class

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

Webcam images are fetched from various source types and cached as JPEG files. The system supports multiple protocols and formats.

### Source Types

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
  - Validates JPEG format
  - Saves directly to cache

#### 4. Static PNG
- **Detection**: URL ends with `.png`
- **Fetch Method**: 
  - Downloads PNG image
  - Converts to JPEG using GD library
  - Quality: 85%
  - Saves to cache

#### 5. Push Type (Not Fetched)
- **Type**: `type: 'push'` or has `push_config`
- **Behavior**: Skipped by fetch script (images pushed by external system)
- **Upload Sources**:
  - **Direct camera uploads**: Cameras upload via SFTP/FTP/FTPS with local time EXIF
  - **Bridge uploads**: AviationWX-Bridge uploads with UTC EXIF and marker in UserComment
- **Supported Upload Formats**: JPEG, PNG, WebP, AVIF
- **Processing**:
  - PNG always converted to JPEG (we don't serve PNG)
  - Original format preserved for JPEG, WebP, AVIF (no redundant conversion)
  - Missing formats generated in background (JPEG, WebP, AVIF)
  - Mtime synced to match source image's capture time
- **Timestamp Handling**:
  - Bridge uploads: EXIF `DateTimeOriginal` interpreted as UTC
  - Direct uploads: EXIF `DateTimeOriginal` interpreted as local time
  - Detection via "AviationWX-Bridge" marker in EXIF UserComment

### Source Type Detection

**Order**:
1. Check `type` field in camera config (if present)
2. Check URL protocol (RTSP/RTSPS)
3. Check file extension (.jpg, .jpeg, .png)
4. Default to MJPEG

### Fetch Process Flow

1. **Lock Acquisition**
   - File-based lock prevents concurrent fetches of same camera
   - Lock file: `/tmp/webcam_lock_{airport_id}_{cam_index}.lock`
   - Timeout: 5 seconds
   - Stale locks (> worker timeout + 10s) automatically cleaned

2. **Cache Age Check**
   - Check if cached image exists and age
   - If cache age < refresh interval, skip fetch
   - Refresh interval: Per-camera `refresh_seconds`, or airport default, or global default

3. **Circuit Breaker Check**
   - Checks if camera is in backoff period
   - Skips fetch if circuit breaker open
   - Error severity affects backoff duration

4. **Source-Specific Fetch**
   - Calls appropriate fetch function based on source type
   - Handles errors and retries
   - Records success/failure for circuit breaker

5. **Image Validation**
   - Verifies file exists and has content
   - Checks file size > 0
   - Validates JPEG format (if GD available)
   - **Uniform Color Detection**: Rejects solid color images (lens cap, dead camera, corruption)
   - **Pixelation Detection**: Rejects severely pixelated images using Laplacian variance
     - Uses phase-aware thresholds (day/twilight/night)
     - Night images use more lenient thresholds (naturally softer)
   - **Blue Iris Error Detection**: Rejects error frames with grey borders and white text
   - **EXIF Timestamp Validation**: Rejects images with invalid/missing timestamps
     - Server-generated images (RTSP/MJPEG) have EXIF added immediately after capture
     - Push camera images must have camera-provided EXIF

6. **Format Generation** (if successful)
   - Generates WebP and AVIF formats for modern browsers
   - Uses ffmpeg with quality settings
   - Runs asynchronously (non-blocking) using `exec() &`
   - Automatically syncs mtime to match source image's capture time
   - All formats cached: `.jpg`, `.webp`, and `.avif`

7. **Lock Release**
   - Releases file lock
   - Cleans up lock file

### Error Handling

**Failure Recording**:
- Records failure with severity (transient/permanent)
- Updates circuit breaker state
- Logs error details

**Error Classification** (RTSP only):
- Timeout, connection, DNS → transient
- Authentication, TLS → permanent

**Fallback Behavior**:
- Failed fetch: Serves stale cache if available
- No cache: Serves placeholder image
- Placeholder: 1x1 transparent PNG or placeholder.jpg if available

---

## Webcam Data Processing

### Image Caching

**Cache Location**: `cache/webcams/{airport_id}_{cam_index}.{ext}`

**File Naming**: `{airport_id}_{cam_index}.{ext}`
- Example: `kspb_0.jpg`, `kspb_0.webp`, `kspb_0.avif`
- Formats: JPEG (`.jpg`), WebP (`.webp`), AVIF (`.avif`)

**Atomic Writes**:
- Writes to temporary file first: `{cache_file}.tmp.{pid}.{timestamp}.{random}`
- Validates write success
- Atomic rename to final filename
- Prevents corruption from concurrent writes

### Format Generation

**Multi-Format Support**:
- System generates JPEG, WebP, and AVIF formats from source images (if enabled in config)
- Format generation is globally configurable via `webcam_generate_webp` and `webcam_generate_avif` flags
- Default: Formats disabled (only JPEG generated) to control resource usage
- All generation runs asynchronously (non-blocking) using `exec() &` with `nice -n -1` priority
- Mtime automatically synced to match source image's capture time (EXIF or filemtime)
- Formats generated in background, may not be immediately available
- Generation jobs are logged (start and result) for monitoring and troubleshooting

**JPEG to WebP**:
- Uses ffmpeg: `nice -n -1 ffmpeg -i input.jpg -frames:v 1 -q:v 30 -compression_level 6 output.webp`
- Quality: 30 (0-100 scale, higher = better quality)
- Compression: Level 6 (0-6 scale)
- Priority: `nice -n -1` (background job, doesn't interfere with main site rendering)
- Mtime sync: `touch -t {timestamp} output.webp` (chained after generation)
- Only runs if `webcam_generate_webp` is enabled in config

**JPEG to AVIF**:
- Uses ffmpeg: `nice -n -1 ffmpeg -i input.jpg -frames:v 1 -c:v libaom-av1 -crf 30 -b:v 0 -cpu-used 4 output.avif`
- Quality: CRF 30 (similar quality to WebP's -q:v 30)
- Codec: libaom-av1 (AV1 codec for AVIF format)
- Speed: cpu-used 4 (balanced speed vs quality, 0-8 scale)
- Priority: `nice -n -1` (background job, doesn't interfere with main site rendering)
- Mtime sync: `touch -t {timestamp} output.avif` (chained after generation)
- Only runs if `webcam_generate_avif` is enabled in config
- Note: AVIF generation can take 15+ seconds; may timeout on slow systems

**Purpose**: 
- AVIF provides best compression (smallest file size, best quality)
- WebP provides good compression (smaller than JPEG)
- JPEG served as fallback for older browsers
- Format priority: AVIF → WebP → JPEG (based on browser support and availability)

### Cache Serving

**Endpoint**: `/webcam.php?airport={id}&cam={index}`

**Request Types**:
1. **Image Request**: Returns JPEG or WebP image
2. **Timestamp Request**: `?mtime=1` returns JSON with file modification time

**Serving Logic**:
1. Check if explicit format parameter (`?fmt=webp|avif`) → if generating, return HTTP 202
2. Check explicit format parameter → if ready, serve immediately (HTTP 200)
3. Check if format disabled but explicitly requested → return HTTP 400
4. Check Accept header for format preference (if no explicit `fmt=`) → serve best available (HTTP 200)
5. Check if all formats from same stale cycle → serve most efficient available (HTTP 200)
6. Fallback to JPEG (always available, HTTP 200)
7. If no formats available → serve placeholder (HTTP 200)

**HTTP 202 Response (Format Generating)**:
- Only returned for explicit `fmt=webp` or `fmt=avif` requests
- Indicates format is actively generating in current refresh cycle
- Includes `Retry-After` header (5 seconds)
- Client can wait briefly or use fallback immediately
- Not returned for old cycles (generation failed) or no explicit format requests

**Format Priority**:
- Explicit `fmt` parameter (highest priority, may return 202 if generating)
- AVIF (if browser supports via Accept header and enabled)
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

## Data Display on Dashboard

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
- **Visual**: Wind rose/compass showing direction
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
- **Sunrise**: Local time in airport timezone
- **Sunset**: Local time in airport timezone

### Webcam Display

#### Image Display
- **Format**: JPEG or WebP (based on browser support)
- **Refresh**: Automatic refresh based on cache age
- **Placeholder**: Shown if image unavailable
- **Loading State**: Shown during fetch

#### Timestamp Display
- **Last Updated**: Shows when image was captured
- **Format**: Relative time ("2 minutes ago") or absolute time
- **Update Frequency**: Checks timestamp periodically
- **Staleness Warning**: Warning emoji (⚠️) appears when webcam age exceeds `MAX_STALE_HOURS` (3 hours)

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

The system prioritizes **safety** (accurate, timely data), **performance** (fast responses), and **reliability** (graceful degradation when sources fail).
