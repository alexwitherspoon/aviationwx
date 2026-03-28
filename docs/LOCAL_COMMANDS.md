# AviationWX - Local Development Commands

## 🚀 Quick Start

**IMPORTANT: Always use Docker for local development, NOT direct PHP processes.**

```bash
# Start the Docker development environment
make dev

# Visit the page in your browser
open http://localhost:8080/?airport=kspb
```

## 📋 Available Commands

### 1. Start Docker Environment
```bash
make dev          # Build and start Docker containers, then tail logs
make up           # Start containers (builds if needed)
make down         # Stop containers
make restart      # Restart containers
```

### 2. Access Container Shell
```bash
# Open a shell inside the container
make shell

# Inside container, you can run PHP scripts:
cd /var/www/html
php scripts/fetch-webcam.php --worker kspb 0
```

### 3. Check Weather API
```bash
curl -s 'http://localhost:8080/api/weather.php?airport=kspb' | python3 -m json.tool
```

### 4. View Cached Images
```bash
# List cached webcam images
docker compose -f docker/docker-compose.yml exec web ls -lh /var/www/html/cache/webcams/*/
```

### 5. Test Full Page
Open in browser: http://localhost:8080/?airport=kspb

Shows:
- ✅ Airport information
- ✅ Real-time weather data
- ✅ Live clocks (Local & Zulu)
- ✅ Webcam images with "last updated" times
- ✅ Wind runway visualization

### 6. Quick Test Menu
```bash
./dev/test.sh
```
Interactive menu for testing different aspects.

## 🔄 Webcam Updates

The scheduler daemon runs automatically inside the Docker container and handles webcam updates.

```bash
# Check scheduler status
docker compose -f docker/docker-compose.yml exec web cat /tmp/scheduler.lock | jq

# Manually trigger webcam update for specific airport/camera
docker compose -f docker/docker-compose.yml exec -T web php scripts/fetch-webcam.php --worker kspb 0
```

## 📁 Directory Structure

```
aviationwx.org/
├── index.php                    # Main router
├── pages/airport.php            # Airport page template
├── api/weather.php              # Weather API fetcher
├── api/webcam.php               # Webcam image server
├── config/airports.json         # Airport configuration
├── public/css/styles.css        # Styling
├── cache/                       # Cache directory
│   └── webcams/{airport}/{cam}/ # Cached webcam images
└── ...
```

## ⏰ How "Last Updated" Works

1. The scheduler daemon captures webcam images at configured intervals
2. Each image file has a modification timestamp (when it was downloaded)
3. The page reads this timestamp and displays it as "X minutes ago"
4. The timestamp updates every minute automatically

## 🛠️ Troubleshooting

**Webcam images not showing?**
```bash
# Check if scheduler is running
docker compose -f docker/docker-compose.yml exec web ps aux | grep scheduler

# Check scheduler lock file
docker compose -f docker/docker-compose.yml exec web cat /tmp/scheduler.lock

# Manually trigger webcam fetch
docker compose -f docker/docker-compose.yml exec -T web php scripts/fetch-webcam.php --worker kspb 0
```

**Weather data not loading?**
```bash
# Check the API response
curl 'http://localhost:8080/api/weather.php?airport=kspb'
```

**Container not running?**
```bash
# Check container status
docker compose -f docker/docker-compose.yml ps

# View logs
make logs

# Restart containers
make restart
```

## 🎯 For Production (Docker on the server)

**The scheduler daemon runs automatically inside the Docker container** - no setup required!

The Docker container includes:
- **Scheduler daemon**: Starts automatically on container boot, handles all data refresh tasks
- **Scheduler health check**: Runs every minute via cron to ensure scheduler stays running
- **Push webcam processing**: Runs every minute via cron to process uploaded images

The scheduler supports sub-minute refresh intervals (minimum 5 seconds) and automatically reloads configuration changes without restart. All refresh intervals are configurable via `airports.json`.

## ❌ DO NOT USE

- `php -S localhost:8080` - Never run PHP's built-in server directly
- Direct PHP execution outside Docker for web testing
