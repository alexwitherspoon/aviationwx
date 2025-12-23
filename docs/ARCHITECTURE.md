# Architecture Overview

This document provides an overview of the AviationWX.org codebase structure and architecture.

## Project Structure

```
aviationwx.org/
├── index.php                 # Main router - handles subdomain/query routing
├── pages/
│   ├── airport.php           # Airport page template with weather display
│   ├── homepage.php          # Homepage with airport list
│   ├── status.php            # Status page
│   ├── config-generator.php  # Configuration generator
│   ├── error-404.php         # 404 error page
│   └── error-404-airport.php # Airport-specific 404 page
├── api/
│   ├── weather.php           # Weather API endpoint
│   ├── webcam.php            # Webcam image server endpoint
│   ├── sitemap.php           # Dynamic XML sitemap generator
│   ├── outage-status.php     # Data outage status API
│   └── partner-logo.php     # Partner logo caching endpoint
├── lib/
│   ├── config.php            # Configuration loading and utilities
│   ├── rate-limit.php        # Rate limiting utilities
│   ├── logger.php            # Logging utilities
│   ├── seo.php               # SEO utilities (structured data, meta tags)
│   ├── constants.php         # Application constants
│   ├── circuit-breaker.php   # Circuit breaker for API failures
│   ├── airport-identifiers.php # Airport code validation
│   ├── address-formatter.php # Address formatting utilities
│   ├── partner-logo-cache.php # Partner logo caching
│   ├── push-webcam-validator.php # Push webcam validation
│   ├── vpn-routing.php       # VPN routing utilities
│   ├── webcam-error-detector.php # Webcam error frame detection
│   ├── webcam-format-generation.php # Shared format generation (WebP, AVIF, JPEG)
│   └── weather/
│       ├── fetcher.php       # Weather data fetching
│       ├── calculator.php    # Aviation calculations
│       ├── daily-tracking.php # Daily high/low tracking
│       ├── staleness.php     # Data staleness handling
│       ├── source-timestamps.php # Timestamp extraction
│       └── adapter/          # Weather API adapters
├── scripts/
│   ├── scheduler.php         # Combined scheduler daemon (weather, webcam, NOTAM)
│   ├── scheduler-health-check.php # Scheduler health check (runs via cron)
│   ├── fetch-webcam.php      # Webcam fetcher (worker mode for scheduler)
│   ├── fetch-weather.php     # Weather fetcher (worker mode for scheduler)
│   ├── fetch-notam.php       # NOTAM fetcher (worker mode for scheduler)
│   └── process-push-webcams.php # Push webcam processor (runs via cron)
├── admin/
│   ├── diagnostics.php       # System diagnostics endpoint
│   ├── cache-clear.php       # Cache clearing endpoint
│   ├── cache-diagnostics.php # Cache diagnostics
│   └── metrics.php           # Application metrics
├── health/
│   ├── health.php            # Health check endpoint
│   └── ready.php             # Readiness check endpoint
├── config/
│   ├── airports.json.example # Configuration template
│   └── crontab               # Cron job definitions
├── public/
│   ├── css/styles.css        # Application styles
│   ├── js/service-worker.js  # Service worker for offline support
│   └── favicons/             # Favicon files
├── docker/                   # Docker configuration files
└── tests/                    # Test files
```

## Core Components

### Routing System (`index.php`)

- **Purpose**: Routes requests to appropriate pages
- **Logic**: 
  - Extracts airport ID from subdomain or query parameter
  - Validates airport exists in configuration
  - Loads airport-specific template
  - Shows homepage if no airport specified

### Weather System (`api/weather.php`)

- **Purpose**: Fetches and serves weather data as JSON API
- **Key Features**:
  - Supports multiple weather sources (Tempest, Ambient, WeatherLink, METAR)
  - Parallel fetching via `curl_multi` (when supported by source)
  - Per-source staleness checking (3-hour threshold)
  - Caching with stale-while-revalidate
  - Rate limiting
  - Comprehensive logging

**Data Flow**:
1. Request validation (airport ID, rate limiting)
2. Cache check (fresh/stale/expired)
3. Data fetching (parallel primary + METAR)
4. Data merging and processing
5. Staleness checking (per-source)
6. Response with appropriate cache headers

### Webcam System

**`api/webcam.php`**: Serves cached webcam images
- Handles image requests with cache headers
- Returns placeholder if image missing
- Supports multiple formats (AVIF, WebP, JPEG) with content negotiation
- Format priority: explicit fmt parameter → AVIF → WebP → JPEG
- **Background refresh**: Serves stale cache immediately, refreshes in background (similar to weather)

