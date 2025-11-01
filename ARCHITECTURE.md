# Architecture Overview

This document provides an overview of the AviationWX.org codebase structure and architecture.

## Project Structure

```
aviationwx.org/
├── index.php                 # Main router - handles subdomain/query routing
├── airport-template.php      # Airport page template with weather display
├── homepage.php              # Homepage with airport list
├── weather.php               # Weather API endpoint
├── webcam.php                # Webcam image server endpoint
├── fetch-webcam-safe.php     # Webcam fetcher (runs via cron)
├── config-utils.php          # Configuration loading and utilities
├── rate-limit.php            # Rate limiting utilities
├── logger.php                # Logging utilities
├── diagnostics.php           # System diagnostics endpoint
├── clear-cache.php           # Cache clearing endpoint
├── metrics.php               # Metrics endpoint
├── health.php                # Health check endpoint
├── airports.json.example     # Configuration template
├── styles.css                # Application styles
├── sw.js                     # Service worker for offline support
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

### Weather System (`weather.php`)

- **Purpose**: Fetches and serves weather data as JSON API
- **Key Features**:
  - Supports multiple weather sources (Tempest, Ambient, METAR)
  - Parallel fetching via `curl_multi`
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

**`webcam.php`**: Serves cached webcam images
- Handles image requests with cache headers
- Returns placeholder if image missing
- Supports multiple formats (WEBP, JPEG)

**`fetch-webcam-safe.php`**: Fetches and caches webcam images
- Runs via cron (recommended every minute)
- Safe memory usage (stops after first frame)
- Supports: Static images, MJPEG streams, RTSP/RTSPS (via ffmpeg)
- Generates multiple formats per image

### Configuration System (`config-utils.php`)

- **Purpose**: Loads and validates airport configuration
- **Features**:
  - Caching via APCu
  - Automatic cache invalidation on file change
  - Validation functions
  - Airport ID extraction from requests

### Frontend (`airport-template.php`)

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
- `updateWindVisual()`: Updates wind visualization
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
Fetch Primary Source (Tempest/Ambient) + METAR (parallel)
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
Cron → fetch-webcam-safe.php
  ↓
For each webcam:
  ↓
Fetch image (HTTP/MJPEG/RTSP)
  ↓
Generate formats (WEBP/JPEG)
  ↓
Save to cache/webcams/
  ↓

User Request → webcam.php
  ↓
Check cache for requested image
  ↓
Serve with cache headers
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
- **Implementation**: Generate WEBP/JPEG, serve via `<picture>` element
- **Benefit**: Best format per browser, smaller file sizes

## Security Considerations

- **Input Validation**: All user input validated and sanitized
- **Rate Limiting**: Prevents abuse (60/min weather, 100/min webcams)
- **Credential Protection**: API keys never exposed to frontend
- **File Permissions**: Sensitive files properly protected
- **Error Messages**: Sanitized to prevent information leakage

See [SECURITY.md](SECURITY.md) for detailed security information.

## Caching Strategy

- **Configuration**: APCu memory cache (invalidates on file change)
- **Weather Data**: File-based cache with stale-while-revalidate
- **Webcam Images**: File-based cache (refreshed via cron)
- **HTTP Headers**: Appropriate cache-control headers

## Deployment

- **Docker-based**: Containerized for consistent deployment
- **GitHub Actions**: Automated CI/CD pipeline
- **Production**: Docker Compose on DigitalOcean Droplet
- **DNS**: Wildcard subdomain support

See [DOCKER_DEPLOYMENT.md](DOCKER_DEPLOYMENT.md) for deployment details.

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

1. Add calculation function in `weather.php`
2. Update `$weatherData` array with new field
3. Update `airport-template.php` to display new metric
4. Document in README.md

## Testing

- **Manual Testing**: `test-local.php` for local development
- **Endpoint Testing**: Direct API endpoint testing
- **Diagnostics**: `/diagnostics.php` for system health

See [LOCAL_SETUP.md](LOCAL_SETUP.md) for testing instructions.

## Performance Considerations

- **Caching**: Multiple cache layers (APCu, file cache, HTTP cache)
- **Parallel Requests**: Async fetching when possible
- **Image Optimization**: Multiple formats, efficient generation
- **Rate Limiting**: Prevents resource exhaustion
- **Background Processing**: Stale-while-revalidate reduces blocking

## Monitoring

- **Logging**: Comprehensive logging via `logger.php`
- **Metrics**: `/metrics.php` endpoint for monitoring
- **Health Checks**: `/health.php` for uptime monitoring
- **Diagnostics**: `/diagnostics.php` for system information

## Future Improvements

Potential areas for enhancement:

- **Unit Tests**: Comprehensive test suite
- **API Documentation**: OpenAPI/Swagger spec
- **GraphQL API**: More flexible data queries
- **Real-time Updates**: WebSocket support
- **Mobile App**: Native mobile applications
- **Historical Data**: Data archive and trends

