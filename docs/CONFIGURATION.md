# Configuration Guide

All configuration lives in a single `airports.json` file with two sections:
- **`config`** - Global defaults
- **`airports`** - Per-airport settings

---

## Quick Reference

### Global Options (`config` section)

| Option | Default | Description |
|--------|---------|-------------|
| `default_timezone` | `UTC` | Fallback timezone for airports |
| `base_domain` | `aviationwx.org` | Base domain for subdomains |
| `public_ip` | — | Explicit public IPv4 (for FTP passive mode) |
| `public_ipv6` | — | Explicit public IPv6 |
| `upload_hostname` | `upload.{base_domain}` | Hostname for FTP/SFTP uploads |
| `dynamic_dns_refresh_seconds` | `0` | Re-resolve DNS periodically for DDNS (0=disabled, min 60) |
| `webcam_refresh_default` | `60` | Default webcam refresh (seconds) |
| `weather_refresh_default` | `60` | Default weather refresh (seconds) |
| `metar_refresh_seconds` | `60` | METAR refresh interval (min: 60) |
| `notam_refresh_seconds` | `600` | NOTAM refresh interval |
| `minimum_refresh_seconds` | `5` | Minimum allowed refresh interval |
| `scheduler_config_reload_seconds` | `60` | Config reload check interval |
| `weather_worker_pool_size` | `5` | Concurrent weather workers |
| `webcam_worker_pool_size` | `5` | Concurrent webcam workers |
| `notam_worker_pool_size` | `1` | Concurrent NOTAM workers |
| `worker_timeout_seconds` | `90` | Worker process timeout |
| `webcam_generate_webp` | `false` | Generate WebP globally |
| `faa_crop_margins` | see below | Default crop margins for FAA profile (percentages) |
| **Webcam History Settings** |||
| `webcam_history_retention_hours` | `24` | Hours of history to retain (preferred) |
| `webcam_history_default_hours` | `3` | Default period shown in UI |
| `webcam_history_preset_hours` | `[1, 3, 6, 24]` | Period options in UI |
| `webcam_history_max_frames` | — | *Deprecated* - use retention_hours |
| `default_preferences` | — | Default unit toggle settings (see below) |
| `notam_cache_ttl_seconds` | `3600` | NOTAM cache TTL |
| `notam_api_client_id` | — | NOTAM API client ID |
| `notam_api_client_secret` | — | NOTAM API client secret |
| **OpenWeatherMap Integration** |||
| `openweathermap_api_key` | — | API key for cloud layer tiles (optional, [free at openweathermap.org](https://home.openweathermap.org/api_keys)) |
| **Cloudflare Analytics** |||
| `cloudflare.api_token` | — | Cloudflare API token (Analytics:Read) |
| `cloudflare.zone_id` | — | Cloudflare Zone ID |
| `cloudflare.account_id` | — | Cloudflare Account ID |
| **Client Version Management** |||
| `dead_man_switch_days` | `7` | Days without update before cleanup (0 = disabled) |
| `force_cleanup` | `false` | Emergency flag to force all clients to cleanup |
| `stuck_client_cleanup` | `false` | Inject cleanup for clients stuck on old code |
| **Staleness Thresholds (3-tier model)** |||
| `stale_warning_seconds` | `600` | Warning tier threshold (yellow indicator) |
| `stale_error_seconds` | `3600` | Error tier threshold (red indicator) |
| `stale_failclosed_seconds` | `10800` | Failclosed tier (hide stale data) |
| `metar_stale_warning_seconds` | `3600` | METAR warning threshold |
| `metar_stale_error_seconds` | `7200` | METAR error threshold |
| `metar_stale_failclosed_seconds` | `10800` | METAR failclosed threshold |
| `notam_stale_warning_seconds` | `900` | NOTAM warning threshold |
| `notam_stale_error_seconds` | `1800` | NOTAM error threshold |
| `notam_stale_failclosed_seconds` | `3600` | NOTAM failclosed threshold |

### Airport Options (`airports.{id}` section)

**Airport IDs must be lowercase** (e.g., `kspb`, not `KSPB`). This prevents case sensitivity issues with file paths and cache directories.

| Option | Default | Description |
|--------|---------|-------------|
| **Required** |||
| `name` | — | Display name |
| `enabled` | `false` | Must be `true` to activate |
| `lat` | — | Latitude |
| `lon` | — | Longitude |
| **Identifiers** |||
| `icao` | — | ICAO code (e.g., `KSPB`) |
| `iata` | — | IATA code (e.g., `SPB`) |
| `faa` | — | FAA LID (e.g., `03S`) |
| `formerly` | `[]` | Previous identifiers for NOTAM matching |
| **Location** |||
| `address` | — | City, State display |
| `elevation_ft` | — | Field elevation in feet |
| `timezone` | global default | Timezone (e.g., `America/Los_Angeles`) |
| **Status** |||
| `maintenance` | `false` | Show maintenance banner |
| `unlisted` | `false` | Hide from discovery (map, search, sitemap) |
| **Refresh Overrides** |||
| `webcam_refresh_seconds` | global default | Override webcam refresh for this airport |
| `weather_refresh_seconds` | global default | Override weather refresh for this airport |
| **Feature Overrides** |||
| `webcam_history_retention_hours` | global default | Hours of history to retain |
| `webcam_history_default_hours` | global default | Default period shown in UI |
| `webcam_history_preset_hours` | global default | Period options in UI |
| `default_preferences` | global default | Override unit toggle defaults for this airport |
| **Data Sources** |||
| `weather_sources` | `[]` | Array of weather source configurations (see Weather Sources section) |
| `webcams` | `[]` | Array of webcam configurations |
| **Metadata** |||
| `runways` | `[]` | Runway definitions |
| `frequencies` | `{}` | Radio frequencies |
| `services` | `{}` | Available services |
| `partners` | `[]` | Partner organizations |
| `links` | `[]` | Custom external links |
| **Link Overrides** |||
| `airnav_url` | auto | Override AirNav link |
| `skyvector_url` | auto | Override SkyVector link |
| `aopa_url` | auto | Override AOPA link |
| `faa_weather_url` | auto | Override FAA Weather link |
| `foreflight_url` | auto | Override ForeFlight link |

### Webcam Options (`webcams[]` array items)

| Option | Default | Description |
|--------|---------|-------------|
| **Required** |||
| `name` | — | Display name |
| `url` | — | Stream/image URL (not for push type) |
| **Optional** |||
| `type` | auto-detect | `rtsp`, `mjpeg`, `static_jpeg`, `static_png`, `push` |
| `refresh_seconds` | airport default | Override refresh for this camera |
| `crop_margins` | global default | FAA profile crop margins override (percentages) |
| **RTSP Options** |||
| `rtsp_transport` | `tcp` | `tcp` or `udp` |
| `rtsp_fetch_timeout` | `10` | Frame capture timeout (seconds) |
| `rtsp_max_runtime` | `6` | Max ffmpeg runtime (seconds) |
| **Push Options** |||
| `push_config.username` | — | 14 alphanumeric chars |
| `push_config.password` | — | 14 alphanumeric chars |
| `push_config.max_file_size_mb` | `100` | Max upload size (1-100 MB) |
| `push_config.allowed_extensions` | `["jpg","jpeg","png"]` | Allowed file types |
| `push_config.upload_file_max_age_seconds` | `1800` | Max file age before abandonment (600-7200) |
| `push_config.stability_check_timeout_seconds` | `15` | Stability check timeout (10-30) |

### Configuration Hierarchy

Settings resolve in this order (first match wins):

1. **Per-webcam** — `webcams[].refresh_seconds`
2. **Per-airport** — `airport.webcam_refresh_seconds` or `airport.weather_refresh_seconds`
3. **Global** — `config.webcam_refresh_default` or `config.weather_refresh_default`
4. **Built-in default** — 60 seconds

### Default Preferences Hierarchy

Unit toggle defaults resolve in this order (first match wins):

1. **User preference** — stored in browser cookie/localStorage
2. **Per-airport** — `airport.default_preferences`
3. **Global** — `config.default_preferences`
4. **Built-in default** — US aviation standards (12hr, °F, ft, inHg, kts)

---

## Global Configuration

```json
{
  "config": {
    "default_timezone": "UTC",
    "base_domain": "aviationwx.org",
    "public_ip": "178.128.130.116",
    "public_ipv6": "2604:a880:2:d1::e88b:3001",
    "upload_hostname": "upload.aviationwx.org",
    
    "dead_man_switch_days": 7,
    "force_cleanup": false,
    "stuck_client_cleanup": false,
    
    "stale_warning_seconds": 600,
    "stale_error_seconds": 3600,
    "stale_failclosed_seconds": 10800,
    
    "webcam_refresh_default": 60,
    "weather_refresh_default": 60,
    "metar_refresh_seconds": 60,
    "notam_refresh_seconds": 600,
    "minimum_refresh_seconds": 5,
    
    "weather_worker_pool_size": 5,
    "webcam_worker_pool_size": 5,
    "notam_worker_pool_size": 1,
    "worker_timeout_seconds": 90,
    
    "webcam_generate_webp": false,
    "webcam_history_max_frames": 12,
    
    "notam_cache_ttl_seconds": 3600,
    "notam_api_client_id": "your-client-id",
    "notam_api_client_secret": "your-secret",
    
    "cloudflare": {
      "api_token": "your-analytics-read-token",
      "zone_id": "your-zone-id",
      "account_id": "your-account-id"
    }
  },
  "airports": { ... }
}
```

The `config` section is optional—sensible defaults apply if omitted.

### Network Configuration

Configure the server's public network identity for FTP/SFTP services and URL generation.

| Option | Type | Description |
|--------|------|-------------|
| `base_domain` | string | Base domain for URL generation (e.g., `aviationwx.org`) |
| `public_ip` | string | Public IPv4 address for FTP passive mode |
| `public_ipv6` | string | Public IPv6 address (optional) |
| `upload_hostname` | string | Hostname for FTP/SFTP uploads |
| `dynamic_dns_refresh_seconds` | integer | Re-resolve DNS periodically (0=disabled, min 60 when enabled) |

**FTP Passive Mode Resolution Priority:**

1. **`public_ip`** (explicit) — Use directly, no DNS lookup needed
2. **`upload_hostname`** — Resolve via DNS if `public_ip` not set
3. **`upload.{base_domain}`** — Default fallback if neither is set
4. **`upload.aviationwx.org`** — Final fallback

**Production Recommendation (Static IP):**

For production servers with static IPs, set `public_ip` explicitly to eliminate DNS resolution as a startup dependency:

```json
{
  "config": {
    "base_domain": "aviationwx.org",
    "public_ip": "178.128.130.116",
    "upload_hostname": "upload.aviationwx.org"
  }
}
```

**Dynamic DNS (DDNS) Support:**

For self-hosted instances with dynamic IPs (e.g., home internet with DDNS), enable periodic DNS refresh:

```json
{
  "config": {
    "base_domain": "weather.myairport.org",
    "upload_hostname": "upload.weather.myairport.org",
    "dynamic_dns_refresh_seconds": 300
  }
}
```

When `dynamic_dns_refresh_seconds` is enabled:
- The scheduler periodically re-resolves the upload hostname
- If the IP has changed, vsftpd's `pasv_address` is updated automatically
- vsftpd is restarted to apply the new IP (brief interruption to active FTP sessions)
- If `public_ip` is set, dynamic DNS refresh is automatically disabled (not needed)

**Self-Hosted/Federation:**

For self-hosted instances with static IPs, configure your own domain:

```json
{
  "config": {
    "base_domain": "weather.myairport.org",
    "public_ip": "203.0.113.50",
    "upload_hostname": "upload.weather.myairport.org"
  }
}
```

---

## Airport Configuration

### Minimal Example

```json
{
  "airports": {
    "kspb": {
      "name": "Scappoose Industrial Airpark",
      "enabled": true,
      "lat": 45.7710278,
      "lon": -122.8618333,
      "timezone": "America/Los_Angeles",
      "weather_sources": [
        { "type": "metar", "station_id": "KSPB" }
      ]
    }
  }
}
```

### Complete Example

```json
{
  "airports": {
    "kspb": {
      "name": "Scappoose Industrial Airpark",
      "enabled": true,
      "maintenance": false,
      "unlisted": false,
      
      "icao": "KSPB",
      "iata": "SPB",
      "faa": "SPB",
      
      "address": "Scappoose, Oregon",
      "lat": 45.7710278,
      "lon": -122.8618333,
      "elevation_ft": 58,
      "timezone": "America/Los_Angeles",
      
      "webcam_refresh_seconds": 30,
      "weather_refresh_seconds": 60,
      "webcam_history_max_frames": 24,
      
      "weather_sources": [
        {
          "type": "tempest",
          "station_id": "149918",
          "api_key": "your-key"
        },
        {
          "type": "nws",
          "station_id": "KSPB"
        },
        {
          "type": "metar",
          "station_id": "KSPB",
          "nearby_stations": ["KVUO", "KHIO"]
        }
      ],
      
      "webcams": [
        {
          "name": "Runway Camera",
          "url": "rtsp://camera.local:554/stream",
          "type": "rtsp",
          "refresh_seconds": 30
        },
        {
          "name": "Field View",
          "url": "https://example.com/cam.jpg",
          "refresh_seconds": 120
        }
      ],
      
      "runways": [
        { "name": "15/33", "heading_1": 152, "heading_2": 332 }
      ],
      "frequencies": {
        "ctaf": "122.8",
        "asos": "135.875"
      },
      "services": {
        "fuel": "100LL, Jet-A",
        "repairs_available": true
      },
      "partners": [
        {
          "name": "Local Aviation Club",
          "url": "https://club.example.com",
          "logo": "https://club.example.com/logo.png"
        }
      ],
      "links": [
        { "label": "Airport Website", "url": "https://airport.example.com" }
      ]
    }
  }
}
```

### Airport Identifiers

Priority order for URL routing (highest first):
1. **ICAO** — `KSPB` → `kspb.aviationwx.org`
2. **IATA** — `SPB` → redirects to ICAO
3. **FAA** — `03S` → `03s.aviationwx.org` (if no ICAO)
4. **Airport ID** — JSON key as fallback

All identifiers are case-insensitive. Non-primary identifiers 301 redirect to primary.

### Status Flags

**`enabled`** (default: `false`)
- Must be `true` for airport to be accessible
- When `false`: returns 404, excluded from homepage/sitemap, no data fetching

**`maintenance`** (default: `false`)
- Shows warning banner: "⚠️ This airport is currently under maintenance"
- Status page shows orange indicator
- APIs continue to function normally

**`unlisted`** (default: `false`)
- Hides airport from discovery channels while keeping it fully operational
- When `true`:
  - Data fetching continues normally (weather, webcams, NOTAMs)
  - Accessible via direct URL (e.g., `test.aviationwx.org`)
  - Hidden from: airport map, navigation search, sitemaps (XML/HTML), public API
  - Page includes `<meta name="robots" content="noindex, nofollow">` to prevent search indexing
- Use cases: test sites, new airports being commissioned, private beta testing
- To include unlisted airports in API: `GET /v1/airports?include_unlisted=true`

**State Matrix:**

| `enabled` | `unlisted` | Result |
|-----------|------------|--------|
| `false` | any | Disabled (404, no data fetching) |
| `true` | `false` | Fully public (default behavior) |
| `true` | `true` | Operational but hidden from discovery |

### Timezone

Affects daily statistics reset (midnight), sunrise/sunset display. Use PHP timezone identifiers:
- `America/New_York`, `America/Chicago`, `America/Denver`, `America/Los_Angeles`
- `America/Anchorage`, `Pacific/Honolulu`, `UTC`

---

## Weather Sources

All weather sources are configured in a unified `weather_sources` array. Sources are fetched in parallel and aggregated—the freshest data from any source wins for each field. METAR typically provides ceiling and cloud_cover (other sources do not provide these fields).

### Source Types

| Type | Description | Update Frequency |
|------|-------------|------------------|
| `tempest` | Tempest Weather Station | ~1 minute |
| `ambient` | Ambient Weather Network | ~1 minute |
| `weatherlink_v2` | Davis WeatherLink (newer devices) | Depends on Davis subscription (see below) |
| `weatherlink_v1` | Davis WeatherLink (legacy devices) | Depends on Davis subscription (see below) |
| `pwsweather` | PWSWeather/AerisWeather | Variable |
| `synopticdata` | SynopticData API | Variable |
| `nws` | NWS ASOS API (api.weather.gov) | ~5 minutes |
| `metar` | Aviation Weather METAR | ~60 minutes |

**Davis WeatherLink update intervals** (per [WeatherLink v2 Data Permissions](https://weatherlink.github.io/v2-api/data-permissions)): **Basic (free)** = most recent 15-minute record; **Pro (paid)** = most recent 5-minute record; **Pro+ (paid)** = most recent record (~1 minute). Historic data is only available on Pro/Pro+.

### Tempest Weather

```json
"weather_sources": [
  {
    "type": "tempest",
    "station_id": "149918",
    "api_key": "your-api-key"
  }
]
```

### Ambient Weather

```json
"weather_sources": [
  {
    "type": "ambient",
    "api_key": "your-api-key",
    "application_key": "your-app-key",
    "mac_address": "AA:BB:CC:DD:EE:FF"
  }
]
```

`mac_address` is optional—uses first device if omitted.

### Davis WeatherLink v2 (Newer Devices)

For WeatherLink Live, WeatherLink Console, and EnviroMonitor systems. Data interval depends on Davis subscription; see [Davis WeatherLink update intervals](#source-types) above.

```json
"weather_sources": [
  {
    "type": "weatherlink_v2",
    "api_key": "your-api-key",
    "api_secret": "your-api-secret",
    "station_id": "123456"
  }
]
```

**Getting v2 Credentials:**

| Field | Where to Find It |
|-------|------------------|
| `api_key` | WeatherLink Account page → "Generate v2 Key" |
| `api_secret` | Generated with API Key (shown only once!) |
| `station_id` | **We'll look this up for you** - just provide your API Key and Secret |

The Station ID is a numeric value not displayed in the WeatherLink web interface.
When you submit your API Key and Secret, we'll use the API to discover your Station ID.

See the [Weather Station Guide](../guides/09-weather-station-configuration.md) for detailed step-by-step instructions.

### Davis WeatherLink v1 (Legacy Devices)

For older devices: Vantage Connect, WeatherLinkIP, WeatherLink USB/Serial loggers. Same subscription-based intervals as v2; see [Davis WeatherLink update intervals](#source-types) above or [WeatherLink v2 Data Permissions](https://weatherlink.github.io/v2-api/data-permissions) for device-specific tables.

```json
"weather_sources": [
  {
    "type": "weatherlink_v1",
    "device_id": "001D0A12345678",
    "api_token": "your-api-token"
  }
]
```

**Getting v1 Credentials:**

| Field | Where to Find It |
|-------|------------------|
| `device_id` | Printed on a label on your physical device (12-16 characters) |
| `api_token` | WeatherLink Account page → API Token section |

See the [Weather Station Guide](../guides/09-weather-station-configuration.md) for photos and detailed instructions.

### PWSWeather (AerisWeather)

```json
"weather_sources": [
  {
    "type": "pwsweather",
    "station_id": "KMAHANOV10",
    "client_id": "your-aeris-client-id",
    "client_secret": "your-aeris-client-secret"
  }
]
```

### SynopticData

```json
"weather_sources": [
  {
    "type": "synopticdata",
    "station_id": "YOUR_STATION_ID",
    "api_token": "your-api-token"
  }
]
```

### NWS ASOS (National Weather Service)

High-frequency (~5 minute) observations from ASOS stations via the NWS API. Requires explicit `station_id` configuration.

```json
"weather_sources": [
  {
    "type": "nws",
    "station_id": "KSPB"
  }
]
```

The `station_id` must be a valid airport ICAO code (e.g., `KSPB`, `KPDX`). Only airport stations are accepted.

### METAR

METAR provides aviation-specific observations including visibility, ceiling, and cloud cover. No API key required.

```json
"weather_sources": [
  {
    "type": "metar",
    "station_id": "KSPB",
    "nearby_stations": ["KVUO", "KHIO"]
  }
]
```

`nearby_stations` provides fallback stations if the primary METAR station is unavailable.

### Backup Sources

Mark a source as backup by adding `"backup": true`. Backup sources are only used when primary sources fail or are stale:

```json
"weather_sources": [
  {
    "type": "tempest",
    "station_id": "149918",
    "api_key": "your-key"
  },
  {
    "type": "ambient",
    "api_key": "backup-key",
    "application_key": "backup-app-key",
    "backup": true
  },
  {
    "type": "metar",
    "station_id": "KSPB"
  }
]
```

### Multiple Sources Example

Combine multiple sources for redundancy and data quality. All sources are fetched in parallel:

```json
"weather_sources": [
  {
    "type": "tempest",
    "station_id": "149918",
    "api_key": "your-key"
  },
  {
    "type": "nws",
    "station_id": "KSPB"
  },
  {
    "type": "metar",
    "station_id": "KSPB",
    "nearby_stations": ["KVUO", "KHIO"]
  }
]
```

The aggregator uses the freshest data for each field. METAR typically provides ceiling and cloud_cover (other sources do not provide these fields).

---

## Webcam Configuration

### Format Detection

Automatic detection based on URL:
- `rtsp://` or `rtsps://` → RTSP stream
- `.jpg`, `.jpeg` → Static JPEG
- `.png` → Static PNG (converted to JPEG)
- Other URLs → MJPEG stream

Override with explicit `type` field.

### MJPEG Stream

```json
{
  "name": "Main Field View",
  "url": "https://example.com/mjpg/video.mjpg"
}
```

### Static Image

```json
{
  "name": "Weather Station Cam",
  "url": "https://wx.example.com/webcam.jpg",
  "refresh_seconds": 120
}
```

### RTSP Stream

```json
{
  "name": "Runway Camera",
  "url": "rtsp://camera.example.com:554/stream1",
  "type": "rtsp",
  "rtsp_transport": "tcp",
  "refresh_seconds": 30,
  "rtsp_fetch_timeout": 10,
  "rtsp_max_runtime": 6
}
```

For secure RTSP (RTSPS), use `rtsps://` URL with `"type": "rtsp"`.

#### UniFi Protect RTSP URLs

UniFi Protect cameras use specific ports for RTSP streams:

| Type | URL Pattern | Port |
|------|-------------|------|
| Local RTSP (unencrypted) | `rtsp://nvr-ip:7447/STREAM_ID` | 7447 |
| Shared RTSPS (encrypted) | `rtsps://nvr-ip:7441/STREAM_ID?enableSrtp` | 7441 |

**Local RTSP example (recommended for local AviationWX Bridge):**
```json
{
  "name": "UniFi Camera",
  "url": "rtsp://192.168.1.1:7447/FKEFbCxO0CiAF3TH",
  "type": "rtsp",
  "rtsp_transport": "tcp",
  "refresh_seconds": 60
}
```

**Shared RTSPS example (for remote access with encryption):**
```json
{
  "name": "UniFi Camera (Secure)",
  "url": "rtsps://192.168.1.1:7441/FKEFbCxO0CiAF3TH?enableSrtp",
  "type": "rtsp",
  "rtsp_transport": "tcp",
  "refresh_seconds": 60
}
```

The `STREAM_ID` is unique to each camera and must be copied from the UniFi Protect interface (Settings → Advanced → RTSP).

### Push Webcam (SFTP/FTP/FTPS)

For cameras that upload images to the server:

```json
{
  "name": "Runway Camera (Push)",
  "type": "push",
  "refresh_seconds": 60,
  "push_config": {
    "username": "kspbCam0Push01",
    "password": "SecurePass1234",
    "max_file_size_mb": 10,
    "allowed_extensions": ["jpg", "jpeg"],
    
    // Optional: Advanced tuning (usually not needed)
    "upload_file_max_age_seconds": 1800,      // Max age before file abandonment (default: 1800, range: 600-7200)
    "stability_check_timeout_seconds": 15     // Stability check timeout (default: 15, range: 10-30)
  }
}
```

**Connection details:**
- SFTP: Port 2222, Host: `upload.aviationwx.org`
- FTP/FTPS: Port 2121, Host: `upload.aviationwx.org`
- **Both protocols enabled**: Each push camera gets both FTP and SFTP access with the same credentials

**Upload paths:**
- **FTP**: Upload to `/` (vsftpd lands in FTP directory)
- **SFTP**: Upload to `/files/` (chroot requires subdirectory)

Directory structure (separate hierarchies for FTP and SFTP):
```
/cache/ftp/{airport}/{username}/   <- FTP uploads (ftp:www-data 2775)
/var/sftp/{username}/              <- SFTP chroot (root:root 755)
/var/sftp/{username}/files/        <- SFTP uploads (ftp:www-data 2775)
```

Note: SFTP uses `/var/sftp/` (outside cache) because SSH chroot requires
ALL parent directories to be root-owned. `/var/www/html/cache/` is www-data owned.

The processor checks both FTP and SFTP directories automatically.

**Subfolder support:** Cameras that create date-based folder structures (e.g., `2026/01/06/image.jpg`) are fully supported. The system recursively searches up to 10 levels deep and automatically cleans up empty folders after processing.

**Upload stability detection:** The system uses adaptive stability checking that starts conservative (20 consecutive stable checks = 10 seconds) and automatically optimizes based on the camera's historical upload performance. After 20+ successful uploads, it can reduce to as low as 5 checks (2.5 seconds) for fast connections.

**Advanced tuning parameters:**
- `upload_file_max_age_seconds`: Files older than this are considered stuck/abandoned and deleted (default: 30 minutes). Increase for known very slow connections (up to 2 hours).
- `stability_check_timeout_seconds`: How long the worker waits for an in-progress upload before returning to try again later (default: 15 seconds). Most sites should use the default.

### Webcam Variants

The system automatically generates multiple image sizes (variants) from the original image to optimize bandwidth and display performance. Variants are identified by height in pixels to support diverse aspect ratios.

**Configuration:**

Variants are configured via `webcam_variant_heights` at three levels (priority: per-camera → per-airport → global):

```json
{
  "config": {
    "webcam_variant_heights": [1080, 720, 360]
  },
  "airports": {
    "kspb": {
      "webcam_variant_heights": [1080, 720, 360],
      "webcams": [
        {
          "name": "Runway Camera",
          "variant_heights": [1080, 720]
        }
      ]
    }
  }
}
```

**How It Works:**
- Original images are preserved at full resolution
- Variants are generated by height (e.g., 1080px, 720px, 360px)
- Width is calculated from height to preserve aspect ratio
- Ultra-wide cameras are capped at 3840px width (prevents extreme widths)
- Only variants ≤ original height are generated
- Variants are stored as `{timestamp}_{height}.{format}` (e.g., `1703700000_1080.jpg`)

**Default Heights:** `[1080, 720, 360]` (supports common 16:9, 2:1, and 3:1 aspect ratios)

**API Usage:**
- Request specific variant: `/webcam.php?id=kspb&cam=0&size=720`
- Request original: `/webcam.php?id=kspb&cam=0&size=original` (default)
- History player automatically selects appropriate variant based on display size

### FAA Profile (Crop Margins)

AviationWX participates in the **FAA Weather Camera Program (WCPO)**, publishing webcam imagery to the FAA's official aviation weather camera network. The FAA WCPO requires specific image formats without third-party timestamps or watermarks.

The `profile=faa` API parameter produces WCPO-compliant images by applying configurable crop margins to exclude camera OSD timestamps and watermarks.

**API Usage:**
```
GET /v1/airports/kspb/webcams/0/image?profile=faa
```

**FAA Profile Behavior:**
- Applies crop margins to exclude edge content (timestamps, watermarks)
- Forces 4:3 aspect ratio
- Forces JPG format
- Quality-capped: 1280x960 if source supports it, otherwise 640x480 (no upscaling)

**Global Default Margins:**

Configure default crop margins (percentages) in the global config:

```json
{
  "config": {
    "faa_crop_margins": {
      "top": 5,
      "bottom": 4,
      "left": 0,
      "right": 4
    }
  }
}
```

**Per-Webcam Override:**

Override margins for specific cameras with unusual timestamp positions:

```json
{
  "webcams": [
    {
      "name": "Runway Camera",
      "url": "rtsp://...",
      "crop_margins": {
        "top": 8
      }
    }
  ]
}
```

**Margin Values:**
- All values are **percentages** (0-50) of source image dimensions
- Top/bottom: percentage of source height
- Left/right: percentage of source width
- Only specified edges are overridden; others use global defaults

**Config Hierarchy:**
1. Per-webcam `crop_margins` (highest priority)
2. Global `faa_crop_margins`
3. Built-in defaults: `{ top: 7, bottom: 4, left: 0, right: 4 }`

**Percentage Scaling Examples:**

| Margin | 720p (1280x720) | 1080p (1920x1080) | 4K (3840x2160) |
|--------|-----------------|-------------------|----------------|
| 5% top | 36px | 54px | 108px |
| 4% bottom | 29px | 43px | 86px |
| 4% right | 51px | 77px | 154px |

---

## Webcam History (Time-lapse)

Stores recent frames for time-lapse playback. All webcam images (current and historical) are stored in a unified directory structure at `cache/webcams/{airport}/{cam}/`.

### Storage Architecture

- **Unified Storage**: All webcam images stored directly in the camera cache directory
- **No Separate History Folder**: Timestamped files serve as both current and historical images
- **Symlinks**: `current.jpg`, `current.webp` point to the latest timestamped image
- **Retention**: Controlled by `webcam_history_retention_hours` config

### Configuration

History retention is now time-based using `webcam_history_retention_hours`:

| Setting | Default | Description |
|---------|---------|-------------|
| `webcam_history_retention_hours` | `24` | Hours of history to retain |
| `webcam_history_default_hours` | `3` | Default period shown in player UI |
| `webcam_history_preset_hours` | `[1, 3, 6, 24]` | Period selection buttons in UI |

The history player is enabled when `retention_hours > 0` and at least 2 frames exist.

### Set Globally

```json
{
  "config": {
    "webcam_history_retention_hours": 24,
    "webcam_history_default_hours": 3,
    "webcam_history_preset_hours": [1, 3, 6, 24]
  }
}
```

### Override Per-Airport

Use per-airport overrides for airports with different retention needs:

```json
{
  "airports": {
    "kspb": {
      "webcam_history_retention_hours": 48,
      "webcam_history_default_hours": 6
    }
  }
}
```

### Cleanup Safety Net

Cleanup uses a 2x safety multiplier to prevent data loss:
- Expected frames = `retention_hours × (3600 / refresh_seconds)`
- Max frames = `expected_frames × 2.0`

This ensures frames aren't deleted prematurely if timestamps don't align perfectly.

### Player UI Period Selection

The history player shows period preset buttons (e.g., "1h", "3h", "6h", "All") allowing users to select how much history to view:

- Only presets with sufficient data (≥90% coverage) are shown
- The default period is configurable
- Users can select "All" to view the entire retention period
- Lazy loading: frames are only downloaded when played or scrubbed to

### Legacy Configuration

The old `webcam_history_max_frames` setting is deprecated but still supported:
- If only `max_frames` is set, it's converted to hours automatically
- If both are set, `retention_hours` takes precedence
- A deprecation warning is logged when legacy config is detected

### Player URLs

- `https://kspb.aviationwx.org/?cam=0` — Opens player
- `https://kspb.aviationwx.org/?cam=0&autoplay` — Auto-plays
- `https://kspb.aviationwx.org/?cam=0&autoplay&hideui` — Kiosk mode
- `https://kspb.aviationwx.org/?cam=0&period=3h` — Opens with 3-hour period selected
- `https://kspb.aviationwx.org/?cam=0&period=all` — Opens with all history

### Keyboard Shortcuts

| Key | Action |
|-----|--------|
| `Space` | Play/pause |
| `←` / `→` | Previous/next frame |
| `Home` / `End` | First/last frame |
| `H` | Toggle hide UI |
| `Escape` | Close player |

---

## Default Preferences (Unit Toggles)

Configure default units for the airport page toggle buttons. User preferences (stored in cookies) always take priority over these defaults.

### Available Preferences

| Preference | Options | Default |
|------------|---------|---------|
| `time_format` | `12hr`, `24hr` | `12hr` |
| `temp_unit` | `F`, `C` | `F` |
| `distance_unit` | `ft`, `m` | `ft` |
| `baro_unit` | `inHg`, `hPa`, `mmHg` | `inHg` |
| `wind_speed_unit` | `kts`, `mph`, `km/h` | `kts` |

### Set Global Defaults

```json
{
  "config": {
    "default_preferences": {
      "time_format": "24hr",
      "temp_unit": "C",
      "distance_unit": "m",
      "baro_unit": "hPa",
      "wind_speed_unit": "kts"
    }
  }
}
```

### Override Per-Airport

```json
{
  "airports": {
    "egll": {
      "default_preferences": {
        "temp_unit": "C",
        "baro_unit": "hPa"
      }
    }
  }
}
```

### Priority Order

1. **User preference** — stored in browser cookie/localStorage (persists across visits)
2. **Per-airport** — `airport.default_preferences`
3. **Global** — `config.default_preferences`
4. **Built-in** — US aviation standards (12hr, °F, ft, inHg, kts)

Only include preferences you want to change from defaults. Users who have previously set a preference will keep their choice.

---

## Weather Overlays (Airport Map)

The airport network map at https://airports.aviationwx.org/ can display weather overlays from two sources:
1. **RainViewer** - Precipitation radar (no API key required)
2. **OpenWeatherMap** - Cloud cover, temperature, wind, pressure (requires free API key)

Both services are proxied through `/api/map-tiles.php` for server-side caching and usage metrics.

---

### RainViewer Precipitation Radar

**Always available** - No configuration required. Displays real-time precipitation radar overlay.

- **Source**: [RainViewer](https://www.rainviewer.com/)
- **Data**: Precipitation intensity (rain/snow)
- **Update frequency**: Every 10 minutes
- **Cache TTL**: 15 minutes (server-side)
- **API key**: Not required
- **Max zoom**: 7 (tiles scale up at higher zoom levels)

The precipitation radar layer is always enabled and accessible through the map controls (☔).

**Note (January 2026 API Changes)**: RainViewer's API now limits tile requests to zoom level 7 and 100 requests/IP/minute. The map automatically handles this by fetching tiles at zoom 7 and scaling them up when zoomed in further. Server-side caching ensures the rate limit is rarely a concern.

---

### OpenWeatherMap Weather Layers

**Optional** - Requires a free API key. When configured, enables cloud cover and other weather overlays.

#### Available Weather Layers

When configured, the following layers are available:
- **Cloud Cover** (`clouds_new`) - Cloud coverage overlay (exposed in UI)
- **Precipitation** (`precipitation_new`) - Rain/snow intensity  
- **Temperature** (`temp_new`) - Temperature gradient map
- **Wind Speed** (`wind_new`) - Wind speed visualization
- **Pressure** (`pressure_new`) - Atmospheric pressure

Currently, only the cloud layer is exposed in the UI. Additional layers can be added to the map controls in `pages/airports.php` if desired.

#### Getting an API Key

1. Sign up for a free account at [OpenWeatherMap](https://home.openweathermap.org/users/sign_up)
2. Navigate to [API Keys](https://home.openweathermap.org/api_keys)
3. Generate a new API key
4. Wait 10-20 minutes for the key to activate (standard OpenWeatherMap activation time)

#### Configuration

Add your API key to the global `config` section in `airports.json`:

```json
{
  "config": {
    "openweathermap_api_key": "your_api_key_here"
  }
}
```

#### Behavior

- **When configured**: Cloud layer toggle (☁️) appears in the map controls
- **When not configured**: Cloud layer toggle is hidden (precipitation radar still works)
- Free tier includes 60 calls/minute, 1,000,000 calls/month (sufficient for most deployments)

---

### Tile Proxy and Caching

All weather tiles (RainViewer and OpenWeatherMap) are proxied through `/api/map-tiles.php` for:
- **Server-side caching** - Reduces external API calls
- **Usage metrics** - Track tile requests for monitoring
- **Consistent CORS** - Unified cross-origin handling
- **Rate limiting** - Abuse protection

#### Multi-Layer Caching Architecture

**Caching layers (from fastest to slowest):**
1. **Browser cache** - Tiles cached in user's browser (session-based)
2. **Nginx proxy cache** - Shared cache at reverse proxy level
   - OpenWeatherMap: 1 hour TTL
   - RainViewer: 15 minutes TTL (radar updates frequently)
3. **PHP file cache** - Server-side cache at application level
   - OpenWeatherMap: 1 hour TTL
   - RainViewer: 15 minutes TTL
4. **External API** - Only hit when caches miss

**How this works in practice:**
- First user viewing a tile: Hits external API (counts against rate limit if applicable)
- Same user viewing same tile again: Browser cache (0 API calls)
- Different user viewing same tile (within TTL): Nginx cache (0 API calls)
- All users share the same server-side caches

**Optimization settings:**
- Tiles only load between zoom levels 3-12 (aviation planning range)
- Tiles only refresh when user stops panning (not during drag)
- Additional tiles kept in memory to reduce re-fetching
- Cache headers include `stale-while-revalidate` for resilience

#### Estimated Usage (OpenWeatherMap)

With server caching:
- First deployment day: ~500-2,000 tiles fetched (filling cache)
- Subsequent days: ~50-200 tiles/day (cache refreshes)
- Per user: Typically 0-5 API calls (most tiles already cached)
- High traffic (100 users/day): Still under 1,000 API calls/day
- **Total monthly: ~10,000-50,000 calls** (well under 1M limit)

**Rate limit handling:**
If you exceed 60 calls/minute (very rare with caching), OpenWeatherMap returns HTTP 429. The proxy will serve stale cached tiles as fallback.

**Monitoring your usage:**
- Check your API usage at: https://home.openweathermap.org/statistics
- If you consistently hit rate limits, consider starting with cloud layer disabled by default

---

### Abuse Protection

The tile proxy includes **permissive rate limiting** (300 requests/minute per IP):
- Legitimate users won't hit this limit (normal usage: ~10-50 tiles/session)
- Blocks obvious abuse (bots, scrapers, automated tools)
- Returns HTTP 429 with `Retry-After: 60` header when exceeded
- Rate limit window resets every minute

**Why permissive?**
- Panning the map quickly can load 20-50 tiles in seconds
- Multiple browser tabs or family members sharing IP need headroom
- Focus is on abuse prevention, not usage restriction

**Monitoring:**
Rate limit violations are logged to help identify abuse patterns:
```
aviationwx_log('warning', 'map tiles rate limit exceeded', ...)
```

---

### Testing

After adding your API key:
1. Visit https://airports.aviationwx.org/
2. Look for the cloud toggle button (☁️) in the map controls
3. Click to enable/disable the cloud overlay
4. Adjust opacity using the slider

---

## Runway Configuration

```json
"runways": [
  { "name": "15/33", "heading_1": 152, "heading_2": 332 },
  { "name": "28L/10R", "heading_1": 280, "heading_2": 100 },
  { "name": "28R/10L", "heading_1": 280, "heading_2": 100 }
]
```

Parallel runways (L/C/R) are automatically detected and displayed side-by-side.

---

## Partners & Links

### Partners

Displayed prominently above footer:

```json
"partners": [
  {
    "name": "Local Aviation Club",
    "url": "https://club.example.com",
    "logo": "https://club.example.com/logo.png",
    "description": "Supporting local aviation"
  }
]
```

Logos are cached locally for 30 days. Text fallback if logo fails.

### Custom Links

Appear after standard links (AirNav, SkyVector, etc.):

```json
"links": [
  { "label": "Airport Website", "url": "https://airport.example.com" },
  { "label": "FBO", "url": "https://fbo.example.com" }
]
```

### External Link Overrides

Standard links auto-generate from best identifier. Override when needed:

```json
"airnav_url": "https://www.airnav.com/airport/KSPB",
"skyvector_url": "https://skyvector.com/airport/KSPB"
```

---

## Refresh Intervals

### Hierarchy

1. Per-webcam: `webcams[].refresh_seconds`
2. Per-airport: `webcam_refresh_seconds`, `weather_refresh_seconds`
3. Global: `webcam_refresh_default`, `weather_refresh_default`
4. Built-in: 60 seconds

### Constraints

- Minimum: `minimum_refresh_seconds` (default: 5)
- METAR minimum: 60 seconds (data published hourly)

### Example: Fast Webcam, Slow Weather

```json
{
  "airports": {
    "kspb": {
      "webcam_refresh_seconds": 15,
      "weather_refresh_seconds": 300,
      "webcams": [
        { "name": "Priority Cam", "url": "...", "refresh_seconds": 10 },
        { "name": "Static Cam", "url": "...", "refresh_seconds": 120 }
      ]
    }
  }
}
```

---

## SSL Certificate Setup (FTPS)

FTPS requires the wildcard certificate (`*.aviationwx.org`).

### Quick Check

```bash
# Verify certificate
ls -la /etc/letsencrypt/live/aviationwx.org/
openssl x509 -in /etc/letsencrypt/live/aviationwx.org/fullchain.pem -noout -dates

# Check vsftpd SSL status
docker compose -f docker/docker-compose.prod.yml exec web grep "^ssl_enable=" /etc/vsftpd/vsftpd.conf

# Enable SSL manually if needed
docker compose -f docker/docker-compose.prod.yml exec web enable-vsftpd-ssl.sh
```

### Certificate Chain

1. Generate wildcard cert: `certbot certonly --dns-cloudflare -d aviationwx.org -d '*.aviationwx.org'`
2. Mount in Docker: `/etc/letsencrypt:/etc/letsencrypt:rw`
3. Container validates and enables SSL on startup
4. Restart container after renewal

See [DEPLOYMENT.md](DEPLOYMENT.md) for full certificate setup.

---

## Cloudflare Analytics Integration

AviationWX can integrate with Cloudflare Analytics to display real-time traffic, bandwidth, and security metrics on the status page and homepage.

### What It Provides

When configured, Cloudflare Analytics provides:

- **Unique Visitors** - Daily unique visitors across all pages
- **Total Requests** - Total HTTP requests (includes images, API calls, assets)
- **Bandwidth** - Total data transferred (GB)
- **Requests/Visitor** - Engagement metric (avg requests per visitor)
- **Threats Blocked** - Security events blocked by Cloudflare

These metrics appear on:
- **Status Page** (`status.aviationwx.org`) - Full metrics grid in header
- **Homepage** (`aviationwx.org`) - "Pilots Served Today" in hero section

### Configuration

Add the following to your `airports.json` config section:

```json
{
  "config": {
    "cloudflare": {
      "api_token": "your-analytics-read-token",
      "zone_id": "your-zone-id",
      "account_id": "your-account-id"
    }
  }
}
```

### Setup Steps

1. **Create API Token** (Cloudflare Dashboard → My Profile → API Tokens):
   - Use "Analytics:Read" template
   - Or create custom token with permissions:
     - Zone → Analytics → Read
     - Account → Analytics → Read (optional, for account-level metrics)
   - Scope to specific zone or all zones
   - Copy the token (only shown once!)

2. **Find Zone ID**:
   - Go to your domain in Cloudflare Dashboard
   - Right sidebar → "API" section → Zone ID
   - Copy the ID (format: `a1b2c3d4e5f6...`)

3. **Find Account ID** (optional):
   - Cloudflare Dashboard → Click domain
   - Right sidebar → Account ID
   - Copy the ID

4. **Add to Configuration**:
   - Update `airports.json` with credentials
   - Restart application: `make restart`
   - Verify: Check status page for metrics

### Caching Behavior

- **APCu Cache**: 30 minutes (in-memory, fast)
- **File Cache Fallback**: 2 hours (if APCu cleared)
- **Stale Data Strategy**: Shows last valid data if API fails (better than showing zeros)
- **API Rate Limits**: Respects Cloudflare's GraphQL API limits

### Privacy & Security

- **Read-Only**: Token has no write permissions
- **Analytics Only**: Cannot modify DNS, firewall, or other settings
- **No PII**: Only aggregated metrics (no visitor IPs or user data)
- **Local Caching**: Reduces API calls and improves performance

### Disabling Analytics

To disable Cloudflare Analytics:

1. **Remove config**: Delete `cloudflare` section from `airports.json`
2. **Restart**: `make restart`
3. **Result**: Metrics section hidden on status page, homepage shows static airport counts

### Testing

Run the Cloudflare Analytics test suite:

```bash
# Unit tests (includes mock mode tests)
vendor/bin/phpunit tests/Unit/CloudflareAnalyticsTest.php

# Check configuration
php -r "require 'lib/config.php'; \$c = loadConfig(); var_dump(isset(\$c['config']['cloudflare']));"
```

### Troubleshooting

**Metrics showing zeros:**
- Check API token permissions (Analytics:Read required)
- Verify Zone ID is correct
- Check Cloudflare has data for your zone (may take 24h for new zones)
- Review logs: `grep -i cloudflare /var/log/aviationwx/app.log`

**Metrics not appearing:**
- Ensure `cloudflare` config section exists
- Restart after config changes: `make restart`
- Check APCu is available: `php -r "var_dump(function_exists('apcu_fetch'));"`
- Verify file cache fallback: `ls -la cache/cloudflare_analytics.json`

**API errors:**
- Token expired or revoked (regenerate in Cloudflare Dashboard)
- Rate limit exceeded (wait 5-10 minutes, caching should prevent this)
- Zone not on account (verify Zone ID matches your domain)

---

## Validation

The validator uses strict checking—unknown fields are rejected.

### Adding New Fields

1. Update `lib/config.php` — add to allowed fields, add validation
2. Update `tests/Unit/ConfigValidationTest.php` — add tests
3. Update this documentation
4. Update `config/airports.json.example`

### Test Configuration

```bash
# Start Docker development environment
make dev

# Validate config (inside container)
docker compose -f docker/docker-compose.yml exec web \
  php -r "require 'lib/config.php'; var_dump(validateAirportsJsonStructure(loadAirportsConfig()));"

# Test API endpoint
curl http://localhost:8080/api/weather.php?airport=kspb
```

---

## Configuration Files

| File | Purpose |
|------|---------|
| `config/airports.json` | All configuration |
| `cache/weather/{airport}.json` | Cached weather data |
| `cache/weather/history/{airport}.json` | Weather history (24h) |
| `cache/webcams/{airport}/{cam}/` | Webcam images (current and historical) |
| `cache/webcams/{airport}/{cam}/current.{ext}` | Latest webcam (symlink) |
| `cache/webcams/{airport}/{cam}/{ts}_original.{ext}` | Original timestamped webcam images |
| `cache/webcams/{airport}/{cam}/{ts}_{height}.{ext}` | Variant timestamped webcam images (height in pixels) |
| `cache/ftp/{airport}/{username}/` | FTP push uploads (ftp:www-data 2775) |
| `/var/sftp/{username}/` | SFTP chroot (root:root 755) - outside cache |
| `/var/sftp/{username}/files/` | SFTP push uploads (ftp:www-data 2775) |
| `cache/peak_gusts/{airport}.json` | Per-airport daily peak gust tracking |
| `cache/temp_extremes/{airport}.json` | Per-airport daily temperature extremes |
