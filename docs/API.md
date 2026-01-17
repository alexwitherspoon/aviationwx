# Internal API Documentation

This document describes the **internal** API endpoints used by the AviationWX.org web interface. These endpoints are designed for the frontend and are not versioned.

> **For Third-Party Developers:** If you're building an application that integrates with AviationWX, please use the [**Public API**](https://api.aviationwx.org) instead. The Public API provides:
> - Stable, versioned endpoints (`/v1/...`)
> - OpenAPI specification
> - Rate limit headers
> - Consistent JSON responses
> - Support for API keys with higher rate limits
>
> Visit **[api.aviationwx.org](https://api.aviationwx.org)** for documentation.

---

## Internal Endpoints

### Base URL

Examples:
- Production: `https://aviationwx.org`
- Local development: `http://localhost:8080`

Replace with your own domain for production deployments.

## Endpoints

### Weather Data

#### `GET /weather.php?airport={airport_id}`

Returns weather data for the specified airport.

**Parameters:**
- `airport` (required): Airport ID (e.g., `kspb`)

**Response Format:**
```json
{
  "success": true,
  "weather": {
    "temperature": 15.5,
    "temperature_f": 60,
    "dewpoint": 12.0,
    "dewpoint_f": 54,
    "dewpoint_spread": 3.5,
    "humidity": 85,
    "wind_speed": 8,
    "wind_direction": 230,
    "gust_speed": 12,
    "peak_gust": 12,
    "gust_factor": 4,
    "pressure": 30.12,
    "visibility": 10.0,
    "ceiling": null,
    "cloud_cover": "SCT",
    "precip_accum": 0.0,
    "flight_category": "VFR",
    "flight_category_class": "status-vfr",
    "density_altitude": 1234,
    "pressure_altitude": 456,
    "temp_high_today": 18.5,
    "temp_low_today": 10.0,
    "temp_high_ts": 1699123456,
    "temp_low_ts": 1699087654,
    "peak_gust_today": 15,
    "peak_gust_time": 1699120000,
    "sunrise": "07:15",
    "sunset": "17:45",
    "last_updated": 1699123456,
    "last_updated_iso": "2024-11-04T12:34:56+00:00",
    "last_updated_primary": 1699123456,
    "last_updated_backup": null,
    "last_updated_metar": 1699123400,
    "obs_time_primary": 1699123450,
    "obs_time_backup": null,
    "obs_time_metar": 1699123400
  }
}
```

**Error Response:**
```json
{
  "success": false,
  "error": "Error message"
}
```

**HTTP Status Codes:**
- `200`: Success
- `400`: Invalid airport ID
- `404`: Airport not found
- `429`: Rate limit exceeded
- `500`: Server error

**Rate Limiting:** 60 requests per minute per IP

**Caching:** 
- Responses are cached with multi-layer cache control
- **Browser Cache:** Controlled by `max-age` (typically 60 seconds, per-airport configurable)
- **Cloudflare CDN Cache:** Controlled by `s-maxage` (typically 30 seconds, half of refresh interval)
- **Vary Header:** `Vary: Accept` ensures proper content negotiation
- **Stale-While-Revalidate:** Supports `stale-while-revalidate` for background cache updates
- Check `Cache-Control`, `Expires`, and `Vary` headers for cache behavior

**Cloudflare Configuration:**
The API uses `s-maxage` to limit Cloudflare cache TTL separately from browser cache. This prevents Cloudflare from serving stale data longer than intended. Cloudflare automatically varies cache by query string parameters (e.g., `airport`), so each airport's data is cached separately.

**Stale Data:** Data older than 3 hours is automatically nulled (displays as `null`). See [README.md](README.md#stale-data-safety-check) for details.

---

### Webcam Images

#### `GET /webcam.php?id={airport_id}&cam={camera_index}[&fmt={format}][&size={height}][&v={hash}][&mtime=1]`

Returns a cached webcam image for the specified airport and camera.

**Parameters:**
- `id` (required): Airport ID (e.g., `kspb`)
- `cam` (required): Camera index (0-based, e.g., `0`, `1`)
- `fmt` (optional): Explicit format request (`jpg` or `webp`)
  - If specified: May return HTTP 202 if format is generating
  - If omitted: Always returns HTTP 200 immediately (server respects `Accept` header)
- `size` (optional): Variant height in pixels (e.g., `1080`, `720`, `360`) or `original` for full resolution
  - Default: `original` (serves full-resolution original image)
  - Variants preserve aspect ratio and are capped at 3840px width for ultra-wide cameras
- `v` (optional): Cache-busting hash (8-character hex string)
- `mtime` (optional): Set to `1` to get JSON timestamp response instead of image

**Variant System:**
- Original images are preserved at full resolution
- Height-based variants are generated automatically (configured via `webcam_variant_heights` in `airports.json`)
- Variants are identified by height (e.g., `1080`, `720`, `360`) to support diverse aspect ratios
- Width is calculated from height to preserve aspect ratio, capped at 3840px for ultra-wide cameras
- Only variants ≤ original height are generated

---

### Webcam History

#### `GET /api/webcam-history.php?id={airport_id}&cam={camera_index}[&ts={timestamp}][&size={height}][&fmt={format}]`

Returns webcam history data. When `ts` is omitted, returns a JSON manifest of available frames. When `ts` is provided, returns the actual historical image.

**Parameters:**
- `id` (required): Airport ID (e.g., `kspb`)
- `cam` (required): Camera index (0-based)
- `ts` (optional): Unix timestamp of specific frame to retrieve
- `size` (optional): Variant height in pixels (e.g., `1080`, `720`, `360`) or `original` for full resolution
- `fmt` (optional): Format request (`jpg` or `webp`)

**Response (without `ts` - JSON manifest):**
```json
{
  "enabled": true,
  "available": true,
  "airport": "kspb",
  "cam": 0,
  "variantHeights": [1080, 720, 360],
  "frames": [
    {
      "timestamp": 1703444400,
      "url": "/api/webcam-history.php?id=kspb&cam=0&ts=1703444400",
      "formats": ["jpg", "webp"],
      "variants": {
        "original": ["jpg", "webp"],
        "1080": ["jpg", "webp"],
        "720": ["jpg", "webp"],
        "360": ["jpg"]
      }
    },
    {
      "timestamp": 1703444460,
      "url": "/api/webcam-history.php?id=kspb&cam=0&ts=1703444460",
      "formats": ["jpg", "webp"],
      "variants": {
        "original": ["jpg", "webp"],
        "1080": ["jpg", "webp"],
        "720": ["jpg", "webp"],
        "360": ["jpg"]
      }
    }
  ],
  "frame_count": 2,
  "current_index": 1,
  "timezone": "America/Los_Angeles",
  "max_frames": 12
}
```

**Response (with `ts` - Image):**
- **Content-Type**: `image/jpeg`
- **Cache-Control**: `public, max-age=31536000` (immutable historical frame)

**Response (history not configured):**
```json
{
  "enabled": false,
  "available": false,
  "airport": "kspb",
  "cam": 0,
  "frames": [],
  "max_frames": 1,
  "message": "Webcam history not configured for this airport"
}
```

**Response (history enabled but not yet available):**
```json
{
  "enabled": true,
  "available": false,
  "airport": "kspb",
  "cam": 0,
  "frames": [],
  "max_frames": 12,
  "message": "History not available for this camera, come back later."
}
```

**Error Responses:**
- `400 Bad Request`: Missing required parameters
- `404 Not Found`: Airport not found or frame not found

**Notes:**
- History is enabled when `webcam_history_max_frames >= 2`
- History is available when at least 2 frames have been captured
- Historical frames are stored directly in the camera cache directory (unified storage)
- See [Configuration Guide](CONFIGURATION.md#webcam-history-time-lapse) for setup details

---

**Parameters:**
- `id` (required): Airport ID (e.g., `kspb`)
- `cam` (required): Camera index (0-based, e.g., `0`, `1`)
- `fmt` (optional): Explicit format request (`jpg` or `webp`)
  - If specified: May return HTTP 202 if format is generating
  - If omitted: Always returns HTTP 200 immediately (server respects `Accept` header)
- `v` (optional): Cache-busting hash (8-character hex string)
- `mtime` (optional): Set to `1` to get JSON timestamp response instead of image

**Response:**
- Content-Type: `image/jpeg`, `image/webp`, or `application/json` (for `mtime=1`)
- Binary image data (for image requests) or JSON (for `mtime=1`)

**HTTP Status Codes:**
- `200`: Success (image returned)
- `202`: Format generating (only for explicit `fmt=webp` requests)
  - Response body: JSON with `status: "generating"`, `format`, `estimated_ready_seconds`, `fallback_url`, `preferred_url`, `jpeg_timestamp`, `refresh_interval`
  - Headers: `Retry-After: 5`, `X-Format-Generating: {format}`, `X-Fallback-URL: {url}`, `X-Preferred-Format-URL: {url}`
- `400`: Format disabled but explicitly requested, or invalid format parameter
- `404`: Airport or camera not found (returns placeholder image)
- `503`: Service unavailable - two possible causes:
  - Cache directory not accessible
  - **Stale image (fail-closed safety)**: Image is older than 3 hours (`DEFAULT_STALE_FAILCLOSED_SECONDS`). Returns JSON error: `{"error": "Image data is stale", "age_hours": X.X, "message": "..."}`

**Format Selection:**
- Explicit `fmt=` parameter: Highest priority, may return 202 if generating
- `Accept` header (no `fmt=`): Server respects browser preference, always returns 200
- Fallback: JPEG (always available, always enabled)

**Rate Limiting:** 100 requests per minute per IP

**Caching:** Images are cached on disk and served with appropriate cache headers. 202 responses are not cached.

**Timestamp Endpoint (`mtime=1`):**

Returns JSON with image timestamp, format availability, and variant information.

**Response Format:**
```json
{
  "success": true,
  "timestamp": 1699123456,
  "size": 123456,
  "formatReady": {
    "jpg": true,
    "webp": true
  },
  "variants": {
    "original": ["jpg", "webp"],
    "1080": ["jpg", "webp"],
    "720": ["jpg", "webp"],
    "360": ["jpg"]
  }
}
```

**Note:** Only includes formats that are enabled in configuration. Format availability is checked via optimized file I/O (single `stat()` call per format). The `variants` object shows available variant heights and their supported formats.

**Staleness Response (HTTP 503):**
If the image is older than 3 hours, the `mtime=1` endpoint also returns HTTP 503 with:
```json
{
  "success": false,
  "error": "Image data is stale",
  "age_hours": 4.5,
  "message": "Webcam image has not been updated in over 3 hours. Data cannot be trusted for aviation use."
}
```

---

### Admin Endpoints

#### `GET /admin/diagnostics.php`

Returns system diagnostics information (useful for debugging).

**Response Format:**
```json
{
  "system": {
    "php_version": "8.1.0",
    "server": "nginx/1.21.0"
  },
  "cache": {
    "apcu_enabled": true,
    "cache_size": "64M"
  },
  "config": {
    "config_file_exists": true,
    "config_cache_valid": true
  }
}
```

**Note:** May contain sensitive information. Use with caution in production.

---

#### `GET /admin/cache-clear.php`

Clears configuration cache (useful after updating `airports.json`).

**Response:**
```json
{
  "success": true,
  "message": "Cache cleared"
}
```

**Security:** Consider restricting access in production.

---

#### `GET /admin/metrics.php`

Returns application metrics (for monitoring systems like Prometheus).

**Response:** Prometheus-formatted metrics

**Example:**
```
# HELP http_requests_total Total number of HTTP requests
# TYPE http_requests_total counter
http_requests_total{endpoint="weather"} 1234
http_requests_total{endpoint="webcam"} 5678
```

---

### Health Endpoints

#### `GET /health/health.php`

Simple health check endpoint for monitoring.

**Response:**
```json
{
  "status": "ok",
  "timestamp": 1699123456
}
```

**HTTP Status Codes:**
- `200`: Healthy
- `500`: Unhealthy

---

### Status Page

#### `GET /status.php` or `GET /?status=1` or `status.aviationwx.org`

Returns an HTML status page displaying system and airport health status.

**Access:**
- Direct: `/status.php`
- Query parameter: `/?status=1`
- Subdomain: `status.aviationwx.org` (production)

**Response:** HTML status page with:
- System status components (Configuration, Cache, APCu, Logging, Error Rate)
- Per-airport status cards (Weather API, Webcams)
- Status indicators (Green/Yellow/Red) for each component
- Timestamps showing when each component status last changed

**Status Levels:**
- **Operational** (Green): Component is working correctly
- **Degraded** (Yellow): Component has issues but is still functional
- **Down** (Red): Component has critical failures
- **Under Maintenance** (Orange 🚧): Airport is in maintenance mode (overall status only; components still show individual status)

**Weather Source Status Thresholds:**
- **Primary Weather Sources** (Tempest, Ambient, WeatherLink):
  - Operational: 0 to 5x refresh interval (e.g., 0-300 seconds for 60-second refresh)
  - Degraded: 5x to 10x refresh interval (e.g., 300-600 seconds for 60-second refresh) or until 3 hours (whichever is smaller)
  - Down: > 10x refresh interval or > 3 hours (MAX_STALE_HOURS), whichever is smaller
- **METAR/Aviation Weather** (uses hourly thresholds, not multipliers):
  - Operational: < 2 hours (WEATHER_STALENESS_ERROR_HOURS_METAR)
  - Degraded: 2-3 hours (between WEATHER_STALENESS_ERROR_HOURS_METAR and MAX_STALE_HOURS)
  - Down: > 3 hours (MAX_STALE_HOURS)

**HTTP Status Codes:**
- `200`: Status page loaded successfully
- `503`: Configuration cannot be loaded

**Caching:** No caching (always fresh status data)

---

## Data Types

### Temperature
- **Unit**: Celsius (stored), Fahrenheit (converted for display)
- **Format**: Float (degrees Celsius)
- **Example**: `15.5` (15.5°C = 60°F)

### Wind Speed
- **Unit**: Knots
- **Format**: Integer
- **Example**: `8` (8 knots)

### Pressure
- **Unit**: Inches of Mercury (inHg)
- **Format**: Float
- **Example**: `30.12`

### Visibility
- **Unit**: Statute Miles (SM)
- **Format**: Float
- **Example**: `10.0` (10 statute miles)

### Ceiling
- **Unit**: Feet Above Ground Level (ft AGL)
- **Format**: Integer or `null` (unlimited)
- **Example**: `3500` or `null`

### Precipitation
- **Unit**: Inches
- **Format**: Float
- **Example**: `0.25` (0.25 inches)

### Timestamps
- **Format**: Unix timestamp (seconds since epoch)
- **Example**: `1699123456`

### Flight Category
- **Values**: `VFR`, `MVFR`, `IFR`, `LIFR`
- **CSS Class**: `status-vfr`, `status-mvfr`, `status-ifr`, `status-lifr`
- **Colors**: Green (VFR), Blue (MVFR), Red (IFR), Magenta (LIFR)

---

## Stale Data Handling

All weather data elements are checked for staleness (5x refresh interval threshold for primary/backup, 2-hour threshold for METAR):

- **Stale Primary Source**: Temperature, dewpoint, humidity, wind, pressure, precipitation are nulled
- **Stale Backup Source**: Fields using backup data are nulled when backup exceeds 5x refresh interval
- **Stale METAR Source**: Visibility, ceiling, cloud cover, flight category are nulled
- **Preserved**: Daily tracking values (`temp_high_today`, `temp_low_today`, `peak_gust_today`) are never nulled
- **Field-Level Fallback**: When primary is stale, backup source provides data for individual fields on a per-field basis

See [README.md](README.md#stale-data-safety-check) for complete details.

---

## Caching Headers

All endpoints return appropriate HTTP cache headers:

- `Cache-Control`: Cache directives
- `Expires`: Expiration time
- `ETag`: Entity tag for conditional requests
- `X-Cache-Status`: Cache status (HIT/MISS/STALE)

---

## Error Handling

All endpoints return JSON error responses:

```json
{
  "success": false,
  "error": "Error message"
}
```

Error messages are sanitized to prevent information leakage.

---

## Rate Limiting

- **Weather API**: 60 requests per minute per IP
- **Webcam API**: 100 requests per minute per IP

Rate limit exceeded returns:
- **Status**: `429 Too Many Requests`
- **Header**: `Retry-After: 60`
- **Response**: Error JSON

---

## Example Usage

### Fetch Weather Data

```bash
curl "https://aviationwx.org/weather.php?airport=kspb"
```

### Fetch Webcam Image

```bash
curl "https://aviationwx.org/webcam.php?id=kspb&cam=0" -o webcam.jpg
```

### JavaScript Example

```javascript
fetch('https://aviationwx.org/weather.php?airport=kspb')
  .then(response => response.json())
  .then(data => {
    if (data.success) {
      console.log('Temperature:', data.weather.temperature_f);
      console.log('Wind Speed:', data.weather.wind_speed);
    }
  });
```

---

## Versioning

Currently no API versioning. Endpoints may evolve, but backward compatibility is maintained when possible.

---

## Support

For API questions or issues:
1. Check this documentation
2. Review [README.md](README.md)
3. Open a GitHub issue

