# AviationWX.org

Real-time aviation weather and conditions for participating airports.

Quick links:
- Deployment guide: [DOCKER_DEPLOYMENT.md](DOCKER_DEPLOYMENT.md)
- Configuration guide: [CONFIGURATION.md](CONFIGURATION.md)
- Diagnostics: visit `/diagnostics.php`
- Clear config cache: visit `/clear-cache.php`

## Features

- **Live Weather Data** from Tempest, Ambient, or METAR sources
- **Live Webcams** with automatic caching (MJPEG streams, RTSP streams via ffmpeg, and static images)
- **Wind Visualization** with runway alignment
- **Aviation-Specific Metrics**: Density altitude, VFR/IFR/MVFR status
- **Weather Status Emojis**: Visual indicators for abnormal conditions (precipitation, high winds, low ceiling, extreme temps)
- **Daily Temperature Extremes**: Tracks and displays today's high/low temperatures with timestamps
- **Daily Peak Gust**: Tracks and displays today's peak wind gust
- **Unit Toggles**: Switch between temperature units (F/C), distance units (ft/m), and wind speed units (kts/mph/km/h)
- **Multiple Image Formats**: AVIF, WEBP, and JPEG with automatic fallback
- **Time Since Updated Indicators**: Shows data age with visual warnings for stale data
- **Performance Optimizations**: 
  - Config caching (APCu)
  - HTTP cache headers for API responses
  - Rate limiting on API endpoints
- **Security Features**:
  - Input validation and sanitization
  - Rate limiting (60 req/min for weather, 100 req/min for webcams)
  - Sanitized error messages
- **Mobile-First Responsive Design**
- **Easy Configuration** via JSON config files

## Installation

### Requirements

- Docker and Docker Compose
- A domain with wildcard DNS (A records for `@` and `*`)
- Cron capability on the host for webcam refresh (recommended)

### Setup

#### Automated Deployment (Recommended)

1. **Configure GitHub Actions:**
   - Add SSH secrets to your repository (Settings → Secrets)
   - See [DOCKER_DEPLOYMENT.md](DOCKER_DEPLOYMENT.md) for the end-to-end guide
   - Push to `main` branch (or merge a PR) to trigger deployment

#### Manual Setup

1. Clone this repository:
```bash
git clone https://github.com/alexwitherspoon/aviationwx.git
cd aviationwx
```

2. Copy the example configuration:
   ```bash
   cp airports.json.example airports.json
   ```

3. Edit `airports.json` with your actual credentials:
   - Add your weather station API keys
   - Configure webcam URLs and credentials
   - Add airport metadata (name, ICAO, coordinates, elevation)
   - Set timezone (optional, defaults to `America/Los_Angeles`)

4. Start locally with Docker
   ```bash
   docker compose up -d
   # Access http://localhost:8080
   ```

5. Configure wildcard DNS: add A records for `@` and `*` to your server IP

6. Set up host cron to refresh webcam images (example):
   ```bash
   */1 * * * * curl -s http://127.0.0.1:8080/fetch-webcam-safe.php > /dev/null 2>&1
   ```

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

- **Supported webcam sources**: Static JPEG/PNG, MJPEG streams, RTSP streams, and RTSPS (secure RTSP over TLS) via ffmpeg snapshot
- **RTSP/RTSPS options per camera**:
  - `type`: `rtsp` (explicit type, recommended for RTSPS URLs)
  - `rtsp_transport`: `tcp` (default, recommended) or `udp`
  - `refresh_seconds`: Override refresh interval per camera
- ffmpeg 5.0+ uses the `-timeout` option (the old `-stimeout` is no longer supported)
- **Image format generation**: The fetcher automatically generates multiple formats per image:
  - `AVIF` (best-effort), `WEBP`, and `JPEG` for broad compatibility
- **Frontend**: Uses `<picture>` element with AVIF/WEBP sources and JPEG fallback

See [CONFIGURATION.md](CONFIGURATION.md) for detailed webcam configuration examples including RTSP/RTSPS setup.

### Dashboard Features

#### Unit Toggles

The dashboard includes three unit toggle buttons that allow users to switch between different measurement units:

1. **Temperature Unit Toggle** (F ↔ C)
   - Located next to "Current Conditions" heading
   - Affects: Temperature, Today's High/Low, Dewpoint, Dewpoint Spread
   - Default: Fahrenheit (°F)
   - Preference stored in localStorage

2. **Distance Unit Toggle** (ft ↔ m, in ↔ cm)
   - Located next to Temperature toggle
   - Affects: 
     - Rainfall Today (inches ↔ centimeters)
     - Pressure Altitude (feet ↔ meters)
     - Density Altitude (feet ↔ meters)
   - Pressure remains in inHg regardless of toggle
   - Default: Imperial (ft/in)
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

### Time Since Updated Indicators

- Weather API includes `last_updated` (UNIX) and `last_updated_iso`.
- UI displays "Time Since Updated" and marks it red when older than 1 hour (shows "Over an hour stale.").

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

### METAR

METAR data is pulled automatically from [Aviation Weather](https://aviationweather.gov) API. No API key required:

```json
"weather_source": {
  "type": "metar"
}
```

METAR can also be used as a supplement to Tempest/Ambient data when visibility or ceiling information is missing.

## License

MIT License - See LICENSE file

## Contributing

Contributions welcome! Please feel free to submit a Pull Request.

## Support

For issues and questions, please open an issue on GitHub.
