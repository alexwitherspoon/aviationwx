# API Documentation

This document describes the API endpoints available in AviationWX.org.

## Base URL

Production: `https://aviationwx.org`  
Local: `http://localhost:8080`

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
    "last_updated_metar": 1699123400
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

**Caching:** Responses are cached. Check `Cache-Control` and `Expires` headers.

**Stale Data:** Data older than 3 hours is automatically nulled (displays as `null`). See [README.md](README.md#stale-data-safety-check) for details.

---

### Webcam Images

#### `GET /webcam.php?id={airport_id}&cam={camera_index}`

Returns a cached webcam image for the specified airport and camera.

**Parameters:**
- `id` (required): Airport ID (e.g., `kspb`)
- `cam` (required): Camera index (0-based, e.g., `0`, `1`)

**Response:**
- Content-Type: `image/jpeg` or `image/webp` (depending on browser support)
- Binary image data

**HTTP Status Codes:**
- `200`: Success (image returned)
- `404`: Image not found (returns placeholder image)
- `400`: Invalid parameters

**Rate Limiting:** 100 requests per minute per IP

**Caching:** Images are cached on disk and served with appropriate cache headers.

---

### Diagnostics

#### `GET /diagnostics.php`

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

### Clear Cache

#### `GET /clear-cache.php`

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

### Health Check

#### `GET /health.php`

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

### Metrics

#### `GET /metrics.php`

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

**Weather Source Status Thresholds:**
- **Primary Weather Sources** (Tempest, Ambient, WeatherLink):
  - Operational: < refresh interval (typically 60 seconds)
  - Degraded: refresh interval to 3 hours (MAX_STALE_HOURS)
  - Down: > 3 hours
- **METAR/Aviation Weather**:
  - Operational: < 2 hours (MAX_STALE_HOURS_METAR)
  - Degraded: 2-3 hours (between MAX_STALE_HOURS_METAR and MAX_STALE_HOURS)
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

All weather data elements are checked for staleness (3-hour threshold):

- **Stale Primary Source**: Temperature, dewpoint, humidity, wind, pressure, precipitation are nulled
- **Stale METAR Source**: Visibility, ceiling, cloud cover, flight category are nulled
- **Preserved**: Daily tracking values (`temp_high_today`, `temp_low_today`, `peak_gust_today`) are never nulled

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

