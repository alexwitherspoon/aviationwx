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

- 🚀 **[Getting Started](docs/LOCAL_SETUP.md)** - Local development setup
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
  - Auto night mode on mobile after sunset (based on airport local time) for safety
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
- **VPN Support**: Optional VPN for accessing private camera networks

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
| Anonymous | 20 | 2,000 |
| Partner (API key) | 120 | 50,000 |

See [API documentation](https://api.aviationwx.org) for full endpoint details, authentication, and usage guidelines.

## Quick Start

### For Developers

```bash
# Clone repository
git clone https://github.com/alexwitherspoon/aviationwx.git
cd aviationwx

# Initialize and start
make init        # Create .env from example
make config      # Generate configs
cp config/airports.json.example config/airports.json
# Edit config/airports.json with your API keys
make up          # Start Docker containers

# Access: http://localhost:8080
```

See [Local Development Setup](docs/LOCAL_SETUP.md) for complete instructions.

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

## License

MIT License - See [LICENSE](LICENSE) file

## Data Sources

AviationWX uses the following external data sources:

- **Airport Identifiers (ICAO, IATA, FAA)**: Airport code validation uses data from [OurAirports](https://ourairports.com/data/) (Public Domain). OurAirports provides comprehensive airport data with 40,000+ airports worldwide, updated nightly. This data is used to validate ICAO, IATA, and FAA identifiers in the airport configuration.
- **ICAO Airport Codes (Legacy)**: For backward compatibility, airport code validation also supports data from [lxndrblz/Airports](https://github.com/lxndrblz/Airports) (CC-BY-SA-4.0 license) as a fallback. Note: This data source is incomplete and may not include all valid ICAO codes.

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
