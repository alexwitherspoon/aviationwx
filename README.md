# AviationWX.org

Real-time aviation weather and conditions for participating airports.

## CI Status

[![Test and Lint](https://github.com/alexwitherspoon/aviationwx/actions/workflows/test.yml/badge.svg)](https://github.com/alexwitherspoon/aviationwx/actions/workflows/test.yml)

[![Quality Assurance Tests](https://github.com/alexwitherspoon/aviationwx/actions/workflows/quality-assurance-tests.yml/badge.svg)](https://github.com/alexwitherspoon/aviationwx/actions/workflows/quality-assurance-tests.yml)

[![Deploy to Production](https://github.com/alexwitherspoon/aviationwx/actions/workflows/deploy-docker.yml/badge.svg)](https://github.com/alexwitherspoon/aviationwx/actions/workflows/deploy-docker.yml)

[![Deploy airports.json to DigitalOcean](https://github.com/alexwitherspoon/aviationwx-secrets/actions/workflows/deploy.yml/badge.svg)](https://github.com/alexwitherspoon/aviationwx-secrets/actions/workflows/deploy.yml)

## Quick links:
- 🚀 **Local Development**: [docs/LOCAL_SETUP.md](docs/LOCAL_SETUP.md) (Docker-based development)
- 🖥️ **Production Deployment**: [docs/PRODUCTION_DEPLOYMENT.md](docs/PRODUCTION_DEPLOYMENT.md) (Ubuntu LTS VPS from scratch)
- 📖 **Configuration Guide**: [docs/CONFIGURATION.md](docs/CONFIGURATION.md)
- 🏗️ **Architecture**: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)
- 🔌 **API Documentation**: [docs/API.md](docs/API.md)
- 🚢 **Deployment Details**: [docs/DOCKER_DEPLOYMENT.md](docs/DOCKER_DEPLOYMENT.md) (CI/CD, advanced config)
- 🔒 **Security**: [docs/SECURITY.md](docs/SECURITY.md)
- 🤝 **Contributing**: [docs/CONTRIBUTING.md](docs/CONTRIBUTING.md)
- 🛠️ **Diagnostics**: visit `/diagnostics.php`
- 🗑️ **Clear Cache**: visit `/clear-cache.php`
- 📊 **Status Page**: visit `/status.php` or `status.aviationwx.org`

## Features

- **Live Weather Data** from Tempest, Ambient, or METAR sources
- **Live Webcams** with automatic caching (MJPEG streams, RTSP streams via ffmpeg, static images, and push uploads via SFTP/FTP/FTPS)
  - Reliable background refresh with atomic writes and file locking
  - Circuit breaker with exponential backoff for failing sources
  - Push webcam support: cameras can upload directly via SFTP, FTP, or FTPS
- **Wind Visualization** with runway alignment and parallel runway support (L/C/R designations)
- **Aviation-Specific Metrics**: Density altitude, VFR/IFR/MVFR status
- **Weather Status Emojis**: Visual indicators for abnormal conditions (precipitation, high winds, low ceiling, extreme temps)
- **Daily Temperature Extremes**: Tracks and displays today's high/low temperatures with timestamps
- **Daily Peak Gust**: Tracks and displays today's peak wind gust
- **Unit Toggles**: Switch between temperature units (F/C), distance units (ft/m), and wind speed units (kts/mph/km/h)
- **Stale Data Safety**: Automatically displays "---" for weather data older than 3 hours (per-source checking preserves valid data)
- **Multiple Image Formats**: WEBP and JPEG with automatic fallback
- **Time Since Updated Indicators**: Shows data age with visual warnings for stale data
- **Performance Optimizations**: 
  - Config caching (APCu)
  - HTTP cache headers for API responses
  - Rate limiting on API endpoints
- **Security Features**:
  - Input validation and sanitization
  - Rate limiting (60 req/min for weather, 100 req/min for webcams)
  - Sanitized error messages
- **Status Page**: Real-time system and airport health monitoring with timestamps
- **SEO & Indexing**: 
  - Dynamic XML sitemap (`/sitemap.xml`)
  - Structured data (JSON-LD) for search engines
  - Open Graph and Twitter Card tags for social sharing
  - Canonical URLs to prevent duplicate content
- **Mobile-First Responsive Design**
- **Easy Configuration** via JSON config files

## Installation

### Requirements

- Docker and Docker Compose
- A domain with wildcard DNS (A records for `@` and `*`)

## Quick Start

### For Local Development

See [LOCAL_SETUP.md](LOCAL_SETUP.md) for complete local development setup guide.

**Quick commands**:
```bash
# Clone repository
git clone https://github.com/alexwitherspoon/aviationwx.git
cd aviationwx

# Initialize and start
make init        # Create .env from example
make config      # Generate configs
cp airports.json.example airports.json  # Copy config template
# Edit airports.json with your API keys
make up          # Start Docker containers

# Access: http://localhost:8080
```

### For Production Deployment (Ubuntu LTS VPS)

See [PRODUCTION_DEPLOYMENT.md](PRODUCTION_DEPLOYMENT.md) for complete step-by-step deployment guide from scratch.

**Highlights**:
1. Set up fresh Ubuntu 22.04 LTS VPS
2. Install Docker & Docker Compose
3. Configure DNS (wildcard subdomain)
4. Set up SSL certificates (Let's Encrypt)
5. Deploy application (cron jobs run automatically inside container)
6. Set up automatic deployments (GitHub Actions - optional)

For detailed deployment guide with all steps, see [PRODUCTION_DEPLOYMENT.md](PRODUCTION_DEPLOYMENT.md).

### ⚠️ Security Note

**IMPORTANT**: The `airports.json` file contains sensitive credentials (API keys, passwords).
- `airports.json` is in `.gitignore` and will NOT be committed to the repo
- Use `airports.json.example` as a template
- See [SECURITY.md](SECURITY.md) for detailed security guidelines
- Never commit real credentials to version control

### Adding an Airport

Edit `airports.json` to add a new airport:

```json
{
  "airports": {
    "kspb": {
      "name": "Scappoose Airport",
      "icao": "KSPB",
      "address": "City, State",
      "lat": 45.7710278,
      "lon": -122.8618333,
      "elevation_ft": 58,
      "timezone": "America/Los_Angeles",
      "weather_source": { ... },
      "webcams": [ ... ]
    }
  }
}
```

**Timezone Configuration**: The `timezone` field (optional) determines when daily high/low temperatures and peak gust values reset at local midnight. If not specified, defaults to `America/Los_Angeles`. Use standard PHP timezone identifiers (e.g., `America/New_York`, `America/Chicago`, `America/Denver`, `UTC`).

See [CONFIGURATION.md](CONFIGURATION.md) for complete configuration details.

Then set up wildcard DNS as described in deployment docs.

### Configuration Path, Caching and Refresh Intervals

- Set `CONFIG_PATH` env to point the app to your mounted `airports.json`.
- Config cache: automatically invalidates when `airports.json` changes (mtime-based)
- Manual cache clear endpoint: `GET /clear-cache.php`
- Webcam refresh cadence can be controlled via env and per-airport:
  - `WEBCAM_REFRESH_DEFAULT` (seconds) default is 60
  - Per-airport `webcam_refresh_seconds` in `airports.json`
  - Per-camera `refresh_seconds` on each webcam entry overrides airport default
- Weather refresh/cache is similarly configurable:
  - `WEATHER_REFRESH_DEFAULT` (seconds) default is 60
  - Per-airport `weather_refresh_seconds` in `airports.json`

### Webcam Sources and Formats

- **Supported webcam sources**: Static JPEG/PNG, MJPEG streams, RTSP streams, RTSPS (secure RTSP over TLS) via ffmpeg snapshot, and push uploads via SFTP/FTP/FTPS
- **RTSP/RTSPS options per camera**:
  - `type`: `rtsp` (explicit type, recommended for RTSPS URLs)
  - `rtsp_transport`: `tcp` (default, recommended) or `udp`
  - `refresh_seconds`: Override refresh interval per camera
- **Push webcams (SFTP/FTP/FTPS)**: Cameras can upload images directly to the server
  - Supports SFTP (port 2222), FTPS (port 2122), and FTP (port 2121)
  - Automatic user creation and chrooted directories for security
  - Image validation and automatic processing
- ffmpeg 5.0+ uses the `-timeout` option (the old `-stimeout` is no longer supported)
- **Image format generation**: The fetcher automatically generates multiple formats per image:
  - `WEBP` and `JPEG` for broad compatibility
- **Frontend**: Uses `<picture>` element with WEBP sources and JPEG fallback

See [CONFIGURATION.md](CONFIGURATION.md) for detailed webcam configuration examples including RTSP/RTSPS and push webcam setup.

### Dashboard Features

#### Unit Toggles

The dashboard includes three unit toggle buttons that allow users to switch between different measurement units:

1. **Temperature Unit Toggle** (F ↔ C)
   - Located next to "Current Conditions" heading
   - Affects: Temperature, Today's High/Low, Dewpoint, Dewpoint Spread
   - Default: Fahrenheit (°F)
   - Preference stored in localStorage

2. **Distance Unit Toggle** (ft ↔ m, in ↔ cm, SM ↔ km)
   - Located next to Temperature toggle
   - Affects: 
     - Rainfall Today (inches ↔ centimeters)
     - Pressure Altitude (feet ↔ meters)
     - Density Altitude (feet ↔ meters)
     - Ceiling (feet ↔ meters)
     - Visibility (statute miles ↔ kilometers)
   - Pressure remains in inHg regardless of toggle
   - Default: Imperial (ft/in/SM)
   - Preference stored in localStorage

3. **Wind Speed Unit Toggle** (kts ↔ mph ↔ km/h)
   - Located in "Runway Wind" section header
   - Cycles through: knots → miles per hour → kilometers per hour → knots
   - Affects: Wind Speed, Gust Factor, Today's Peak Gust
   - Pressure remains in inHg regardless of toggle
   - Default: Knots (kts)
   - Preference stored in localStorage

All unit preferences persist across page refreshes using browser localStorage.

#### Weather Status Emojis

Weather status emojis appear next to the Condition status (e.g., "VFR 🌧️") to highlight abnormal or noteworthy weather conditions. Emojis only display when conditions are outside normal ranges:

**Precipitation** (always shown if present):
- 🌧️ **Rain**: Precipitation > 0.01" and temperature ≥ 32°F
- ❄️ **Snow**: Precipitation > 0.01" and temperature < 32°F

**High Wind** (shown when concerning):
- 💨 **Strong Wind**: Wind speed > 25 knots
- 🌬️ **Moderate Wind**: Wind speed 15-25 knots
- *No emoji*: Wind speed ≤ 15 knots (normal)

**Low Ceiling/Poor Visibility** (shown when concerning):
- ☁️ **Low Ceiling**: Ceiling < 1,000 ft AGL (IFR/LIFR conditions)
- 🌥️ **Marginal Ceiling**: Ceiling 1,000-3,000 ft AGL (MVFR conditions)
- 🌫️ **Poor Visibility**: Visibility < 3 SM (when available)
- *No emoji*: Ceiling ≥ 3,000 ft and visibility ≥ 3 SM (normal VFR)

**Extreme Temperatures** (shown when extreme):
- 🥵 **Extreme Heat**: Temperature > 90°F
- ❄️ **Extreme Cold**: Temperature < 20°F
- *No emoji*: Temperature 20°F to 90°F (normal range)

**Examples:**
- "VFR" (no emojis) - Normal conditions
- "VFR 🌧️" - Rainy but otherwise normal conditions
- "IFR ☁️ 💨" - Low ceiling with strong wind
- "VFR 🥵" - Very hot day
- "VFR ❄️" - Snow or extreme cold

Normal VFR days with moderate temperatures and light winds will not display emojis, keeping the interface clean.

#### Daily Temperature Tracking

The dashboard tracks and displays:
- **Today's High Temperature**: Maximum temperature for the current day with timestamp showing when it was recorded (e.g., "72°F at 2:30 PM")
- **Today's Low Temperature**: Minimum temperature for the current day with timestamp showing when it was recorded (e.g., "55°F at 6:15 AM")

Temperatures reset daily at local airport midnight (based on airport timezone configuration). The timestamps use the airport's local timezone.

#### Daily Peak Gust Tracking

The dashboard tracks and displays:
- **Today's Peak Gust**: Maximum wind gust speed for the current day

Peak gust resets daily at local airport midnight (based on airport timezone configuration).

#### Stale Data Safety Check

To prevent pilots from using dangerously outdated weather information, the system automatically checks data staleness and displays "---" for invalid data:

**3-Hour Safety Threshold:**
- Any weather data element older than 3 hours is considered stale
- Stale data displays as "---" instead of potentially outdated values
- Prevents pilots from making flight decisions based on old information

**Per-Source Checking:**
- The system tracks separate timestamps for primary source (Tempest/Ambient/WeatherLink) and METAR data
- Only fields from stale sources are nulled out - valid data from fresh sources is preserved
- Example: If primary source is stale but METAR is fresh, visibility/ceiling still display
- Example: If METAR is stale but primary source is fresh, temperature/wind still display

**Fields Affected by Staleness:**
- **Primary Source** (stale >3 hours): Current temperature, dewpoint, humidity, wind, pressure, precipitation
- **METAR Source** (stale >3 hours): Visibility, ceiling, cloud cover, flight category

**Fields Preserved (Never Stale):**
- **Daily Tracking Values**: Today's high/low temperatures and peak gust are preserved regardless of data staleness, as they represent valid historical data for the day

**Behavior:**
- Data < 3 hours old: Displayed normally
- Data > 3 hours old: Displays "---" for stale fields only
- Daily tracking values: Always displayed (valid historical data)

### Time Since Updated Indicators

- Weather API includes `last_updated` (UNIX) and `last_updated_iso`.
- UI displays "Time Since Updated" and marks it red when older than 1 hour (shows "Over an hour stale.").
- Note: Stale data safety check (3-hour threshold) automatically nulls outdated data regardless of UI indicator.

## Weather Sources

### Tempest Weather

Get your API token from [Tempest Weather](https://tempestwx.com) and add to config:

```json
"weather_source": {
  "type": "tempest",
  "station_id": "YOUR_STATION_ID",
  "api_key": "YOUR_API_KEY"
}
```

### Ambient Weather

Get your API credentials from [Ambient Weather](https://ambientweather.net) and add to config:

```json
"weather_source": {
  "type": "ambient",
  "api_key": "YOUR_API_KEY",
  "application_key": "YOUR_APPLICATION_KEY"
}
```

### Davis WeatherLink

Get your API credentials from [WeatherLink.com](https://weatherlink.com) and add to config:

```json
"weather_source": {
  "type": "weatherlink",
  "api_key": "YOUR_API_KEY",
  "api_secret": "YOUR_API_SECRET",
  "station_id": "YOUR_STATION_ID"
}
```

### METAR

METAR data is pulled automatically from [Aviation Weather](https://aviationweather.gov) API. No API key required:

```json
"weather_source": {
  "type": "metar"
}
```

METAR can also be used as a supplement to Tempest/Ambient/WeatherLink data when visibility or ceiling information is missing.

## License

MIT License - See LICENSE file

## Contributing

We welcome contributions! See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines and instructions.

Quick start:
1. Fork the repository
2. Set up local development (see [LOCAL_SETUP.md](LOCAL_SETUP.md))
3. Create a branch for your changes
4. Submit a Pull Request

For more details, see [CONTRIBUTING.md](CONTRIBUTING.md).

## Support

For issues and questions, please open an issue on GitHub.
