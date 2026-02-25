# AviationWX.org

Real-time aviation weather and conditions for participating airports. A modern, open-source platform that provides pilots with live weather data, webcam feeds, and aviation-specific metrics for flight planning.

[![Test and Lint](https://github.com/alexwitherspoon/aviationwx/actions/workflows/test.yml/badge.svg)](https://github.com/alexwitherspoon/aviationwx/actions/workflows/test.yml)
[![Quality Assurance Tests](https://github.com/alexwitherspoon/aviationwx/actions/workflows/quality-assurance-tests.yml/badge.svg)](https://github.com/alexwitherspoon/aviationwx/actions/workflows/quality-assurance-tests.yml)
[![Deploy to Production](https://github.com/alexwitherspoon/aviationwx/actions/workflows/deploy-docker.yml/badge.svg)](https://github.com/alexwitherspoon/aviationwx/actions/workflows/deploy-docker.yml)

## What is AviationWX?

AviationWX.org provides real-time, localized weather data specifically designed for pilots making flight decisions. Each airport dashboard includes live weather conditions, webcam feeds, wind visualization, and aviation-specific metrics like density altitude and VFR/IFR status.

**Perfect for:**
- Airport operators wanting to provide free webcam and weather services to pilots to promote safety
- Pilots needing real-time conditions for flight planning
- Developers contributing to open-source aviation tools

## Quick Links

- 🚀 **[Getting Started](docs/LOCAL_SETUP.md)** - Local development setup (with or without secrets)
- 🧪 **[Testing Guide](docs/TESTING.md)** - Test strategy, running tests, mock mode
- 🖥️ **[Deploy to Production](docs/DEPLOYMENT.md)** - Complete deployment guide
- 📖 **[Configure Airports](docs/CONFIGURATION.md)** - Add and configure airports
- 🏗️ **[Architecture](docs/ARCHITECTURE.md)** - System design and components
- 🔌 **[API Documentation](docs/API.md)** - API endpoints reference
- 🔗 **[Embed Generator](https://embed.aviationwx.org)** - Create embeddable weather widgets ([docs](docs/EMBED.md))
- 🤝 **[Contributing](CONTRIBUTING.md)** - How to contribute
- 🔒 **[Security](docs/SECURITY.md)** - Security best practices
- 📊 **[Operations](docs/OPERATIONS.md)** - Logging, monitoring, troubleshooting
- 📝 **[Code Style Guide](CODE_STYLE.md)** - Coding standards and practices

## Features

### Weather Data
- **Multiple Sources**: Tempest Weather, Ambient Weather, Davis WeatherLink, or METAR
- **Real-time Updates**: Automatic refresh with configurable intervals
- **Stale Data Protection**: Automatically nulls data older than 3 hours
- **Daily Tracking**: High/low temperatures and peak gust with timestamps
- **Aviation Metrics**: Density altitude, pressure altitude, VFR/IFR/MVFR status

### Webcams
- **Multiple Formats**: Static images, MJPEG streams, RTSP/RTSPS streams, push uploads (SFTP/FTP/FTPS)
- **Automatic Caching**: Background refresh with atomic writes
- **Reliability**: Circuit breaker with exponential backoff for failing sources
- **Multiple Formats**: WEBP and JPEG with automatic fallback
- **Time-lapse History**: Optional webcam history player with shareable URLs and kiosk mode

### User Experience
- **Wind Visualization**: Interactive runway wind diagram with parallel runway support
- **Unit Toggles**: Switch between temperature (F/C), distance (ft/m), wind speed (kts/mph/km/h), and barometer (inHg/hPa/mmHg) units
- **Theme Toggle**: Four display modes - Auto (default, follows browser preference), Day (light), Dark (classic dark theme), and Night (red night vision mode for cockpit use)
  - Auto mode follows browser `prefers-color-scheme` preference in real-time
  - Auto night mode on mobile after evening civil twilight (based on airport local time) for safety
  - Persists user theme preference via cookie
- **Weather Status Emojis**: Visual indicators for abnormal conditions
- **Mobile-First Design**: Responsive layout for all devices
- **Offline Support**: Service worker for offline access

### Embed Generator
- **Embeddable Widgets**: Create weather widgets to embed on external websites
- **Multiple Styles**: Mini Airport Card, Single Webcam, Dual Camera, 4 Camera Grid, Full Widget
- **Customizable**: Choose theme (light/dark), select specific cameras, configure units
- **Easy Integration**: Copy iframe code for WordPress, Google Sites, Squarespace, or any HTML page
- **Live Preview**: See exactly how your embed will look before copying the code
- **Visit**: [embed.aviationwx.org](https://embed.aviationwx.org)

### Technical Features
- **Performance**: Config caching (APCu), HTTP cache headers, rate limiting
- **Security**: Input validation, rate limiting, sanitized error messages
- **SEO**: Dynamic sitemap, structured data (JSON-LD), Open Graph tags
- **Monitoring**: Status page with real-time system health

## Public API

AviationWX provides a free public API for developers to integrate aviation weather data into their applications.

- **Documentation:** [api.aviationwx.org](https://api.aviationwx.org)
- **OpenAPI Spec:** [api.aviationwx.org/openapi.json](https://api.aviationwx.org/openapi.json)

### Quick Start

```bash
# Get current weather for an airport
curl https://api.aviationwx.org/v1/airports/kspb/weather

# List all available airports
curl https://api.aviationwx.org/v1/airports
```

### Rate Limits

| Tier | Requests/min | Requests/day |
|------|--------------|--------------|
| Anonymous | 100 | 10,000 |
| Partner (API key) | 500 | 50,000 |

See [API documentation](https://api.aviationwx.org) for full endpoint details, authentication, and usage guidelines.

## Quick Start

### For Developers

**Option A: With mock data (new contributors)**
```bash
# Clone and start with example config
git clone https://github.com/alexwitherspoon/aviationwx.git
cd aviationwx

make config-example  # Copy example config (mock mode auto-activates)
make dev             # Start development server

# Access: http://localhost:8080
# Mock mode: Weather shows simulated data, webcams show placeholders
```

**Option B: With real data (maintainers with secrets)**
```bash
# Clone and configure with production secrets
git clone https://github.com/alexwitherspoon/aviationwx.git
cd aviationwx

# Configure docker-compose.override.yml to mount your secrets
cp docker/docker-compose.override.yml.example docker/docker-compose.override.yml
# Edit docker-compose.override.yml with your secrets path

make dev  # Start with real data
```

See [Local Development Setup](docs/LOCAL_SETUP.md) for complete instructions and [Testing Guide](docs/TESTING.md) for test configuration.

### For Airport Operators

#### Participate in AviationWX.org for free!

1. **Get API Keys**: Obtain credentials from your weather station provider (Tempest, Ambient, or WeatherLink)
2. **Gather your Webcam information**: RSTP, FTP(s), or the make and model of what you have.
3. **Email contact@aviationwx.org** to participate in AviationWX.org and we'll get it added.

P.S. - Do you need help sponsoring a local airport with equipment? Reach out, I'd like to talk to you about options!

#### Hosting Your Own Instance

1. **Get API Keys**: Obtain credentials from your weather station provider (Tempest, Ambient, or WeatherLink)
2. **Configure Airport**: Add your airport to `config/airports.json` (see [Configuration Guide](docs/CONFIGURATION.md))
3. **Set Up DNS**: Configure wildcard DNS for airport subdomains
4. **Deploy**: Follow the [Deployment Guide](docs/DEPLOYMENT.md)

### For System Administrators

See the [Deployment Guide](docs/DEPLOYMENT.md) for:
- Ubuntu LTS VPS setup from scratch
- Docker and Docker Compose installation
- SSL certificate setup (Let's Encrypt)
- CI/CD configuration (GitHub Actions)
- Production maintenance

## Installation Requirements

- **Docker** and **Docker Compose**
- Domain with wildcard DNS (A records for `@` and `*`)
- Weather station API credentials (Tempest, Ambient, WeatherLink, or METAR)

## Weather Sources

AviationWX supports multiple weather data sources. See the [Configuration Guide](docs/CONFIGURATION.md) for complete setup instructions and examples for:
- Tempest Weather
- Ambient Weather
- Davis WeatherLink
- PWSWeather.com (via AerisWeather API)
- METAR (aviation weather data)

## Security Note

⚠️ **IMPORTANT**: The `config/airports.json` file contains sensitive credentials (API keys, passwords).

- `config/airports.json` is in `.gitignore` and will NOT be committed
- Use `config/airports.json.example` as a template
- See [Security Guide](docs/SECURITY.md) for detailed guidelines
- Never commit real credentials to version control

## Status & Diagnostics

- **Status Page**: `/status.php` or `status.aviationwx.org` - Real-time system health
- **Diagnostics**: `/diagnostics.php` - System diagnostics and configuration
- **Health Check**: `/health.php` - Simple health check endpoint
- **Clear Cache**: `/clear-cache.php` - Clear configuration cache

## Software Dependencies

### Application (shipped with AviationWX)

| Package | Purpose | License |
|---------|---------|---------|
| [Parsedown](https://github.com/erusev/parsedown) (erusev/parsedown) | Markdown parsing for guides | MIT |
| [Leaflet](https://leafletjs.com) | Interactive maps | BSD-2-Clause |
| [Leaflet.markercluster](https://github.com/Leaflet/Leaflet.markercluster) | Map marker clustering (airports directory) | MIT |

### System / Runtime (Docker image)

| Tool | Purpose | License |
|------|---------|---------|
| [PHP](https://www.php.net) 8.4 + Apache | Runtime (php:8.4-apache base image) | PHP License |
| [ExifTool](https://exiftool.org) (libimage-exiftool-perl) | EXIF metadata for webcam images | Perl Artistic / GPL |
| [FFmpeg](https://ffmpeg.org) | Image/video processing for webcam formats | GPL/LGPL |
| [tini](https://github.com/krallin/tini) | Init process for container | MIT |
| [Composer](https://getcomposer.org) | PHP dependency manager | MIT |
| APCu, GD, Zip, pcntl | PHP extensions | PHP License |

### Optional Services (when configured)

- **Cloudflare Web Analytics** – Status page metrics (static.cloudflareinsights.com). CSP allows when enabled.
- **Cloudflare** – CDN, DNS, analytics API (optional; see docs/CONFIGURATION.md)

### Development Only (not shipped)

- **PHPUnit** (phpunit/phpunit) – Testing (BSD-3-Clause)
- **ESLint**, **globals** – JavaScript linting
- **Playwright** (@playwright/test) – Browser tests

## License

MIT License - See [LICENSE](LICENSE) file

## Data Partners

### FAA Weather Camera Program

AviationWX participates in the **FAA Weather Camera Program (WCPO)**, publishing webcam imagery to the FAA's official aviation weather camera network. Participating cameras are automatically formatted to meet FAA requirements and made available through FAA weather resources.

- **For Airport Operators**: Your cameras can reach pilots through official FAA channels at no additional cost
- **For Developers**: Use `?profile=faa` parameter to get FAA-compliant image formats
- **Requirements**: Cameras must meet reliability and quality standards

See [Configuration Guide](docs/CONFIGURATION.md#faa-profile-crop-margins) for technical details.

## Data Sources

AviationWX uses the following external data sources. See [Terms of Service](https://terms.aviationwx.org) for full attribution and licensing details.

### Weather Data
- **Aviation Weather Center** ([aviationweather.gov](https://aviationweather.gov)) – METAR, TAF (U.S. Government, public domain)
- **National Weather Service** ([weather.gov](https://www.weather.gov)) – ASOS observations (U.S. Government, public domain)
- **NOAA NCEI** ([geomag](https://www.ngdc.noaa.gov/geomag/)) – Magnetic declination (U.S. Government, public domain)
- **Environment Canada** ([dd.weather.gc.ca](https://dd.weather.gc.ca/)) – SWOB-ML for Canadian stations (Open Government Licence – Canada)
- **AWOSnet** ([awosnet.com](https://awosnet.com)) – AWOS station data
- **PWSWeather** ([pwsweather.com](https://pwsweather.com)) – Via AerisWeather/XWeather API
- **Tempest, Ambient, Davis WeatherLink, SynopticData** – Partner weather station platforms

### Map and Tiles
- **OpenStreetMap** ([openstreetmap.org](https://www.openstreetmap.org/copyright)) – Base map (ODbL)
- **OpenWeatherMap** ([openweathermap.org](https://openweathermap.org)) – Cloud cover tiles
- **RainViewer** ([rainviewer.com](https://www.rainviewer.com)) – Radar overlay tiles

### Airport and Aviation Data
- **OurAirports** ([ourairports.com/data](https://ourairports.com/data/)) – Airport identifiers, ICAO/IATA/FAA (Public Domain)
- **lxndrblz/Airports** ([GitHub](https://github.com/lxndrblz/Airports)) – ICAO fallback (CC-BY-SA-4.0)
- **FAA NOTAM** – Via CGI Federal NMS API (U.S. Government)

## Contributing

We welcome contributions! See [CONTRIBUTING.md](CONTRIBUTING.md) for complete guidelines, including:
- How to set up local development
- Coding standards and testing requirements
- Pull request process
- Areas where help is needed

Please read our [Code of Conduct](CODE_OF_CONDUCT.md) before contributing.

## Support

For issues and questions:
- Open an issue on [GitHub](https://github.com/alexwitherspoon/aviationwx/issues)
- Check the [documentation](docs/)
- Review [existing issues](https://github.com/alexwitherspoon/aviationwx/issues) for similar problems

---

**Made for pilots, by pilots** ✈️
