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

- PHP 7.4+
- cPanel with subdomain wildcard support (Bluehost.com recommended)
- Cron job capability for webcam caching (optional but recommended)

### Setup

#### Automated Deployment (Recommended)

1. **Configure GitHub Actions:**
   - Add secrets to your repository (Settings → Secrets)
   - See [DEPLOYMENT.md](DEPLOYMENT.md) for details
   - Push to `main` branch to trigger deployment

2. **Set up on Bluehost:**
   - Upload `airports.json` manually (not in Git)
   - Configure DNS with wildcard `*.aviationwx.org`
   - Set up cron jobs (see [DEPLOYMENT.md](DEPLOYMENT.md))

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

4. Upload all files to your web hosting root directory

5. Configure your subdomain wildcard DNS in cPanel: `*.aviationwx.org` → your server IP

6. Set up cron job to refresh webcam images:
   ```bash
   */1 * * * * curl -s http://yoursite.com/fetch-webcam-safe.php > /dev/null 2>&1
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

Then set up the subdomain in your DNS/hosting panel.

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
