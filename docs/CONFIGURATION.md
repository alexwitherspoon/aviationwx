# Airport Configuration Guide

## Overview

AviationWX supports dynamic configuration via a single `airports.json` file. This file contains:
- **Global Configuration** - Application-wide settings (timezone defaults, domain, refresh intervals)
- **Airport Configuration** - Individual airport settings (weather sources, webcams, metadata)

All configuration lives in this single read-only file, making deployment and management simple.

## Configuration File Structure

The `airports.json` file has two main sections:

```json
{
  "config": {
    "default_timezone": "UTC",
    "base_domain": "aviationwx.org",
    "max_stale_hours": 3,
    "webcam_refresh_default": 60,
    "weather_refresh_default": 60
  },
  "airports": {
    "airportid": { ... }
  }
}
```

### Global Configuration Section

The `config` section (optional) contains application-wide defaults:

- **`default_timezone`** - Default timezone for airports without timezone specified (default: `UTC`)
- **`base_domain`** - Base domain for airport subdomains (default: `aviationwx.org`)
- **`max_stale_hours`** - Maximum stale data threshold in hours (default: `3`)
- **`webcam_refresh_default`** - Default webcam refresh interval in seconds (default: `60`)
- **`weather_refresh_default`** - Default weather refresh interval in seconds (default: `60`)

If the `config` section is omitted, sensible defaults are used.

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

### 3. Davis WeatherLink
**Requires:** `api_key`, `api_secret`, and `station_id`

