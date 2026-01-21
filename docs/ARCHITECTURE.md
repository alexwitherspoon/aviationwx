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
│   ├── partner-logo.php      # Partner logo caching endpoint
│   └── v1/                   # Versioned public API
│       └── version.php       # Deployment version info for client checking
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
│   ├── webcam-error-detector.php # Webcam image validation (error frames, pixelation, uniform color)
│   ├── webcam-format-generation.php # Shared format generation (WebP, JPEG)
│   ├── exif-utils.php        # EXIF timestamp reading, writing, and validation
│   └── weather/
│       ├── UnifiedFetcher.php # Unified weather fetch pipeline
│       ├── WeatherAggregator.php # Multi-source aggregation logic
│       ├── AggregationPolicy.php # Aggregation rules and preferences
│       ├── calculator.php    # Aviation calculations
│       ├── daily-tracking.php # Daily high/low tracking
│       ├── cache-utils.php   # Cache staleness utilities
│       ├── source-timestamps.php # Timestamp extraction
│       ├── utils.php         # Weather utilities (timezone, sunrise/sunset, daylight phases)
│       ├── data/             # Data classes
│       │   ├── WeatherSnapshot.php # Complete weather state from source
│       │   ├── WeatherReading.php  # Single field measurement
│       │   └── WindGroup.php       # Grouped wind fields
│       └── adapter/          # Weather API adapters
├── scripts/
│   ├── scheduler.php         # Combined scheduler daemon (weather, webcam, NOTAM)
│   ├── scheduler-health-check.php # Scheduler health check (runs via cron)
│   ├── unified-webcam-worker.php # Unified webcam worker (handles both pull and push cameras)
│   ├── fetch-weather.php     # Weather fetcher (worker mode for scheduler)
│   └── fetch-notam.php       # NOTAM fetcher (worker mode for scheduler)
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
│   ├── version.json          # Deploy version info (generated, gitignored)
│   └── crontab               # Cron job definitions
├── public/
│   ├── css/styles.css        # Application styles
│   ├── js/
│   │   ├── service-worker.js # Service worker for offline support
│   │   └── timer-lifecycle.js # Timer lifecycle management (deferred)
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
  - Supports multiple weather sources (Tempest, Ambient, WeatherLink, PWSWeather, SynopticData, METAR)
  - **Unified Fetcher** (default): Clean aggregation pipeline with predictable behavior
  - Parallel fetching via `curl_multi` for all sources
  - Field-level source tracking and observation times
  - Per-source staleness checking (3-hour threshold for sensors, METAR has own threshold)
  - Caching with stale-while-revalidate
  - Rate limiting
  - Debug endpoint (`?debug=1`) for troubleshooting
  - Legacy fallback (`?legacy=1`) for backward compatibility

**Data Flow** (Unified Fetcher):
1. Request validation (airport ID, rate limiting)
2. Cache check (fresh/stale/expired)
3. Build source list (primary + backup + METAR)
4. Fetch all sources in parallel via `curl_multi`
5. Parse responses into `WeatherSnapshot` objects
6. Aggregate using `WeatherAggregator` with freshness-based selection:
   - Wind fields must come from single source (complete group)
   - Freshest valid data wins for each field
   - METAR typically provides ceiling and cloud_cover (other sources do not)
7. Add calculated fields (flight category, altitudes, dewpoint spread)
8. Daily tracking (temp extremes, peak gust)
9. Cache and serve response

### Webcam System

**`api/webcam.php`**: Serves cached webcam images
- Handles image requests with cache headers
- Returns placeholder if image missing
- Supports multiple formats (WebP, JPEG) with content negotiation
- Format priority: explicit fmt parameter → WebP → JPEG
- **Background refresh**: Serves stale cache immediately, refreshes in background (similar to weather)

**`scripts/scheduler.php`**: Combined scheduler daemon for data refresh
- Runs continuously as background process (started on container boot)
- Handles weather, webcam, and NOTAM updates with sub-minute granularity
- Supports configurable refresh intervals (minimum 5 seconds, 1-second granularity)
- Non-blocking main loop with ProcessPool integration
- Automatically reloads configuration changes without restart

**`scripts/unified-webcam-worker.php`**: Unified webcam worker (handles both pull and push cameras)
- Called by scheduler in `--worker` mode for individual airport/camera updates
- Replaces the previous separate `fetch-webcam.php` (pull) and `process-push-webcams.php` (push) workers
- **Architecture**: Uses Strategy Pattern with three main components:
  - `AcquisitionStrategy` interface with `PullAcquisitionStrategy` and `PushAcquisitionStrategy` implementations
  - `ProcessingPipeline` class for standardized image validation, variant generation, and promotion
  - `WebcamWorker` class that orchestrates acquisition and processing
