# AviationWX - Local Development Commands

## 🚀 Quick Start

```bash
# Start the PHP server
php -S localhost:8000

# In another terminal, fetch webcam images
php fetch-webcam-safe.php

# Visit the page in your browser
open http://localhost:8000/?airport=kspb
```

## 📋 Available Commands

### 1. Start Server
```bash
php -S localhost:8000
```
Starts local development server on port 8000.

### 2. Fetch Webcam Images
```bash
php fetch-webcam-safe.php
```
Downloads current images from webcam streams and caches them.
- Safe memory usage (stops after first JPEG frame)
- Timeout protection (10 second max)
- Shows progress and results
- Caches to `cache/webcams/` directory

### 3. Check Weather API
```bash
curl -s 'http://localhost:8000/weather.php?airport=kspb' | python3 -m json.tool
```
Tests the weather API and shows formatted JSON response.

### 4. View Cached Images
```bash
ls -lh cache/webcams/
```
Lists cached webcam images with sizes and timestamps.

### 5. Test Full Page
Open in browser: http://localhost:8000/?airport=kspb

Shows:
- ✅ Airport information
- ✅ Real-time weather data
- ✅ Live clocks (Local & Zulu)
- ✅ Webcam images with "last updated" times
- ✅ Wind runway visualization

### 6. Quick Test Menu
```bash
./test-local.sh
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
├── airport-template.php         # Airport page template
├── weather.php                  # Weather API fetcher
├── webcam.php                   # Webcam image server
├── fetch-webcam-safe.php        # Safe webcam fetcher (run this!)
├── airports.json                # Airport configuration
├── styles.css                   # Styling
├── cache/
│   └── webcams/                 # Cached webcam images
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
curl 'http://localhost:8000/weather.php?airport=kspb'
```

**Server not responding?**
```bash
# Check if server is running
lsof -i :8000

# Restart if needed
pkill -f "php -S"
php -S localhost:8000
```

## 🎯 For Production (Bluehost)

When deploying, set up this cron job:
```bash
# Run every minute to update webcam images
* * * * * cd /path/to/aviationwx && php fetch-webcam-safe.php
```

This will keep the webcam images fresh automatically.

