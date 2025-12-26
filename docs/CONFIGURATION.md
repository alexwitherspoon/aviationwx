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
| `max_stale_hours` | `3` | Hours before data considered stale |
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
| `webcam_generate_avif` | `false` | Generate AVIF globally |
| `webcam_history_enabled` | `false` | Enable time-lapse globally |
| `webcam_history_max_frames` | `12` | Max history frames per camera |
| `default_preferences` | — | Default unit toggle settings (see below) |
| `notam_cache_ttl_seconds` | `3600` | NOTAM cache TTL |
| `notam_api_client_id` | — | NOTAM API client ID |
| `notam_api_client_secret` | — | NOTAM API client secret |

### Airport Options (`airports.{id}` section)

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
| **Refresh Overrides** |||
| `webcam_refresh_seconds` | global default | Override webcam refresh for this airport |
| `weather_refresh_seconds` | global default | Override weather refresh for this airport |
| **Feature Overrides** |||
| `webcam_history_enabled` | global default | Override time-lapse for this airport |
| `webcam_history_max_frames` | global default | Override max frames for this airport |
| `default_preferences` | global default | Override unit toggle defaults for this airport |
| **Data Sources** |||
| `weather_source` | — | Primary weather source config |
| `weather_source_backup` | — | Backup weather source config |
| `metar_station` | — | Primary METAR station ID |
| `nearby_metar_stations` | `[]` | Fallback METAR stations |
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
| **RTSP Options** |||
| `rtsp_transport` | `tcp` | `tcp` or `udp` |
| `rtsp_fetch_timeout` | `10` | Frame capture timeout (seconds) |
| `rtsp_max_runtime` | `6` | Max ffmpeg runtime (seconds) |
| **Push Options** |||
| `push_config.protocol` | — | `sftp`, `ftp`, or `ftps` |
| `push_config.username` | — | 14 alphanumeric chars |
| `push_config.password` | — | 14 alphanumeric chars |
| `push_config.max_file_size_mb` | `100` | Max upload size |
| `push_config.allowed_extensions` | `["jpg","jpeg","png"]` | Allowed file types |

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
    "max_stale_hours": 3,
    
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
    "webcam_generate_avif": false,
    "webcam_history_enabled": false,
    "webcam_history_max_frames": 12,
    
    "notam_cache_ttl_seconds": 3600,
    "notam_api_client_id": "your-client-id",
    "notam_api_client_secret": "your-secret"
  },
  "airports": { ... }
}
```

The `config` section is optional—sensible defaults apply if omitted.

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
      "metar_station": "KSPB"
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
      "webcam_history_enabled": true,
      "webcam_history_max_frames": 24,
      
      "weather_source": {
        "type": "tempest",
        "station_id": "149918",
        "api_key": "your-key"
      },
      "weather_source_backup": {
        "type": "ambient",
        "api_key": "backup-key",
        "application_key": "backup-app-key"
      },
      "metar_station": "KSPB",
      "nearby_metar_stations": ["KVUO", "KHIO"],
      
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

### Timezone

Affects daily statistics reset (midnight), sunrise/sunset display. Use PHP timezone identifiers:
- `America/New_York`, `America/Chicago`, `America/Denver`, `America/Los_Angeles`
- `America/Anchorage`, `Pacific/Honolulu`, `UTC`

---

## Weather Sources

### Tempest Weather

```json
"weather_source": {
  "type": "tempest",
  "station_id": "149918",
  "api_key": "your-api-key"
}
```

### Ambient Weather

```json
"weather_source": {
  "type": "ambient",
  "api_key": "your-api-key",
  "application_key": "your-app-key",
  "mac_address": "AA:BB:CC:DD:EE:FF"
}
```

`mac_address` is optional—uses first device if omitted.

### Davis WeatherLink

```json
"weather_source": {
  "type": "weatherlink",
  "api_key": "your-api-key",
  "api_secret": "your-api-secret",
  "station_id": "your-station-id"
}
```

### PWSWeather (AerisWeather)

```json
"weather_source": {
  "type": "pwsweather",
  "station_id": "KMAHANOV10",
  "client_id": "your-aeris-client-id",
  "client_secret": "your-aeris-client-secret"
}
```

### SynopticData

```json
"weather_source": {
  "type": "synopticdata",
  "station_id": "YOUR_STATION_ID",
  "api_token": "your-api-token"
}
```

### METAR Only

No API key required. Two configuration options:

```json
"weather_source": { "type": "metar" },
"metar_station": "KSPB"
```

Or simply (auto-detects METAR as primary):

```json
"metar_station": "KSPB"
```

### Backup Weather Source

Activates automatically when primary exceeds 5× refresh interval:

```json
"weather_source_backup": {
  "type": "ambient",
  "api_key": "backup-key",
  "application_key": "backup-app-key"
}
```

Supports all source types. Field-level merging uses best available data from any source.

### METAR Fallback

```json
"metar_station": "KSPB",
"nearby_metar_stations": ["KVUO", "KHIO"]
```

System tries primary first, then each fallback in order until one succeeds.

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

### Push Webcam (SFTP/FTP/FTPS)

For cameras that upload images to the server:

```json
{
  "name": "Runway Camera (Push)",
  "type": "push",
  "refresh_seconds": 60,
  "push_config": {
    "protocol": "sftp",
    "username": "kspbCam0Push01",
    "password": "SecurePass1234",
    "max_file_size_mb": 10,
    "allowed_extensions": ["jpg", "jpeg"]
  }
}
```

**Connection details:**
- SFTP: Port 2222, Host: `upload.aviationwx.org`
- FTP/FTPS: Port 2121, Host: `upload.aviationwx.org`

Cameras upload directly to `/` (chroot root). Files are processed automatically.

---

## Webcam History (Time-lapse)

Stores recent frames for time-lapse playback.

### Enable Globally

```json
{
  "config": {
    "webcam_history_enabled": true,
    "webcam_history_max_frames": 12
  }
}
```

### Override Per-Airport

```json
{
  "airports": {
    "kspb": {
      "webcam_history_enabled": true,
      "webcam_history_max_frames": 24
    }
  }
}
```

### Player URLs

- `https://kspb.aviationwx.org/?cam=0` — Opens player
- `https://kspb.aviationwx.org/?cam=0&autoplay` — Auto-plays
- `https://kspb.aviationwx.org/?cam=0&autoplay&hideui` — Kiosk mode

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
docker compose -f docker/docker-compose.prod.yml exec web grep "^ssl_enable=" /etc/vsftpd/vsftpd_ipv4.conf

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

## Validation

The validator uses strict checking—unknown fields are rejected.

### Adding New Fields

1. Update `lib/config.php` — add to allowed fields, add validation
2. Update `tests/Unit/ConfigValidationTest.php` — add tests
3. Update this documentation
4. Update `config/airports.json.example`

### Test Configuration

```bash
# Validate config
php -r "require 'lib/config.php'; var_dump(validateAirportsJsonStructure(loadAirportsConfig()));"

# Test locally
php -S localhost:8080
curl http://localhost:8080/api/weather.php?airport=kspb
```

---

## Configuration Files

| File | Purpose |
|------|---------|
| `config/airports.json` | All configuration |
| `cache/weather_{airport}.json` | Cached weather data |
| `cache/webcams/{airport}_{cam}.jpg` | Cached webcam images |
| `cache/webcam-history/` | Time-lapse frames |
| `cache/peak_gusts.json` | Daily peak gust tracking |
| `cache/temp_extremes.json` | Daily temperature extremes |