- **Pull cameras**: Supports Static images, MJPEG streams, RTSP/RTSPS (via ffmpeg), federated API
- **Push cameras**: Processes FTP/SFTP uploads with adaptive stability detection and batch processing
  - Batch processing: Processes up to 30 files per run to clear backlogs efficiently
  - Processing order: Newest first (pilot safety), then oldest-to-newest (prevent aging out)
  - Extended timeout: 5 minutes when ≥10 files pending
- Generates multiple formats per image (JPEG, WebP) and variants (original, 1080p, 720p, 360p)
- Single image load through pipeline (loads GD resource once, passes through all validation steps)
- Mtime automatically synced to match source image's capture time
- **Reliability features**:
  - Hybrid lock strategy: ProcessPool primary, `flock()` file locks for crash resilience
  - Orphaned staging file cleanup (removes incomplete files from crashed workers)
  - Circuit breaker with exponential backoff
  - Atomic file writes (prevents cache corruption)
  - Push timestamp drift validation (rejects images from cameras with misconfigured clocks)
  - State file validation with graceful recovery from corruption

### Configuration System (`lib/config.php`)

- **Purpose**: Loads and validates airport configuration
- **Features**:
  - Caching via APCu
  - Automatic cache invalidation on file change
  - Validation functions
  - Airport ID extraction from requests
  - Environment-aware config resolution
  - Mock mode detection for development

**Configuration Resolution Order**:
1. `APP_ENV=testing` → `tests/Fixtures/airports.json.test`
2. `CONFIG_PATH` env var → specified file
3. `secrets/airports.json` → Docker secrets mount
4. `config/airports.json` → local development

**Key Functions**:
- `loadConfig()`: Main entry point, returns validated config
- `isTestMode()`: True when `APP_ENV=testing`
- `isProduction()`: True in production environment
- `shouldMockExternalServices()`: True when APIs should be mocked
- `getConfigFilePath()`: Returns resolved config path

**Mock Mode Detection**:
Mock mode activates automatically when:
- `LOCAL_DEV_MOCK=1` environment variable is set, OR
- `APP_ENV=testing`, OR
- Config contains test API keys (`test_*` or `demo_*` prefix), OR
- Webcam URLs point to `example.com`

See [TESTING.md](TESTING.md) for detailed testing documentation.

### Mock Infrastructure

**`lib/test-mocks.php`**: HTTP response mocking for weather APIs
- Intercepts `curl` and `file_get_contents` calls in test mode
- Returns consistent mock responses for all weather providers
- Enables deterministic testing without real API keys

**`lib/mock-webcam.php`**: Placeholder webcam image generation
- Generates identifiable placeholders with airport ID
- Used when real webcams are unavailable
- Includes timestamp for debugging refresh cycles

**`tests/mock-weather-responses.php`**: Weather API mock data
- Provides consistent values across all weather sources
- Covers Tempest, Ambient, WeatherLink, METAR, etc.

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
- **Sitemap**: Automatically includes homepage, status page, embed generator, and all airport subdomains

### Frontend (`pages/airport.php`)

- **Structure**: Single-page template with embedded JavaScript
- **Features**:
  - Dynamic weather data display
  - Unit toggles (temperature, distance, wind speed)
  - Theme toggle (Auto/Day/Dark/Night modes)
  - Wind visualization (Canvas-based)
  - Service worker for offline support
  - Responsive design

**Theme System**:
- **Four Modes**: Auto (default, follows browser), Day (light), Dark (classic dark), Night (red night vision)
- **Night Vision Mode**: Red-tinted display to preserve scotopic vision for pilots
- **Auto-Detection**: Mobile devices automatically switch to Night mode after sunset (based on airport local time)
- **Browser Preference**: Auto mode respects `prefers-color-scheme: dark` and updates in real-time
- **Persistence**: Theme preference saved via cookie (`aviationwx_theme`)
- **Priority**: Mobile auto-night → Saved preference (auto/day/dark) → Default to auto

**Key JavaScript Functions**:
- `fetchWeather()`: Fetches weather data
- `displayWeather()`: Renders weather data
- `updateWindVisual()`: Updates wind visualization with parallel runway support
- `parseRunwayName()`: Extracts L/C/R designations from runway names
- `groupParallelRunways()`: Groups parallel runways by similar headings
- `calculateRunwayOffset()`: Calculates horizontal offset for parallel runways
- `initThemeToggle()`: Initializes theme toggle with auto-detection logic
- `checkThemeAuto()`: Checks time-based and browser theme preferences
- Unit conversion functions
- Timestamp formatting

**Service Worker & Version Management**:
- **Service Worker** (`public/js/service-worker.js`): Provides offline support with network-first caching for weather data
- **Automatic Updates**: SW updates check every 5 minutes, with immediate activation via `skipWaiting()`
- **Version Checking**: Client-side dead man's switch detects stuck versions:
  - Tracks last SW update in localStorage
  - Fetches `/api/v1/version.php` during idle time (non-blocking)
  - Triggers full cleanup if no update in 7 days or if server sets `force_cleanup` flag