Get your API credentials from [WeatherLink.com](https://weatherlink.com) and add to config:

```json
"weather_source": {
    "type": "weatherlink",
    "api_key": "your-api-key-here",
    "api_secret": "your-api-secret-here",
    "station_id": "your-station-id-here"
}
```

### 4. PWSWeather.com (via AerisWeather API)
**Requires:** `station_id`, `client_id`, and `client_secret`

PWSWeather.com stations upload data to pwsweather.com, and station owners receive access to the AerisWeather API to retrieve their station's observations. Get your API credentials from your PWSWeather.com station profile (AerisWeather API section).

**Note**: Your station must be registered on PWSWeather.com and pass Quality Assurance checks before API access is available. This may take up to 4 days for new stations.

```json
"weather_source": {
    "type": "pwsweather",
    "station_id": "KMAHANOV10",
    "client_id": "your-aeris-client-id",
    "client_secret": "your-aeris-client-secret"
}
```

### 5. METAR (Fallback/Primary)
**No API key required** - Uses public METAR data

```json
"weather_source": {
    "type": "metar"
}
```

**METAR Station Configuration:**
- `metar_station`: Primary METAR station ID (e.g., `"KSPB"`)
- `nearby_metar_stations`: Array of alternate METAR station IDs for fallback (e.g., `["KVUO", "KHIO"]`)

**METAR Enable Behavior:**
- Configuring `metar_station` automatically enables METAR data fetching for that airport
- Per-airport: each airport independently enables METAR by configuring `metar_station`
- To disable METAR, simply omit or remove the `metar_station` field
- Example: Airport A with `metar_station: "KSPB"` has METAR enabled; Airport B without the field has it disabled

**Fallback Behavior:**
- The system attempts to fetch METAR data from the primary `metar_station` first
- If the primary station fails (network error, station unavailable, etc.), the system automatically tries each station in `nearby_metar_stations` in order
- The first successful fetch is used; remaining stations are not attempted
- If all stations fail, the system logs a warning and continues without METAR data
- This fallback only occurs when `metar_station` is configured
- Empty or invalid station IDs in `nearby_metar_stations` are automatically skipped

## Airport Identifiers and URL Routing

AviationWX supports multiple airport identifier types and automatically routes users to the preferred identifier. The system uses a priority hierarchy to determine which identifier should be used in URLs.

### Identifier Priority

The system uses the following priority order (highest to lowest):

1. **ICAO Code** (International Civil Aviation Organization) - 4 letters, e.g., `KSPB`, `KPDX`
2. **IATA Code** (International Air Transport Association) - 3 letters, e.g., `SPB`, `PDX`
3. **FAA Identifier (LID)** (Federal Aviation Administration Location Identifier) - 3-4 characters, e.g., `SPB`, `03S`
4. **Custom Airport ID** - The key used in `airports.json` (fallback if no other identifiers exist)

### How It Works

When you configure an airport, you can specify multiple identifier types:

```json
{
  "airports": {
    "kspb": {
      "name": "Scappoose Industrial Airpark",
      "icao": "KSPB",
      "iata": "SPB",
      "faa": "SPB",
      ...
    }
  }
}
```

**URL Routing Behavior:**

- The system automatically determines the **primary identifier** based on the priority hierarchy
- Users can access the airport using **any** of the configured identifiers (ICAO, IATA, FAA, or airport ID)
- The system automatically redirects users to the primary identifier URL
- Example: Accessing `pdx.aviationwx.org` redirects to `kpdx.aviationwx.org` (IATA → ICAO)

### Identifier Examples

**Example 1: Airport with all identifier types**
```json
{
  "airports": {
    "kspb": {
      "name": "Scappoose Industrial Airpark",
      "icao": "KSPB",    // Primary identifier (highest priority)
      "iata": "SPB",      // Accessible via spb.aviationwx.org (redirects to kspb.aviationwx.org)
      "faa": "SPB",       // Accessible via spb.aviationwx.org (redirects to kspb.aviationwx.org)
      ...
    }
  }
}
```
- Primary URL: `https://kspb.aviationwx.org` (uses ICAO)
- Alternative URLs that redirect: `spb.aviationwx.org` (IATA/FAA)

**Example 2: Airport with ICAO and IATA**
```json
{
  "airports": {
    "kpdx": {
      "name": "Portland International Airport",
      "icao": "KPDX",    // Primary identifier
      "iata": "PDX",      // Accessible via pdx.aviationwx.org (redirects to kpdx.aviationwx.org)
      ...
    }
  }
}
```
- Primary URL: `https://kpdx.aviationwx.org` (uses ICAO)
- Alternative URL that redirects: `pdx.aviationwx.org` (IATA)

**Example 3: Airport with only FAA identifier**
```json
{
  "airports": {
    "03s": {
      "name": "Example Airport",
      "faa": "03S",       // Primary identifier (no ICAO or IATA)
      ...
    }
  }
}
```
- Primary URL: `https://03s.aviationwx.org` (uses FAA, since no ICAO/IATA exists)

**Example 4: Airport with custom identifier only**
```json
{
  "airports": {
    "custom-airport": {
      "name": "Custom Airport Name",
      // No icao, iata, or faa fields
      ...
    }
  }
}
```
- Primary URL: `https://custom-airport.aviationwx.org` (uses airport ID as fallback)

### Configuration Guidelines

1. **Always include ICAO code if available** - This is the most preferred identifier
2. **Include IATA code if the airport has one** - Many commercial airports have IATA codes
3. **Include FAA identifier (LID) if different from IATA** - Some airports have FAA identifiers that differ from IATA
4. **Use descriptive airport IDs** - The airport ID (JSON key) should be meaningful, typically matching the ICAO code in lowercase
5. **All identifiers are case-insensitive** - The system handles case conversion automatically

### Redirect Behavior

- **301 Permanent Redirect** - When accessing via a non-primary identifier, users are redirected to the primary identifier URL
- **Query parameters preserved** - Any query parameters in the original URL are preserved in the redirect
- **Subdomain format** - All airport URLs use subdomain format: `{identifier}.aviationwx.org`

## Airport Status Configuration

### Enabled Flag

The `enabled` field controls whether an airport is active in the system.

- **Default**: `false` (opt-in model)
- **Required**: Must be explicitly set to `true` for airport to be accessible
- **Behavior when disabled**:
  - Airport returns 404 (as if it doesn't exist)
  - Airport is excluded from homepage listings
  - Weather and webcam data fetching is skipped
  - Airport is excluded from sitemap
  - API endpoints return 404

**Example:**
```json
{
  "airports": {
    "kspb": {
      "enabled": true,
      // ... other fields
    }
  }
}
```

### Maintenance Flag

The `maintenance` field shows a warning banner when an airport is under maintenance.

- **Default**: `false`
- **Behavior when `true`**:
  - Shows a red warning banner at the top of the airport page
  - Banner message: "⚠️ This airport is currently under maintenance. Data may be missing or unreliable."
  - APIs continue to work normally (banner is UI-only)
  - Airport must be `enabled: true` for maintenance banner to appear

**Example:**
```json
{
  "airports": {
    "kczk": {
      "enabled": true,
      "maintenance": true,
      // ... other fields
    }
  }
}
```

## Adding a New Airport

Add an entry to `airports.json` following this structure:

```json
{
  "airports": {
    "airportid": {
      "name": "Full Airport Name",
      "enabled": true,
      "maintenance": false,
      "icao": "ICAO",
      "iata": "IATA",
      "faa": "FAA",
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
        },
        {
          "name": "28L/10R",
          "heading_1": 280,
          "heading_2": 100
        },
        {
          "name": "28R/10L",
          "heading_1": 280,
          "heading_2": 100
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
          "position": "north"
        }
      ],
      "partners": [
        {
          "name": "Partner Organization",
          "url": "https://partner-link.com",
          "logo": "https://partner-link.com/logo.png",
          "description": "Brief description of partnership"
        }
      ],
      "airnav_url": "https://www.airnav.com/airport/KSPB",
      "metar_station": "KSPB",
      "nearby_metar_stations": ["KVUO", "KHIO"],
      "links": [
        {
          "label": "Airport Website",
          "url": "https://example-airport.com"
        },
        {
          "label": "Supporting Organization",
          "url": "https://example-org.com"
        }
      ]
    }
  }
}
```

## Partnerships Configuration

Partnerships are organizations, companies, or groups that help make the airport weather service possible. They are prominently displayed in a dedicated section above the footer on the airport dashboard.

### Configuration Format

Add a `partners` array to your airport configuration:

```json
"partners": [
  {
    "name": "Local Aviation Club",
    "url": "https://example-aviation-club.com",
    "logo": "https://example-aviation-club.com/logo.png",
    "description": "Supporting local aviation community"
  },
  {
    "name": "Weather Station Sponsor",
    "url": "https://example-weather-sponsor.com",
    "logo": "https://example-weather-sponsor.com/logo.jpg"
  },
  {
    "name": "Community Organization",
    "url": "https://example-org.com"
  }
]
```

### Fields

- **`name`** (required): The partner organization name
- **`url`** (required): The full URL to the partner's website (must be HTTPS)
- **`logo`** (optional): URL to the partner's logo image. Logos are automatically cached locally for 30 days. Supports JPEG, PNG, GIF, and WebP formats. PNG images are automatically converted to JPEG.
- **`description`** (optional): Brief description of the partnership (used for tooltips/accessibility)

### Behavior

- Partners are **optional** - only airports with a `partners` array will display the partnerships section
- Partners are displayed prominently in a two-column layout (Partnerships | Data Sources)
- Logos are automatically downloaded and cached locally for 30 days
- If a logo fails to load, the partner name is displayed as text
- All partner links open in a new tab with proper security attributes (`target="_blank" rel="noopener"`)
- Data sources (weather providers) are automatically deduced from the airport's `weather_source` configuration

### Logo Caching

Partner logos are automatically cached to improve performance and reduce load on partner servers:

- Logos are downloaded on first access and cached in `cache/partners/`
- Cache TTL: 30 days (2592000 seconds)
- Supported formats: JPEG, PNG, GIF, WebP (PNG converted to JPEG)
- Logos are served via `/api/partner-logo.php?url={encoded_url}`
- If logo download fails, partner name is displayed as text fallback

### Examples

**Airport with Single Partner:**
```json
"partners": [
  {
    "name": "Local Aviation Club",
    "url": "https://aviationclub.example.com",
    "logo": "https://aviationclub.example.com/logo.png"
  }
]
```

**Airport with Multiple Partners:**
```json
"partners": [
  {
    "name": "Local Aviation Club",
    "url": "https://aviationclub.example.com",
    "logo": "https://aviationclub.example.com/logo.png",
    "description": "Supporting local aviation community"
  },
  {
    "name": "Weather Station Sponsor",
    "url": "https://weathersponsor.example.com",
    "logo": "https://weathersponsor.example.com/logo.jpg"
  },
  {
    "name": "Community Organization",
    "url": "https://community.example.com"
  }
]
```

## Custom Links Configuration

You can add custom links to any airport that will appear in the links section alongside the standard links (AirNav, SkyVector, AOPA, FAA Weather). This is useful for linking to:
- Airport websites
- Supporting organizations
- FBO websites
- Local aviation groups
- Any other relevant resources

### Configuration Format

Add a `links` array to your airport configuration:

```json
"links": [
  {
    "label": "Airport Website",
    "url": "https://example-airport.com"
  },
  {
    "label": "Supporting Organization",
    "url": "https://example-org.com"
  }
]
```

### Fields

- **`label`** (required): The display text for the link button
- **`url`** (required): The full URL (must be HTTPS)

### Behavior

- Links are **optional** - only airports with a `links` array will display custom links
- Links appear in the links section after the standard links (AirNav, SkyVector, AOPA, FAA Weather)
- Each link is rendered as a button with proper security attributes (`target="_blank" rel="noopener"`)
- Links are only displayed if both `label` and `url` are present and non-empty
- All user input is properly escaped to prevent XSS attacks

### Examples

**Airport with Website:**
```json
"links": [
  {
    "label": "Airport Website",
    "url": "https://scappooseairport.com"
  }
]
```

**Airport with Multiple Links:**
```json
"links": [
  {
    "label": "Airport Website",
    "url": "https://example-airport.com"
  },
  {
    "label": "Friends of the Airport",
    "url": "https://friendsoftheairport.org"
  },
  {
    "label": "FBO",
    "url": "https://fbo.example.com"
  }
]
```

## Runway Configuration

### Runway Format

Runways are specified with a name and two headings (one for each end):

- **Single Runway**: Use numeric runway designations (e.g., "15/33")
- **Parallel Runways**: Use L (Left), C (Center), or R (Right) designations in the runway name (e.g., "28L/10R", "28R/10L")

The visualization automatically:
- Detects parallel runways by grouping runways with similar headings (within 5 degrees)
- Displays parallel runways side-by-side with horizontal offset
- Shows L/C/R designations in runway labels (e.g., "28L", "10R", "04C")
- Positions labels close to runway ends with white outlines for visibility

**Example - Single Runway:**
```json
{
  "runways": [
    {
      "name": "15/33",
      "heading_1": 152,
      "heading_2": 332
    }
  ]
}
```

**Example - Parallel Runways (Two):**
```json
{
  "runways": [
    {
      "name": "28L/10R",
      "heading_1": 280,
      "heading_2": 100
    },
    {
      "name": "28R/10L",
      "heading_1": 280,
      "heading_2": 100
    }
  ]
}
```

**Example - Parallel Runways (Three):**
```json
{
  "runways": [
    {
      "name": "04L/22R",
      "heading_1": 40,
      "heading_2": 220
    },
    {
      "name": "04C/22C",
      "heading_1": 40,
      "heading_2": 220
    },
    {
      "name": "04R/22L",
      "heading_1": 40,
      "heading_2": 220
    }
  ]
}
```

## Airport Timezone Configuration

The `timezone` field in each airport configuration determines:
- **When daily high/low temperatures and peak gust values reset** (at local midnight)
- **Sunrise/sunset time display format** (shown in local timezone)
- **Daily date calculation** for weather tracking (determines "today" vs "yesterday")

### Default Behavior

If not specified, defaults to `UTC` (configurable via `DEFAULT_TIMEZONE` environment variable). The timezone setting ensures that:
- Daily statistics (high/low temps, peak gust) reset at local midnight
- Sunrise/sunset times are displayed in the airport's local time
- "Today's" values reflect the local calendar day

### Timezone Format

Use standard PHP timezone identifiers. Common examples:
- `America/New_York` (Eastern Time - EST/EDT)
- `America/Chicago` (Central Time - CST/CDT)
- `America/Denver` (Mountain Time - MST/MDT)
- `America/Los_Angeles` (Pacific Time - PST/PDT)
- `America/Anchorage` (Alaska Time - AKST/AKDT)
- `Pacific/Honolulu` (Hawaii Time - HST)
- `UTC` (Coordinated Universal Time)

For a complete list, see [PHP's timezone list](https://www.php.net/manual/en/timezones.php).

### Global Configuration

The default timezone is set in the global `config` section of `airports.json`:

```json
{
  "config": {
    "default_timezone": "UTC"
  },
  "airports": { ... }
}
```

This allows all configuration to live in a single file, making deployment and management simpler.

### Configuration Examples

**Pacific Time Airport:**
```json
{
  "airports": {
    "kspb": {
      "name": "Scappoose Airport",
      "icao": "KSPB",
      "timezone": "America/Los_Angeles",
      ...
    }
  }
}
```

**Eastern Time Airport:**
```json
{
  "airports": {
    "kjfk": {
      "name": "John F. Kennedy International Airport",
      "icao": "KJFK",
      "timezone": "America/New_York",
      ...
    }
  }
}
```

**UTC Airport:**
```json
{
  "airports": {
    "airportid": {
      "name": "Example Airport",
      "timezone": "UTC",
      ...
    }
  }
}
```

### Important Notes

- Daily values reset at **local midnight** in the specified timezone
- If an airport spans multiple timezones, use the primary timezone
- The timezone setting affects when "today" begins and ends for statistics
- Sunrise/sunset calculations use the airport's coordinates, but display times use the configured timezone

## Webcam Configuration

The webcam fetcher includes reliability features:
- **Atomic writes**: Cache files are written atomically to prevent corruption
- **File locking**: Backoff state uses file locking to prevent race conditions
- **Circuit breaker**: Automatic exponential backoff for failing sources
- **Error handling**: Comprehensive error detection and logging

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
  "position": "north"
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
  "position": "south"
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
  "position": "north"
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
  "position": "east"
}
```

### Push Webcams (SFTP/FTP/FTPS)

Push webcams allow cameras to upload images directly to the server via SFTP, FTP, or FTPS. This is ideal for cameras that:
- Are behind firewalls and cannot be accessed directly
- Are on private networks without public IP addresses
- Need to push images on their own schedule
- Require secure, authenticated uploads

#### How It Works

1. **Camera Uploads**: The camera uploads images to a dedicated directory via SFTP/FTP/FTPS
2. **Automatic Processing**: A background process (runs every minute) checks for new uploads
3. **Image Validation**: Uploaded images are validated for format, size, and integrity
4. **Cache Generation**: Valid images are moved to the cache and WEBP versions are generated
5. **Website Display**: Images appear on the airport dashboard automatically

#### Configuration

To configure a push webcam, set `"type": "push"` and include a `push_config` object:

**Required Fields:**
- `username`: Exactly 14 alphanumeric characters (used for SFTP/FTP authentication)
- `password`: Exactly 14 alphanumeric characters (used for SFTP/FTP authentication)
- `protocol`: One of `"sftp"`, `"ftp"`, or `"ftps"`

**Optional Fields:**
- `max_file_size_mb`: Maximum file size in MB (default: 100MB, max: 100MB)
- `allowed_extensions`: Array of allowed file extensions (default: `["jpg", "jpeg", "png"]`)

**Note**:
- **SFTP**: Port 2222
- **FTP/FTPS**: Port 2121 (both protocols on same port)

**Push Webcam Example (SFTP):**
```json
{
  "name": "Runway Camera (Push)",
  "type": "push",
  "position": "north",
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

**Push Webcam Example (FTPS - Secure FTP):**
```json
{
  "name": "Secure Camera (Push)",
  "type": "push",
  "position": "south",
  "refresh_seconds": 120,
  "push_config": {
    "protocol": "ftps",
    "username": "kspbCam1Push02",
    "password": "AnotherPass5678",
    "max_file_size_mb": 20
  }
}
```

**Push Webcam Example (FTP - Plain):**
```json
{
  "name": "Legacy Camera (Push)",
  "type": "push",
  "position": "east",
  "push_config": {
    "protocol": "ftp",
    "username": "kspbCam2Push03",
    "password": "LegacyPass9012"
  }
}
```

#### Connection Details

After configuration, the system automatically creates SFTP/FTP users. Cameras should connect using:

- **SFTP**: 
  - Host: `upload.aviationwx.org`
  - Port: **2222**
  - Username: From `push_config.username`
  - Password: From `push_config.password`
  - Directory: Automatically chrooted to the camera's upload directory

- **FTPS (Secure FTP with TLS/SSL)**:
  - Host: `upload.aviationwx.org`
  - Port: **2121**
  - Username: From `push_config.username`
  - Password: From `push_config.password`
  - Encryption: TLS/SSL (negotiated via `AUTH SSL` or `AUTH TLS`)
  - Directory: Automatically chrooted to the camera's upload directory
  - **Note**: FTPS and FTP share the same port (2121). The client negotiates encryption during connection.

- **FTP (Plain, unencrypted)**:
  - Host: `upload.aviationwx.org`
  - Port: **2121**
  - Username: From `push_config.username`
  - Password: From `push_config.password`
  - Directory: Automatically chrooted to the camera's upload directory
  - **Note**: Both FTP and FTPS work on port 2121. FTPS requires SSL certificates to be configured (see SSL Setup below).

#### Security Features

- **Chrooted Directories**: Each camera is restricted to its own upload directory
- **Unique Credentials**: Each camera gets its own username/password
- **File Validation**: Uploaded files are validated for format, size, and integrity
- **Automatic Cleanup**: Old files are automatically removed after processing
- **IP Allowlisting**: Optional IP-based access control (see global settings)

#### Processing Behavior

- **Refresh Interval**: Controlled by `refresh_seconds` (minimum 60 seconds)
- **File Detection**: System checks for new files every minute
- **File Stability**: Waits for files to be fully written before processing
- **Format Support**: JPEG and PNG images are supported
- **Automatic WEBP**: WEBP versions are generated automatically for faster loading
- **Ephemeral Storage**: Upload directories are ephemeral (inside container only). Files are automatically processed and moved to cache, then removed from the upload directory. No persistent storage required for uploads.

#### Troubleshooting

- **Images not appearing**: Check that the camera is uploading to the correct directory and that files are valid JPEG/PNG images
- **Connection issues**: Verify credentials, port numbers, and firewall rules
- **File size errors**: Ensure uploaded files are within the `max_file_size_mb` limit
- **Processing delays**: The system processes uploads every minute; allow up to 60 seconds for images to appear

## Refresh Intervals

Both `webcam_refresh_seconds` and `weather_refresh_seconds` can be configured per airport in `airports.json`. If not specified, the following defaults are used:

- **Webcam Refresh:** 60 seconds (from `config.webcam_refresh_default`)
- **Weather Refresh:** 60 seconds (from `config.weather_refresh_default`)

These defaults are set in the global `config` section:

```json
{
  "config": {
    "webcam_refresh_default": 60,
    "weather_refresh_default": 60
  },
  "airports": { ... }
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
- If Tempest/Ambient/WeatherLink lacks visibility/ceiling, METAR data automatically supplements
- Flight category (VFR/IFR/MVFR) calculated from ceiling and visibility
- All aviation metrics computed regardless of source

**METAR Station Fallback:**
- When `metar_station` is configured, the system attempts to fetch from the primary station
- If the primary station fails, nearby stations (from `nearby_metar_stations`) are tried sequentially
- This provides resilience when a primary METAR station is temporarily unavailable
- The system logs which station was successfully used for debugging and monitoring

## Global Configuration Reference

All application defaults are configured in the `config` section of `airports.json`. This consolidates all configuration into a single file, making deployment and management simpler.

### Configuration Options

- **`default_timezone`** - Default timezone for airports without timezone specified (default: `UTC`)
- **`base_domain`** - Base domain for airport subdomains (default: `aviationwx.org`)
- **`max_stale_hours`** - Maximum stale data threshold in hours (default: `3`)
- **`webcam_refresh_default`** - Default webcam refresh interval in seconds (default: `60`)
- **`weather_refresh_default`** - Default weather refresh interval in seconds (default: `60`)

### Example Global Configuration

```json
{
  "config": {
    "default_timezone": "UTC",
    "base_domain": "aviationwx.org",
    "max_stale_hours": 3,
    "webcam_refresh_default": 60,
    "weather_refresh_default": 60
  },
  "airports": { ... }
}
```

**Note:** The `config` section is optional. If omitted, sensible defaults are used.

## Testing Locally

```bash
# Start the server
php -S localhost:8080

# Access homepage
open http://localhost:8080/

# Access specific airport
open http://localhost:8080/?airport=kspb

# Test weather API
curl http://localhost:8080/weather.php?airport=kspb

# Cache webcam images
php fetch-webcam.php
```

## Production Deployment

### Subdomain Setup
Each airport requires a subdomain DNS entry pointing to the same server:
- `kspb.aviationwx.org`
- `airportid.aviationwx.org`

The `.htaccess` file automatically routes subdomains to `index.php` which loads the appropriate airport template.

### Cron Jobs (Automatic)

Cron jobs are automatically configured inside the Docker container - no setup required!

- **Webcam refresh**: Runs every minute via cron inside the container
- **Weather refresh**: Runs every minute via cron inside the container

Both jobs are configured in the `crontab` file that's built into the Docker image and start automatically when the container starts.

## Configuration Files

- `airports.json` - Airport configuration
- `/tmp/aviationwx-cache/peak_gusts.json` - Daily peak gust tracking (ephemeral, cleared on reboot)
- `/tmp/aviationwx-cache/temp_extremes.json` - Daily temperature extremes (ephemeral, cleared on reboot)
- `/tmp/aviationwx-cache/webcams/` - Cached webcam images (ephemeral, cleared on reboot)

