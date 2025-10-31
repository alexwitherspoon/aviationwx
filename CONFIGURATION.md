# Airport Configuration Guide

## Overview

AviationWX supports dynamic configuration of airports via `airports.json`. Each airport can be configured with its own weather source, webcams, and metadata.

## Supported Weather Sources

### 1. Tempest Weather
**Requires:** `station_id` and `api_key`

```json
"weather_source": {
    "type": "tempest",
    "station_id": "149918",
    "api_key": "your-api-key-here"
}
```

### 2. Ambient Weather
**Requires:** `api_key` and `application_key`

```json
"weather_source": {
    "type": "ambient",
    "api_key": "your-api-key-here",
    "application_key": "your-application-key-here"
}
```

### 3. METAR (Fallback/Primary)
**No API key required** - Uses public METAR data

```json
"weather_source": {
    "type": "metar"
}
```

## Adding a New Airport

Add an entry to `airports.json` following this structure:

```json
{
  "airports": {
    "airportid": {
      "name": "Full Airport Name",
      "icao": "ICAO",
      "address": "City, State",
      "lat": 45.7710278,
      "lon": -122.8618333,
      "elevation_ft": 58,
      "timezone": "America/Los_Angeles",
      "runways": [
        {
          "name": "15/33",
          "heading_1": 152,
          "heading_2": 332
        }
      ],
      "frequencies": {
        "ctaf": "122.8",
        "asos": "135.875"
      },
      "services": {
        "fuel_available": true,
        "repairs_available": true,
        "100ll": true,
        "jet_a": false
      },
      "weather_source": {
        "type": "tempest",
        "station_id": "149918",
        "api_key": "your-key-here"
      },
      "webcams": [
        {
          "name": "Camera Name",
          "url": "https://camera-url.com/stream",
          "username": "user",
          "password": "pass",
          "position": "north",
          "partner_name": "Partner Name",
          "partner_link": "https://partner-link.com"
        }
      ],
      "airnav_url": "https://www.airnav.com/airport/KSPB",
      "metar_station": "KSPB",
      "nearby_metar_stations": ["KVUO", "KHIO"]
    }
  }
}
```

## Airport Timezone Configuration

The `timezone` field in each airport configuration determines:
- When daily high/low temperatures and peak gust values reset (at local midnight)
- Sunrise/sunset time display format
- Daily date calculation for weather tracking

If not specified, defaults to `America/Los_Angeles`. Use standard PHP timezone identifiers (e.g., `America/New_York`, `America/Chicago`, `America/Denver`, `America/Los_Angeles`, `UTC`).

**Example:**
```json
{
  "airports": {
    "airportid": {
      "timezone": "America/Los_Angeles"
    }
  }
}
```

Daily values (high/low temperatures, peak gust) reset at midnight in the specified timezone, ensuring the displayed "today" values reflect the local airport day.

## Webcam Configuration

### Supported Formats
AviationWX automatically detects and handles webcam source types:

1. **MJPEG Streams** - Motion JPEG stream
   - Example: `https://example.com/video.mjpg`
   - Example: `https://example.com/mjpg/stream`
   - Automatically extracts first JPEG frame

2. **Static Images** - JPEG or PNG images
   - Example: `https://example.com/image.jpg`
   - Example: `https://example.com/webcam.png`
   - Downloads the image directly
   - PNG images are automatically converted to JPEG

3. **RTSP/RTSPS Streams** - Real Time Streaming Protocol (snapshot via ffmpeg)
   - Example: `rtsp://camera.example.com:554/stream`
   - Example: `rtsps://camera.example.com:7447/stream?enableSrtp` (secure RTSP over TLS)
   - Example: `rtsp://192.168.1.100:8554/live`
  - Requires `ffmpeg` (included in Docker image). Captures a single high-quality frame per refresh.
  - ffmpeg 5.0+ uses `-timeout` for RTSP timeouts (the old `-stimeout` is not supported)
   - **RTSPS Support**: Secure RTSP streams over TLS are fully supported

### Format Detection
The system automatically detects the source type from the URL:
- URLs starting with `rtsp://` or `rtsps://` → RTSP stream (requires ffmpeg)
- URLs ending in `.jpg`, `.jpeg` → Static JPEG image
- URLs ending in `.png` → Static PNG image (automatically converted to JPEG)
- All other URLs → Treated as MJPEG stream

**Explicit Type Override**: You can force a specific source type by adding `"type": "rtsp"`, `"type": "mjpeg"`, `"type": "static_jpeg"`, or `"type": "static_png"` to any webcam entry.

