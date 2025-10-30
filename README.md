# AviationWX.org

Real-time aviation weather and conditions for participating airports.

## Features

- **Live Weather Data** from Tempest, Ambient, or METAR sources
- **Live Webcams** with automatic caching (MJPEG streams and static images)
- **Wind Visualization** with runway alignment
- **Aviation-Specific Metrics**: Density altitude, VFR/IFR/MVFR status
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
   - See [DOCKER_DEPLOYMENT.md](DOCKER_DEPLOYMENT.md) for details
   - Push to `main` branch to trigger deployment

#### Manual Setup

1. Clone this repository:
   ```bash
   git clone https://github.com/alexwitherspoon/aviationwx.org.git
   cd aviationwx.org
   ```

2. Copy the example configuration:
   ```bash
   cp airports.json.example airports.json
   ```

3. Edit `airports.json` with your actual credentials:
   - Add your weather station API keys
   - Configure webcam URLs and credentials
   - Add airport metadata

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
      ...
    }
  }
}
```

Then set up wildcard DNS as described in deployment docs.

### Configuration Path and Refresh Intervals

- Set `CONFIG_PATH` env to point the app to your mounted `airports.json`.
- Webcam refresh cadence can be controlled via env and per-airport:
  - `WEBCAM_REFRESH_DEFAULT` (seconds) default is 60
  - Per-airport `webcam_refresh_seconds` in `airports.json`
  - Per-camera `refresh_seconds` on each webcam entry overrides airport default
- Weather refresh/cache is similarly configurable:
  - `WEATHER_REFRESH_DEFAULT` (seconds) default is 60
  - Per-airport `weather_refresh_seconds` in `airports.json`

### Webcam Sources and Formats

- Supported webcam sources: Static JPEG/PNG, MJPEG streams, and RTSP streams (via ffmpeg snapshot).
- RTSP options per camera:
  - `rtsp_transport`: `tcp` (default) or `udp`
- The fetcher generates multiple formats per image:
  - `AVIF` (best-effort), `WEBP`, and `JPEG` for broad compatibility.
- Frontend uses `<picture>` with AVIF/WEBP sources and JPEG fallback.

### Time Since Updated Indicators

- Weather API includes `last_updated` (UNIX) and `last_updated_iso`.
- UI displays “Time Since Updated” and marks it red when older than 1 hour (shows “Over an hour stale.”).

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