- **Version File**: `config/version.json` generated during deploy with git hash and timestamp
  - Configuration values sourced from `airports.json` config section
- **Version Cookie** (`aviationwx_v`): Cross-subdomain cookie set on every response containing hash.timestamp
  - Server detects stuck clients by missing/stale cookie
  - Cleanup injected when `stuck_client_cleanup: true` in config
- See [Operations Guide](OPERATIONS.md#client-version-management) for emergency cleanup procedures

**Timer Worker System**:
- **Web Worker** (inline Blob URL): Provides reliable timer management not throttled in background tabs
- **Timer Lifecycle** (`public/js/timer-lifecycle.js`): Deferred script handling visibility, health monitoring, and cleanup
- **Platform-Aware**:
  - Desktop: 1-second tick resolution, continues in background tabs
  - Mobile: 10-second tick resolution, pauses when tab is hidden
- **Used For**:
  - Webcam refresh (per-camera intervals, typically 60-900 seconds)
  - Weather refresh (configurable, default 60 seconds)
- **Fallback**: Graceful degradation to setInterval if Workers unavailable

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
Scheduler Daemon (runs every 1 second)
  ↓
WebcamScheduleQueue (priority queue using min-heap)
  ↓
Check which cameras are due based on refresh_seconds
  ↓
For each due camera → ProcessPool → unified-webcam-worker.php --worker {airport} {cam}
  ↓
WebcamWorker orchestrates:
  ↓
1. Acquire lock (flock for crash resilience)
  ↓
2. Check circuit breaker
  ↓
3. Clean up orphaned staging files
  ↓
4. AcquisitionStrategy.acquire() - Pull or Push based on config
   - Pull: HTTP/MJPEG/RTSP/Federated API
   - Push: Scan upload directory, stability checks, validation
  ↓
5. ProcessingPipeline.process()
   - Load image once as GD resource
   - Error frame detection (uniform color, pixelation, Blue Iris errors)
   - EXIF validation and normalization to UTC
   - Variant generation (original, 1080p, 720p, 360p)
   - Format generation (JPEG, WebP)
   - Atomic promotion with symlinks
  ↓
6. Release lock

User Request → webcam.php
  ↓
Check cache for requested image
  ↓
[If fresh] Serve with cache headers (HIT)
  ↓
[If stale] Serve stale cache (scheduler handles refresh)
  ↓
[If too stale (>3 hours)] Return 503 Service Unavailable (fail-closed safety)
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

### 5. Multi-Source Aggregation (Unified Fetcher)

- **Why**: Combine data from multiple sources for best coverage and accuracy
- **Implementation**: `WeatherAggregator` applies freshness-based selection
- **Key Rules**:
  - Wind fields (speed, direction, gust) must come from single source as a group
  - Freshest valid data wins for each field
  - METAR typically provides ceiling and cloud_cover (other sources do not)
  - Stale data (beyond max acceptable age) is excluded from aggregation
- **Benefit**: Most current data from all sources, clear field-level attribution

### 6. Explicit Unit Tracking

- **Why**: Prevent dangerous unit conversion errors in safety-critical aviation data
- **Implementation**: `WeatherReading` object carries unit explicitly with factory methods
- **Key Components**:
  - Factory methods: `celsius()`, `inHg()`, `knots()`, `statuteMiles()`, `feet()`, etc.
  - `convertTo()` method for safe unit conversion using centralized libraries
  - PHP library: `lib/units.php` with verified conversion constants
  - JavaScript library: `public/js/units.js` (identical factors for client-side)
- **Internal Standard Units**:
  - Temperature: Celsius (°C)
  - Pressure: inHg
  - Wind Speed: Knots (kt)
  - Visibility: Statute miles (SM)
  - Altitude: Feet (ft)
  - Precipitation: Inches (in)
- **TDD Verification**: 70+ tests in `SafetyCriticalReferenceTest.php` and `unit-conversion.test.js`
- **Benefit**: Runtime validation, self-documenting code, prevents unit mixups

### 7. Multiple Image Formats

- **Why**: Browser compatibility and performance
- **Implementation**: Generate JPEG and WebP formats, serve via content negotiation
- **Format Priority**: WebP (good compression) → JPEG (fallback)
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

1. Create new adapter class extending the adapter pattern (see existing adapters in `lib/weather/adapter/`)
2. Implement required methods:
   - `parseResponse()`: Parse API response into standardized format
   - `getMaxAcceptableAge()`: Return max age in seconds before data is stale
   - `buildUrl()`: Construct API URL from config
3. Add adapter to `buildSourceList()` in `lib/weather/UnifiedFetcher.php`
4. Add source type to `parseSourceResponse()` switch statement
5. Update configuration documentation in `CONFIGURATION.md`

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

- **Logging**: Comprehensive file-based logging via `lib/logger.php` (writes to `/var/log/aviationwx/`)
- **Log Rotation**: Logrotate handles rotation (1 rotated file, 100MB max per file)
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