### Required Fields
- `name`: Display name for the webcam
- `url`: Full URL to the stream/image
- `position`: Direction the camera faces (for organization)
- `partner_name`: Partner organization name
- `partner_link`: Link to partner website

### Optional Fields
- `type`: Explicit source type override (`rtsp`, `mjpeg`, `static_jpeg`, `static_png`) - useful when auto-detection is incorrect
- `username`: For authenticated streams/images
- `password`: For authenticated streams/images
- `refresh_seconds`: Override refresh interval (seconds) - overrides airport `webcam_refresh_seconds` default
- `rtsp_transport`: `tcp` (default, recommended) or `udp` for RTSP/RTSPS streams only
// RTSP/RTSPS advanced options
- `rtsp_fetch_timeout`: Timeout in seconds for capturing a single frame from RTSP (default: 10)
- `rtsp_max_runtime`: Max ffmpeg runtime in seconds for the RTSP capture (default: 6)
- `transcode_timeout`: Max seconds allowed to generate WEBP (default: 8)

### Webcam Examples

**MJPEG Stream:**
```json
{
  "name": "Main Field View",
  "url": "https://example.com/mjpg/video.mjpg",
  "position": "north",
  "partner_name": "Example Partners",
  "partner_link": "https://example.com"
}
```

**RTSP Stream:**
```json
{
  "name": "Runway Camera",
  "url": "rtsp://camera.example.com:554/stream1",
  "type": "rtsp",
  "rtsp_transport": "tcp",
  "refresh_seconds": 30,
  "rtsp_fetch_timeout": 10,
  "rtsp_max_runtime": 6,
  "transcode_timeout": 8,
  "username": "admin",
  "password": "password123",
  "position": "south",
  "partner_name": "Partner Name",
  "partner_link": "https://partner.com"
}
```

**RTSPS Stream (Secure RTSP over TLS):**
```json
{
  "name": "Secure Runway Camera",
  "url": "rtsps://camera.example.com:7447/stream?enableSrtp",
  "type": "rtsp",
  "rtsp_transport": "tcp",
  "refresh_seconds": 60,
  "rtsp_fetch_timeout": 10,
  "rtsp_max_runtime": 6,
  "transcode_timeout": 8,
  "position": "north",
  "partner_name": "Partner Name",
  "partner_link": "https://partner.com"
}
```

**Note**: For RTSPS streams, always set `"type": "rtsp"` explicitly and use `"rtsp_transport": "tcp"` for best reliability.

### Error Handling and Backoff (RTSP)
- Errors are classified into transient (timeout, connection, DNS) and permanent (auth, TLS).
- Transient errors back off exponentially up to 1 hour; permanent errors up to 2 hours.
 

**Static Image:**
```json
{
  "name": "Weather Station Cam",
  "url": "https://wx.example.com/webcam.jpg",
  "position": "east",
  "partner_name": "Weather Station",
  "partner_link": "https://wx.example.com"
}
```

## Dynamic Features
### Configuration Cache (Automatic)
- The configuration (`airports.json`) is cached in APCu for performance.
- The cache automatically invalidates when the file's modification time changes.
- You can force a cache clear by visiting `/clear-cache.php`.

### Automatic Homepage
The homepage (`homepage.php`) automatically displays all airports from `airports.json` with working links to each subdomain.

### Dynamic Webcam Support
- Supports 1-6 webcams per airport
- Each webcam automatically appears in the grid
- Responsive layout adjusts to number of webcams

### Weather Source Fallback
- If Tempest/Ambient lacks visibility/ceiling, METAR data automatically supplements
- Flight category (VFR/IFR/MVFR) calculated from ceiling and visibility
- All aviation metrics computed regardless of source

## Testing Locally

```bash
# Start the server
php -S localhost:8000

# Access homepage
open http://localhost:8000/

# Access specific airport
open http://localhost:8000/?airport=kspb

# Test weather API
curl http://localhost:8000/weather.php?airport=kspb

# Cache webcam images
php fetch-webcam.php
```

## Production Deployment

### Subdomain Setup
Each airport requires a subdomain DNS entry pointing to the same server:
- `kspb.aviationwx.org`
- `airportid.aviationwx.org`

The `.htaccess` file automatically routes subdomains to `index.php` which loads the appropriate airport template.

### Cron Job Setup
Set up a cron job to refresh webcam images every 60 seconds:

```bash
* * * * * /usr/bin/php /path/to/fetch-webcam.php
```

## Configuration Files

- `airports.json` - Airport configuration
- `cache/peak_gusts.json` - Daily peak gust tracking
- `cache/temp_extremes.json` - Daily temperature extremes
- `cache/webcams/` - Cached webcam images

