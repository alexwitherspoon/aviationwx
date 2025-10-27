# AviationWX Local Testing Guide

## Prerequisites

- PHP 7.4+ installed
- Terminal/Command Line access

## Quick Start

### 1. Configure Your API Key

Edit `airports.json` and add your Tempest API key:

```json
"weather_source": {
  "type": "tempest",
  "station_id": "149918",
  "api_key": "YOUR_ACTUAL_API_KEY_HERE"
}
```

### 2. Create Cache Directory

```bash
mkdir -p cache/webcams
```

### 3. Start Local Server

**Option A: Using index.php (recommended for testing subdomains)**

You'll need to add entries to your hosts file or use a subdomain setup. Since this is complex, let's use Option B first.

**Option B: Using test-local.php (easier for quick testing)**

```bash
php -S localhost:8000 test-local.php
```

Then visit:
- Homepage: http://localhost:8000
- KSPB test: http://localhost:8000?airport=kspb (we need to adjust this)

### 4. Test the Application

For testing the full subdomain functionality, you have a few options:

**Option 1: Add to /etc/hosts (macOS/Linux) or C:\Windows\System32\drivers\etc\hosts (Windows)**

```
127.0.0.1 kspb.aviationwx.local
127.0.0.1 aviationwx.local
```

Then run:
```bash
php -S aviationwx.local:8000
```

Visit:
- http://aviationwx.local:8000 (homepage)
- http://kspb.aviationwx.local:8000 (KSPB airport)

**Option 2: Use built-in server with routing**

```bash
php -S localhost:8000
```

Edit your `/etc/hosts` or `C:\Windows\System32\drivers\etc\hosts`:
```
127.0.0.1 localhost
127.0.0.1 kspb.localhost
```

Then configure your browser or use curl:
```bash
curl -H "Host: kspb.localhost" http://localhost:8000
```

## Testing Individual Components

### Test Weather API

```bash
php weather.php
```

Or visit in browser:
http://localhost:8000/weather.php?airport=kspb

### Test Webcam Caching

```bash
curl "http://localhost:8000/webcam.php?id=kspb&cam=0" -o test-cam.jpg
```

## File Structure

```
aviationwx.org/
├── airports.json          # Airport configuration
├── index.php             # Main router
├── airport-template.php   # Airport page template
├── weather.php           # Weather data fetcher
├── webcam.php            # Webcam image cacher
├── homepage.php          # Homepage
├── 404.php              # 404 error page
├── styles.css           # Stylesheet
├── .htaccess            # Apache configuration
├── test-local.php       # Local testing script
└── cache/               # Cache directory (gitignored)
    └── webcams/         # Cached webcam images
```

## Next Steps

1. Add your Tempest API key to `airports.json`
2. Start the server
3. Test the KSPB page
4. Verify weather data loads correctly
5. Check webcam caching works
6. Deploy to Bluehost when ready

## Troubleshooting

**Issue: Weather data not loading**
- Check your API key in airports.json
- Verify Tempest API endpoint is accessible
- Check PHP error logs

**Issue: Webcam images not showing**
- Make sure cache/webcams directory is writable
- Check that MJPEG streams are accessible
- Look for PHP errors

**Issue: Subdomain routing not working locally**
- Use the hosts file method (Option 1 above)
- Or test individual components separately

## Bluehost Deployment Checklist

- [ ] Upload all files to public_html
- [ ] Set up subdomain wildcard DNS: *.aviationwx.org
- [ ] Create cache/webcams directory
- [ ] Set proper file permissions
- [ ] Configure .htaccess (already included)
- [ ] Test subdomain routing
- [ ] Set up cron job for webcam caching (optional)

