# Local Development Setup

## Prerequisites

- Docker Desktop installed (Mac/Windows) or Docker + Docker Compose (Linux)
- Git

## Quick Start

### 1. Clone and Configure

```bash
# Clone the repository
git clone https://github.com/alexwitherspoon/aviationwx.git
cd aviationwx

# Initialize environment (creates .env from env.example)
make init
```

### 2. Edit Configuration

Edit `.env` with your settings:

```bash
# Open .env in your editor
nano .env
# or
code .env
```

**Minimum required changes**:
- Keep defaults for local development
- Domain setting only matters for production

### 3. Create airports.json

```bash
# Copy from example
cp airports.json.example airports.json

# Edit with your API keys
nano airports.json
```

**Important**: `airports.json` is gitignored and will not be pushed to GitHub.

### 4. Generate Configuration

```bash
# Generate nginx and other configs from .env
make config
```

### 5. Start Docker

```bash
# Build and start containers
make up

# Or use the full dev command (init + up + logs)
make dev
```

### 6. Test

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
make restart     # Restart containers
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
docker compose restart    # Restart to pick up changes

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
tail -f /var/log/apache2/error.log

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
   make restart
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
   docker compose restart
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
# Remove cache directory
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
├── cache/            # Runtime cache (gitignored)
├── docker-compose.yml
├── Dockerfile
├── Makefile          # Convenient commands
├── config/           # Generated configs
│   └── docker-config.sh
└── [application files...]
```

## Next Steps

Once local setup works:

1. **Test all features**:
   - Homepage loads
   - Airport page loads
   - Weather data displays
   - Webcam images work (if configured)

2. **Ready for production?**
   - See `DOCKER_DEPLOYMENT.md` for DigitalOcean setup
   - Or continue with Bluehost (current hosting)

3. **Deploy**:
   - Push to GitHub
   - Use GitHub Actions for automated deployment

## Environment Variables Explained

See `env.example` for full list. Key variables:

- `DOMAIN`: Your domain name (aviationwx.org)
- `APP_PORT`: Local port to use (default: 8080)
- `PHP_MEMORY_LIMIT`: PHP memory (default: 256M)
- `CACHE_ENABLED`: Enable caching (default: true)

