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
- 🤝 **[Contributing](CONTRIBUTING.md)** - How to contribute
- 🔒 **[Security](docs/SECURITY.md)** - Security best practices
- 📊 **[Operations](docs/OPERATIONS.md)** - Logging, monitoring, troubleshooting

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

### User Experience
- **Wind Visualization**: Interactive runway wind diagram with parallel runway support
- **Unit Toggles**: Switch between temperature (F/C), distance (ft/m), and wind speed (kts/mph/km/h) units
- **Weather Status Emojis**: Visual indicators for abnormal conditions
- **Mobile-First Design**: Responsive layout for all devices
- **Offline Support**: Service worker for offline access

### Technical Features
- **Performance**: Config caching (APCu), HTTP cache headers, rate limiting
- **Security**: Input validation, rate limiting, sanitized error messages
- **SEO**: Dynamic sitemap, structured data (JSON-LD), Open Graph tags
- **Monitoring**: Status page with real-time system health
- **VPN Support**: Optional VPN for accessing private camera networks

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
3. **Email alex@alexwitherspoon.com** to participate in AviationWX.org and we'll get it added.

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

## Documentation

### User Guides
- **[Configuration Guide](docs/CONFIGURATION.md)** - Add airports, configure weather sources, set up webcams
- **[Local Development](docs/LOCAL_SETUP.md)** - Set up development environment
- **[Local Commands](docs/LOCAL_COMMANDS.md)** - Quick reference for minimal PHP setup

### Technical Documentation
- **[Architecture](docs/ARCHITECTURE.md)** - System design, components, data flow
- **[API Documentation](docs/API.md)** - API endpoints, request/response formats
- **[Deployment Guide](docs/DEPLOYMENT.md)** - Production deployment, CI/CD, maintenance
- **[VPN Setup](docs/VPN.md)** - VPN configuration for remote sites (optional)
- **[Operations](docs/OPERATIONS.md)** - Logging, monitoring, troubleshooting

### Contributing & Security
- **[Contributing](CONTRIBUTING.md)** - How to contribute code, report bugs, suggest features
- **[Code Style Guide](CODE_STYLE.md)** - Coding standards, comment guidelines, and testing requirements
- **[Code of Conduct](CODE_OF_CONDUCT.md)** - Community guidelines
- **[Security](docs/SECURITY.md)** - Security best practices and guidelines

## Weather Sources

### Tempest Weather
```json
"weather_source": {
  "type": "tempest",
  "station_id": "YOUR_STATION_ID",
  "api_key": "YOUR_API_KEY"
}
```

### Ambient Weather
```json
"weather_source": {
  "type": "ambient",
  "api_key": "YOUR_API_KEY",
  "application_key": "YOUR_APPLICATION_KEY"
}
```

### Davis WeatherLink
```json
"weather_source": {
  "type": "weatherlink",
  "api_key": "YOUR_API_KEY",
  "api_secret": "YOUR_API_SECRET",
  "station_id": "YOUR_STATION_ID"
}
```

### METAR
```json
"weather_source": {
  "type": "metar"
}
```
No API key required. Can supplement other sources for visibility/ceiling data.

See [Configuration Guide](docs/CONFIGURATION.md) for detailed setup instructions.

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

## Contributing

We welcome contributions! See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

**Quick start:**
1. Fork the repository
2. Set up local development (see [docs/LOCAL_SETUP.md](docs/LOCAL_SETUP.md))
3. Create a branch for your changes
4. Submit a Pull Request

Please read our [Code of Conduct](CODE_OF_CONDUCT.md) before contributing.

## Support

For issues and questions:
- Open an issue on [GitHub](https://github.com/alexwitherspoon/aviationwx/issues)
- Check the [documentation](docs/)
- Review [existing issues](https://github.com/alexwitherspoon/aviationwx/issues) for similar problems

---

**Made for pilots, by pilots** ✈️
