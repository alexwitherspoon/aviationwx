# AviationWX - Local Development Commands

## 🚀 Quick Start

```bash
# Start the PHP server
php -S localhost:8080

# In another terminal, fetch webcam images
php fetch-webcam-safe.php

# Visit the page in your browser
open http://localhost:8080/?airport=kspb
```

## 📋 Available Commands

### 1. Start Server
```bash
php -S localhost:8080
```
Starts local development server on port 8080.

### 2. Fetch Webcam Images
```bash
php fetch-webcam-safe.php
```
Downloads current images from webcam streams and caches them.
- Safe memory usage (stops after first JPEG frame)
- Timeout protection (10 second max)
- Shows progress and results
- Caches to the cache directory (location depends on deployment - see [Local Setup](LOCAL_SETUP.md) or [Deployment Guide](DEPLOYMENT.md))

### 3. Check Weather API
```bash
curl -s 'http://localhost:8080/weather.php?airport=kspb' | python3 -m json.tool
```
Tests the weather API and shows formatted JSON response.

### 4. View Cached Images
```bash
# List cached webcam images (cache location depends on deployment)
ls -lh cache/webcams/
```
Lists cached webcam images with sizes and timestamps.

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

## 🔄 Update Webcam Images

The webcam images are cached for performance. To refresh them:

```bash
# Fetch fresh images from streams
php fetch-webcam-safe.php

# The "last updated" times will show when the fetch was executed
# Times display as: "X minutes ago" or "X hours ago"
```

## 📁 Directory Structure

```
aviationwx.org/
├── index.php                    # Main router
├── pages/airport.php             # Airport page template
├── weather.php                  # Weather API fetcher
├── webcam.php                   # Webcam image server
├── fetch-webcam-safe.php        # Safe webcam fetcher (run this!)
├── airports.json                # Airport configuration
├── styles.css                   # Styling
# Cache directory structure (location depends on deployment)
# cache/webcams/  # Cached webcam images
│       ├── kspb_0.jpg          # Webcam 1
│       └── kspb_1.jpg          # Webcam 2
└── ...
```

## ⏰ How "Last Updated" Works

1. When you run `php fetch-webcam-safe.php`, it captures images and saves them
2. Each image file has a modification timestamp (when it was downloaded)
3. The page reads this timestamp and displays it as "X minutes ago"
4. The timestamp updates every minute automatically

## 🛠️ Troubleshooting

**Webcam images not showing?**
```bash
# Fetch the images first
php fetch-webcam-safe.php

# Then reload the page
```

**Weather data not loading?**
```bash
# Check the API response
curl 'http://localhost:8080/weather.php?airport=kspb'
```

**Server not responding?**
```bash
# Check if server is running
lsof -i :8080

# Restart if needed
pkill -f "php -S"
php -S localhost:8080
```

## 🎯 For Production (Docker Droplet)

**Cron jobs run automatically inside the Docker container** - no setup required!

The Docker container includes cron jobs that run:
- Webcam refresh every minute
- Weather refresh every minute

Both jobs are configured in the `crontab` file that's built into the Docker image and start automatically when the container starts.