**`scripts/scheduler.php`**: Combined scheduler daemon for data refresh
- Runs continuously as background process (started on container boot)
- Handles weather, webcam, and NOTAM updates with sub-minute granularity
- Supports configurable refresh intervals (minimum 5 seconds, 1-second granularity)
- Non-blocking main loop with ProcessPool integration
- Automatically reloads configuration changes without restart

**`scripts/fetch-webcam.php`**: Fetches and caches webcam images (worker mode)
- Called by scheduler in `--worker` mode for individual airport/camera updates
- Can also be run manually for testing
- Safe memory usage (stops after first frame)
- Supports: Static images, MJPEG streams, RTSP/RTSPS (via ffmpeg), push uploads (SFTP/FTP/FTPS)
- Generates multiple formats per image (JPEG, WebP, AVIF)
- Format generation runs asynchronously (non-blocking)
- Mtime automatically synced to match source image's capture time
- Can be included by `api/webcam.php` for background refresh functionality
- **Reliability features**:
  - File locking for backoff state (prevents race conditions)
  - Atomic file writes (prevents cache corruption)
  - Circuit breaker with exponential backoff
  - Comprehensive error handling and logging

### Configuration System (`lib/config.php`)

- **Purpose**: Loads and validates airport configuration
- **Features**:
  - Caching via APCu
  - Automatic cache invalidation on file change
  - Validation functions
  - Airport ID extraction from requests

### SEO System (`lib/seo.php`, `api/sitemap.php`)

- **Purpose**: Search engine optimization and indexing
- **Features**:
  - Dynamic XML sitemap generation (`/sitemap.xml`)
  - Structured data (JSON-LD) for search engines
    - Organization schema for homepage
    - LocalBusiness schema for airport pages
  - Open Graph and Twitter Card tags for social sharing
  - Canonical URLs to prevent duplicate content
  - Enhanced meta tags (keywords, author, description)
- **Sitemap**: Automatically includes homepage, status page, and all airport subdomains

### Frontend (`pages/airport.php`)

- **Structure**: Single-page template with embedded JavaScript
- **Features**:
  - Dynamic weather data display
  - Unit toggles (temperature, distance, wind speed)
  - Wind visualization (Canvas-based)
  - Service worker for offline support
  - Responsive design

**Key JavaScript Functions**:
- `fetchWeather()`: Fetches weather data
- `displayWeather()`: Renders weather data
- `updateWindVisual()`: Updates wind visualization with parallel runway support
- `parseRunwayName()`: Extracts L/C/R designations from runway names
- `groupParallelRunways()`: Groups parallel runways by similar headings
- `calculateRunwayOffset()`: Calculates horizontal offset for parallel runways
- Unit conversion functions
- Timestamp formatting

## Data Flow

### Weather Data Flow

```
Request → index.php/router
  ↓
weather.php endpoint
  ↓
Cache Check (fresh/stale/expired)
  ↓
[If stale] Serve stale + trigger background refresh
  ↓
Fetch Primary Source (Tempest/Ambient/WeatherLink) + METAR (parallel when possible)
  ↓
Parse and merge data
  ↓
Calculate aviation metrics (density altitude, flight category)
  ↓
Daily tracking (high/low temps, peak gust)
  ↓
Staleness check (per-source)
  ↓
Response (JSON) + Cache
```

### Webcam Data Flow

```
Scheduler Daemon (background process) → scripts/fetch-webcam.php (worker mode)
Cron (every 60s) → scripts/scheduler-health-check.php (monitors scheduler)
  ↓
For each webcam:
  ↓
Fetch image (HTTP/MJPEG/RTSP)
  ↓
Generate formats (JPEG, WebP, AVIF) - async, non-blocking
  ↓
Save to cache/webcams/
  ↓

User Request → webcam.php
  ↓
Check cache for requested image
  ↓
[If fresh] Serve with cache headers (HIT)
  ↓
[If stale] Serve stale cache immediately + trigger background refresh
  ↓
Background: Fetch fresh image + update cache
  ↓
Next request gets fresh image
```

## Key Design Decisions

### 1. Per-Source Staleness Checking

- **Why**: Preserves valid data from one source when another is stale
- **Implementation**: Separate timestamps for `last_updated_primary` and `last_updated_metar`
- **Benefit**: Maximum data visibility even with partial failures

### 2. Stale-While-Revalidate Caching

- **Why**: Fast responses while keeping data fresh
- **Implementation**: Serve stale cache immediately, refresh in background
- **Benefit**: Low latency with eventual consistency
- **Applied to**: Weather data and webcam images (both use background refresh)

### 3. Daily Tracking Values Never Stale

- **Why**: Historical data for the day is always valid
- **Implementation**: Excluded from staleness checks
- **Benefit**: Useful context even with stale current readings

### 4. Parallel Data Fetching

