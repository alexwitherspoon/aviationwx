# Local Development Setup

Complete guide for setting up AviationWX.org for local development and testing.

## Prerequisites

- **Docker Desktop** (Mac/Windows) or **Docker + Docker Compose** (Linux)
- **Git** installed
- **Text editor** for configuration files

## Quick Start

There are two setup paths depending on your access level:

### Option A: With Production Config (Maintainers)

For developers with access to the secrets repository:

```bash
# 1. Clone both repositories
git clone https://github.com/alexwitherspoon/aviationwx.git
git clone git@github.com:org/aviationwx.org-secrets.git

cd aviationwx

# 2. Copy and configure the override file
cp docker/docker-compose.override.yml.example docker/docker-compose.override.yml
```

Edit `docker-compose.override.yml` to mount your secrets:

```yaml
services:
  web:
    volumes:
      # ... (keep existing source mounts) ...
      # Add secrets mounts:
      - /path/to/aviationwx.org-secrets/airports.json:/var/www/html/secrets/airports.json:ro
      - /path/to/aviationwx.org-secrets/partner-logos:/var/www/html/partner-logos:ro
    environment:
      - CONFIG_PATH=/var/www/html/secrets/airports.json
```

```bash
# 3. Start development server
make dev
```

This setup uses real API keys, real webcam sources, and partner logos.

### Option B: With Mock Data (Contributors)

For developers without access to production secrets:

```bash
# 1. Clone the repository
git clone https://github.com/alexwitherspoon/aviationwx.git
cd aviationwx

# 2. Copy example config (mock mode auto-activates)
make config-example
# Or manually: cp config/airports.json.example config/airports.json

# 3. Start development server
make dev
```

**Mock mode** automatically activates because the example config contains `test_` API keys and `example.com` URLs. In mock mode:
- Weather data returns simulated values
- Webcams display placeholder images with airport ID
- All UI features work for development
- No real API calls are made

### Verify Your Setup

```bash
# Check configuration status
make config-check

# Expected output for mock mode:
# Config file: /path/to/config/airports.json
# Test mode: NO
# Mock mode: YES (external services will be mocked)
# Production: NO
# Airports: 5 (kspb, kczk, kpfc, cust, 4or9)
```

## Access the Application

Visit in your browser:
- Homepage: http://localhost:8080
- Airport page: http://localhost:8080/?airport=kspb

## Available Commands

```bash
make help        # Show all available commands
make init        # Create .env from env.example
make config      # Generate config from .env
make build       # Build Docker images
make up          # Start containers
make down        # Stop containers
make restart     # Restart containers (quick restart)
make restart-env # Restart and recreate containers (picks up env var changes)
make logs        # View logs (Ctrl+C to exit)
make shell       # Open shell in container
make test        # Test the application
make clean       # Remove containers and cleanup
```

## Configuration Files

| File | Purpose | Git Tracked? |
|------|---------|--------------|
| `env.example` | Template environment configuration | ✅ Yes |
| `.env` | Your local environment config | ❌ No (.gitignore) |
| `airports.json.example` | Template airport configuration | ✅ Yes |
| `airports.json` | Your airport config with API keys | ❌ No (.gitignore) |
| `config/` | Generated configs | ✅ Yes |

## Development Workflow

### Starting Development

```bash
# First time setup
make init            # Create .env
make config          # Generate configs
make up              # Start Docker

# Daily development
make up              # Start containers
make logs            # Watch logs

# When done
make down            # Stop containers
```

### Testing Changes

```bash
# After making code changes
make restart              # Quick restart (for code changes)

# After changing environment variables in docker-compose.local.yml
make restart-env           # Recreate containers to pick up env var changes

# Or rebuild if Dockerfile changed
make build && make up

# View logs to debug
make logs
```

### Debugging

```bash
# Open shell in container
make shell

# Inside container:
cd /var/www/html
php -v
ls -la

# View logs (from host, not inside container)
# Logs are captured by Docker and can be viewed with:
docker compose logs -f web

# Exit shell
exit
```

## Port Configuration

Default port is `8080`. To change:

1. Edit `.env`:
   ```bash
   APP_PORT=3000
   ```

2. Restart:
   ```bash
   make restart-env  # Use restart-env for environment variable changes
   ```

3. Access: http://localhost:3000

## Adding New Airports

### Local Development

1. Edit `airports.json`:
   ```json
   {
     "airports": {
       "kspb": { ... },
       "kxxx": { ... }  // Add new airport
     }
   }
   ```

2. Restart container:
   ```bash
   make restart-env  # Recreate to pick up config changes
   ```

3. Test:
   - http://localhost:8080/?airport=kxxx

### Production (Future)

Same process, but edit `airports.json` on the server.

## Troubleshooting

### Container won't start

```bash
# Check logs
make logs

# Common issues:
# - Port 8080 already in use
# - Missing airports.json
# - .env not configured
```

### Fix port conflict

Edit `.env`:
```bash
APP_PORT=3000
```

Then restart:
```bash
make restart
```

### Missing airports.json

```bash
# Copy from example
cp airports.json.example airports.json

# Edit with real credentials
nano airports.json

# Restart
make restart
```

### Clear cache

```bash
# Remove cache directory (location depends on deployment)
rm -rf cache/

# Restart
make restart
```

### Reset Everything

```bash
# WARNING: This removes all containers and volumes
make clean

# Start fresh
make init
make up
```

## Directory Structure

```
aviationwx.org/
├── .env              # Your configuration (gitignored)
├── airports.json     # Your API config (gitignored)
# Cache directory (location depends on deployment - see [Deployment Guide](DEPLOYMENT.md) for production paths)
├── docker-compose.yml
├── Dockerfile
├── Makefile          # Convenient commands
├── config/           # Generated configs
│   └── docker-config.sh
└── [application files...]
```

## Testing Checklist

Once local setup is complete, verify:

- ✅ Homepage loads: http://localhost:8080
- ✅ Airport page loads: http://localhost:8080/?airport=kspb
- ✅ Weather data displays correctly
- ✅ Unit toggles work (temperature, distance, wind speed)
- ✅ Webcam images display (if configured)
- ✅ Wind visualization works
- ✅ All features functioning

## Next Steps

1. **Continue Development**:
   - Make code changes
   - Test locally
   - Commit changes
   - Push to GitHub

2. **Ready for Production?**
   - See [DEPLOYMENT.md](DEPLOYMENT.md) for complete production deployment guide

3. **Contributing**:
   - See [CONTRIBUTING.md](../CONTRIBUTING.md) for contribution guidelines
   - Follow coding standards
   - Submit Pull Requests

## Environment Variables Explained

The `.env` file is **only for Docker/infrastructure configuration**, not application settings.

See `config/env.example` for full list. Key Docker/infrastructure variables:

- `DOMAIN`: Your domain name (aviationwx.org)
- `APP_PORT`: Local port to use (default: 8080)
- `PHP_MEMORY_LIMIT`: PHP memory (default: 256M)
- `COMPOSE_PROJECT_NAME`: Docker Compose project name
- `SSL_ENABLED`: Enable SSL for local dev (default: false)

**Note:** Application defaults (timezone, refresh intervals, etc.) are configured in the `config` section of `airports.json`, not in `.env`. See [CONFIGURATION.md](CONFIGURATION.md) for details.

**Magnetic declination:** For automatic runway wind diagram alignment, add `geomag_api_key` to the `config` section of `airports.json`. [Register free](https://www.ngdc.noaa.gov/geomag/CalcSurvey.shtml). Without it, declination uses config override or 0.