- **Why**: Reduce latency when fetching from multiple sources
- **Implementation**: `curl_multi` for parallel HTTP requests
- **Benefit**: Faster responses, better user experience

### 5. Multiple Image Formats

- **Why**: Browser compatibility and performance
- **Implementation**: Generate JPEG, WebP, and AVIF formats, serve via content negotiation
- **Format Priority**: AVIF (best compression) → WebP (good compression) → JPEG (fallback)
- **Generation**: Fully async (non-blocking) using `exec() &`
- **Mtime Sync**: Automatically synced to match source image's capture time
- **Benefit**: Best format per browser, smaller file sizes, better quality

## Security Considerations

- **Input Validation**: All user input validated and sanitized
- **Rate Limiting**: Prevents abuse (60/min weather, 100/min webcams)
- **Credential Protection**: API keys never exposed to frontend
- **File Permissions**: Sensitive files properly protected
- **Error Messages**: Sanitized to prevent information leakage

See [SECURITY.md](docs/SECURITY.md) for detailed security information.

## Caching Strategy

- **Configuration**: APCu memory cache (invalidates on file change)
- **Weather Data**: File-based cache with stale-while-revalidate
- **Webcam Images**: File-based cache with stale-while-revalidate (refreshed via cron inside container + background refresh)
  - Atomic writes to prevent corruption (write to `.tmp` then atomic `rename()`)
  - File locking for concurrent access safety
- **HTTP Headers**: Appropriate cache-control headers with stale-while-revalidate

## Reliability Features

### File Locking
- Backoff state (`backoff.json`) uses file locking (`flock()`) to prevent race conditions
- Read-modify-write operations are atomic when locking is available
- Graceful fallback to `file_put_contents()` with `LOCK_EX` if file handles unavailable

### Atomic File Writes
- Cache files (webcam images) are written atomically:
  1. Write to temporary file (`.tmp` suffix)
  2. Atomic `rename()` to final location
- Prevents serving corrupted images if process is interrupted
- Applied to: JPEG cache files, PNG-to-JPEG conversions, MJPEG stream captures, RTSP frame captures

### Circuit Breaker
- Exponential backoff prevents hammering failing sources
- Separate backoff state per camera
- Severity-based scaling (permanent errors get longer backoff)
- Automatic reset on successful fetch

## Deployment

- **Docker-based**: Containerized for consistent deployment
- **GitHub Actions**: Automated CI/CD pipeline
- **Production**: Docker Compose on DigitalOcean Droplet
- **DNS**: Wildcard subdomain support

See [DEPLOYMENT.md](DEPLOYMENT.md) for deployment details.

## Extending the System

### Adding a New Weather Source

1. Add parser function (e.g., `parseNewSourceResponse()`)
2. Add fetch function (e.g., `fetchNewSourceWeather()`)
3. Update `fetchWeatherAsync()` or `fetchWeatherSync()` to use new source
4. Update configuration documentation
5. Add to `CONFIGURATION.md`

### Adding a New Airport

1. Add entry to `airports.json`
2. Configure weather source and webcams
3. Set up DNS for subdomain
4. No code changes required (fully dynamic)

### Adding New Weather Metrics

1. Add calculation function in `api/weather.php`
2. Update `$weatherData` array with new field
3. Update `pages/airport.php` to display new metric
4. Document in README.md

## Testing

- **Manual Testing**: `dev/router.php` for local development
- **Endpoint Testing**: Direct API endpoint testing
- **Diagnostics**: `/diagnostics.php` for system health

See [LOCAL_SETUP.md](docs/LOCAL_SETUP.md) for testing instructions.

## Performance Considerations

- **Caching**: Multiple cache layers (APCu, file cache, HTTP cache)
- **Parallel Requests**: Async fetching when possible
- **Image Optimization**: Multiple formats, efficient generation
- **Rate Limiting**: Prevents resource exhaustion
- **Background Processing**: Stale-while-revalidate reduces blocking

## Monitoring

- **Logging**: Comprehensive logging via `lib/logger.php` (writes to stdout/stderr for Docker logging)
- **Docker Logs**: All logs captured by Docker with automatic rotation (10MB files, 10 files = 100MB total)
- **Metrics**: `/metrics.php` endpoint for monitoring
- **Health Checks**: `/health.php` for uptime monitoring
- **Diagnostics**: `/diagnostics.php` for system information
- **Status Page**: `/status.php` for system health overview

## Future Improvements

Potential areas for enhancement:

- **Unit Tests**: Comprehensive test suite
- **API Documentation**: OpenAPI/Swagger spec
- **GraphQL API**: More flexible data queries
- **Real-time Updates**: WebSocket support
- **Mobile App**: Native mobile applications
- **Historical Data**: Data archive and trends

