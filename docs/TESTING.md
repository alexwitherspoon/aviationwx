# Testing Guide

This guide covers testing strategies, environment configuration, and how to run tests locally and in CI.

## Overview

AviationWX uses a unified testing strategy based on `APP_ENV`:

| Environment | `APP_ENV` | Config Source | External Services | Port |
|-------------|-----------|---------------|-------------------|------|
| Production | `production` | secrets/airports.json | Real APIs | 80/443 |
| Local Dev (maintainers) | `development` | secrets/airports.json | Real APIs | 8080 |
| Local Dev (contributors) | `development` | config/airports.json | Auto-mocked | 8080 |
| Testing (Isolated Docker) | `testing` | tests/Fixtures/airports.json.test | Fully mocked | 9080 |
| CI | `testing` | tests/Fixtures/airports.json.test | Fully mocked | 9080 |

## Quick Start

**⚠️ IMPORTANT: Always run `make test-ci` before committing or pushing code.**

### Pre-Commit Validation (REQUIRED)

```bash
# ✅ REQUIRED before commit/push - matches GitHub Actions exactly
make test-ci
```

**Why `make test-ci` and not `make test`?**

| Command | What It Does | When To Use |
|---------|-------------|-------------|
| `make test-ci` | Full CI validation (syntax check + all tests) | **Before every commit/push** |
| `make test` | PHPUnit only (unit + integration) | Quick iteration during development |
| `make test-unit` | Unit tests only | Fast feedback loop |

**Key Difference**: `make test` only runs PHPUnit, which **only checks syntax of files it loads**. CLI scripts and other files without unit tests won't be validated. `make test-ci` runs explicit PHP syntax checking on **all files**, matching GitHub Actions.

**Real Example**: A syntax error (unmatched brace) in `process-push-webcams.php` passed `make test` locally because no tests load that script, but failed in GitHub CI which runs syntax validation on all files.

## Isolated Test Environment

The test environment runs in a separate Docker container that is completely isolated from development:

| Aspect | Development | Test |
|--------|-------------|------|
| Port | 8080 | 9080 |
| Cache | `/tmp/aviationwx-cache` | `/tmp/aviationwx-cache-test` |
| Config | Production secrets | Test fixtures |
| Container | `aviationwx-web-local` | `aviationwx-web-test` |

This allows you to run tests without disrupting your development environment or vice versa.

### Test Environment Commands

```bash
# Start isolated test container (automatically cleans cache for fresh state)
make test-up

# Stop test container
make test-down

# View test container logs
make test-logs

# Open shell in test container
make test-shell

# Clean up test environment (removes volumes and cache)
make test-clean
```

**Note:** `make test-up` automatically clears `/tmp/aviationwx-cache-test` before starting, ensuring each test run begins with a clean cache. This guarantees reproducible tests without leftover state from previous runs.

### Running Tests with Isolation

All test commands that use the isolated environment automatically clean the cache before starting, ensuring deterministic test results.

```bash
# E2E tests - cleans cache, starts container, runs tests, stops container
make test-e2e

# Browser tests - uses isolated test environment on port 9080
make test-browser

# Local tests - uses isolated test environment
make test-local

# Smoke test the isolated environment
make smoke-test
```

### Running Both Environments Simultaneously

You can run both development and test environments at the same time:

```bash
# Terminal 1: Start development (port 8080)
make dev

# Terminal 2: Run tests in isolated environment (port 9080)
make test-e2e
```

### Run All Tests

```bash
# Run unit + integration tests (FAST, but doesn't catch all issues)
make test

# Run only unit tests (fast)
make test-unit

# Run only integration tests
make test-integration

# Run browser tests (uses isolated test container on port 9080)
make test-browser
```

### Run Specific Test

```bash
# Single test file
vendor/bin/phpunit tests/Unit/ConfigValidationTest.php

# Single test method
vendor/bin/phpunit --filter testValidAirportId tests/Unit/ConfigValidationTest.php

# Test suite
vendor/bin/phpunit --testsuite Unit
```

## Test Suites

### Unit Tests (`tests/Unit/`)

Fast, isolated tests that don't require running containers.

```bash
vendor/bin/phpunit --testsuite Unit
```

**What they test:**
- Configuration validation
- Weather calculations
- Data parsing and formatting
- Utility functions

### Integration Tests (`tests/Integration/`)

Tests that verify component interactions.

```bash
vendor/bin/phpunit --testsuite Integration
```

**What they test:**
- API endpoint responses
- Cache behavior
- HTML output validation
- Webcam refresh initialization

### Browser Tests (`tests/Browser/`)

Playwright-based tests for frontend functionality. These run against the isolated test environment (port 9080).

```bash
# Recommended: Use make target (handles container lifecycle)
make test-browser

# Manual: Run with test container already started
make test-up
cd tests/Browser && npm install && npx playwright test
make test-down

# Override URL (e.g., to test against dev environment)
TEST_API_URL=http://localhost:8080 npx playwright test
```

**What they test:**
- JavaScript functionality
- UI interactions
- API endpoint validation
- Performance optimizations

### E2E Tests

Full end-to-end tests run against the isolated test Docker container (port 9080).

```bash
# Recommended: Ephemeral test run (starts container, tests, stops)
make test-e2e

# Manual: Start test container separately
make test-up
TEST_API_URL=http://localhost:9080 vendor/bin/phpunit --testsuite E2E
make test-down
```

## Configuration System

### How Config Loading Works

```
┌─────────────────────────────────────────────────────────────┐
│                    Config Resolution                         │
├─────────────────────────────────────────────────────────────┤
│ 1. APP_ENV=testing → tests/Fixtures/airports.json.test     │
│                                                              │
│ 2. CONFIG_PATH env var → use specified file                 │
│                                                              │
│ 3. secrets/airports.json exists → production mode           │
│                                                              │
│ 4. config/airports.json exists → development mode           │
│    (auto-detects mock mode from config contents)            │
│                                                              │
│ 5. No config found → error with setup instructions          │
└─────────────────────────────────────────────────────────────┘
```

### Mock Mode Detection

Mock mode activates automatically when:
- `LOCAL_DEV_MOCK=1` environment variable is set, OR
- Config contains test API keys (prefixed with `test_` or `demo_`), OR
- Webcam URLs point to `example.com`

See `lib/config.php:shouldMockExternalServices()` for implementation.

### Test Fixtures

**`tests/Fixtures/airports.json.test`**
- Used by all tests when `APP_ENV=testing`
- Contains airports with test API keys
- Webcam URLs point to `example.com`
- Designed for deterministic, reproducible tests

**`tests/mock-weather-responses.php`**
- Provides mock responses for weather APIs
- Returns consistent, known values for testing
- Covers Tempest, Ambient, WeatherLink, METAR, etc.

**`lib/test-mocks.php`**
- HTTP request interception for test mode
- Routes API calls to mock responses
- Returns placeholder images for webcams

## Environment Variables

| Variable | Values | Default | Description |
|----------|--------|---------|-------------|
| `APP_ENV` | `production`, `development`, `testing` | `production` | Controls environment behavior |
| `CONFIG_PATH` | file path | auto-detected | Explicit config file override |
| `LOCAL_DEV_MOCK` | `1`, `0`, `auto` | `auto` | Force mock mode on/off |
| `TEST_API_URL` | URL | `http://localhost:9080` | Base URL for E2E/browser tests (isolated test environment) |
| `TEST_BASE_URL` | URL | `http://localhost:9080` | Alias for TEST_API_URL (Playwright compatibility) |

### Setting Environment for Tests

**PHPUnit** (automatic via `phpunit.xml`):
```xml
<php>
    <env name="APP_ENV" value="testing" force="true"/>
    <env name="CONFIG_PATH" value="tests/Fixtures/airports.json.test" force="true"/>
</php>
```

**Command line**:
```bash
APP_ENV=testing vendor/bin/phpunit
```

**Docker**:
```bash
docker compose exec -e APP_ENV=testing web vendor/bin/phpunit
```

## Writing Tests

### Test File Location

```
tests/
├── Unit/                    # Fast, isolated tests
│   ├── ConfigValidationTest.php
│   ├── WeatherCalculationsTest.php
│   └── ...
├── Integration/             # Component interaction tests
│   ├── WeatherEndpointTest.php
│   ├── SmokeTest.php
│   └── ...
├── Performance/             # Performance benchmarks
│   └── PerformanceTest.php
├── Browser/                 # Playwright browser tests
│   ├── tests/
│   │   ├── aviationwx.spec.js
│   │   └── ...
│   └── playwright.config.js
├── Fixtures/                # Test data
│   └── airports.json.test
├── Helpers/                 # Test utilities
│   └── TestHelper.php
├── bootstrap.php            # Test bootstrap
└── mock-weather-responses.php
```

### Test Helper Functions

```php
// Create test airport config
$airport = createTestAirport([
    'name' => 'My Test Airport',
    'weather_source' => ['type' => 'tempest']
]);

// Create test weather data
$weather = createTestWeatherData([
    'temperature' => 20.0,
    'wind_speed' => 10
]);

// Assert weather response structure
assertWeatherResponse($response);
```

### Testing Config-Dependent Code

```php
// Test with specific config
public function testWithCustomConfig(): void
{
    // The test environment automatically uses airports.json.test
    $config = loadConfig();
    
    $this->assertArrayHasKey('airports', $config);
    $this->assertArrayHasKey('kspb', $config['airports']);
}
```

### Testing with Mocked HTTP

```php
// In test mode, HTTP calls are automatically mocked
public function testWeatherFetch(): void
{
    // This will use mock responses from mock-weather-responses.php
    $weather = fetchWeather('kspb');
    
    $this->assertArrayHasKey('temperature', $weather);
}
```

## CI Pipeline

### Workflow Structure

The CI runs on every push and pull request:

1. **PHP Syntax Validation** - `php -l` on ALL files (catches syntax errors in untested scripts)
2. **Unit Tests** - Fast, isolated tests
3. **Integration Tests** - Component tests
4. **Critical Safety Tests** - Weather calculations, error handling
5. **JavaScript Validation** - Syntax check on JS files
6. **JSON Validation** - Config file syntax
7. **Config Utilities Tests** - Verify config parsing
8. **Docker Build** - Build and cache image
9. **E2E Tests** - Full system tests with Docker
10. **Browser Tests** - Playwright tests (parallel matrix)

### Running CI Tests Locally

**Always run before committing:**
```bash
make test-ci
```

This runs the **exact same** validation as GitHub Actions, including:
- PHP syntax check on all `.php` files (not just those with tests)
- All PHPUnit test suites
- JavaScript syntax validation
- JSON validation
- Config utilities tests

**Why this matters**: PHPUnit only validates files it loads. CLI scripts, utility scripts, and other standalone files won't be checked unless you run `make test-ci`.

### CI Environment

- PHP 8.4 with extensions: apcu, gd, zip, curl
- Composer dependencies cached
- Docker images cached via GitHub Actions cache
- Test fixtures used (no production secrets)

### Test Results

Test results are uploaded as artifacts:
- `unit-results.xml` - Unit test JUnit report
- `integration-results.xml` - Integration test JUnit report
- `playwright-report/` - Browser test HTML report

## Troubleshooting

### Tests Fail Locally but Pass in CI

1. **Check APP_ENV**: Ensure `APP_ENV=testing` is set
2. **Check config**: Verify using test fixtures, not production config
3. **Clear cache**: Run `cleanTestCache()` or delete cache files

### Mock Mode Not Activating

1. **Check config contents**: API keys should start with `test_` or `demo_`
2. **Check LOCAL_DEV_MOCK**: Set explicitly if auto-detection fails
3. **Check APP_ENV**: `testing` mode always enables mocks

### Browser Tests Failing

1. **Install dependencies**: `cd tests/Browser && npm install`
2. **Install browsers**: `npx playwright install chromium`
3. **Check test container**: Ensure test container is running (`make test-up`)
4. **Check port**: Tests default to port 9080 (isolated test environment)

### Test vs Dev Environment Conflicts

If tests are hitting your dev environment or vice versa:

1. **Check port**: Dev uses 8080, tests use 9080
2. **Check containers**: `docker ps` should show separate containers
3. **Check cache**: Dev uses `/tmp/aviationwx-cache`, tests use `/tmp/aviationwx-cache-test`
4. **Clean start**: `make test-clean` removes test volumes and cache

### Cache Conflicts

```bash
# Clean test cache (PHPUnit tests on host)
php -r "require 'tests/bootstrap.php'; cleanTestCache();"

# Clean isolated test environment cache
make test-clean

# Or manually for host cache
rm -rf cache/weather_*.json cache/webcams/ cache/backoff.json

# Manually for Docker test cache
rm -rf /tmp/aviationwx-cache-test
```

## Best Practices

### 1. Use Test Fixtures

Don't create production config files for testing. Use the test fixtures:

```php
// ✅ Good: Uses test fixtures
putenv('CONFIG_PATH=tests/Fixtures/airports.json.test');

// ❌ Bad: Creates production-like files
file_put_contents('config/airports.json', $testConfig);
```

### 2. Isolate Tests

Each test should be independent:

```php
protected function setUp(): void
{
    parent::setUp();
    cleanTestCache(); // Start fresh
}

protected function tearDown(): void
{
    cleanTestCache(); // Clean up
    parent::tearDown();
}
```

### 3. Use Meaningful Assertions

```php
// ✅ Good: Clear, specific assertion
$this->assertEquals('VFR', $weather['flight_category'], 
    'Expected VFR flight category for clear conditions');

// ❌ Bad: Vague assertion
$this->assertTrue(isset($weather['flight_category']));
```

### 4. Test Edge Cases

```php
public function testHandlesEmptyResponse(): void { ... }
public function testHandlesMalformedData(): void { ... }
public function testHandlesTimeout(): void { ... }
```

## Safety-Critical Weather Tests

Aviation weather is safety-critical. The following tests ensure data integrity:

### Weather Data Validation Tests (`tests/Unit/WeatherDataValidationTest.php`)

Tests that verify weather data is within physically possible ranges. These catch:
- **Unit conversion errors**: API returning pressure in wrong units (e.g., hundredths of inHg)
- **API format changes**: New API versions returning data in different formats
- **Data corruption**: Invalid values that would produce dangerous flight calculations

**Key tests:**
- Pressure bounds (28-35 inHg is valid; 3000+ is obviously wrong)
- Temperature bounds (-90°C to +60°C)
- Pressure altitude bounds (-5000 to +50000 ft)
- Humidity bounds (0-100%)

### Weather Adapter Unit Conversion Tests (`tests/Unit/WeatherAdapterUnitConversionTest.php`)

Tests each weather adapter's unit conversion logic with edge cases:
- **SynopticData**: Handles altimeter in hundredths of inHg (actual production bug)
- **METAR**: Converts hPa to inHg correctly
- **Tempest**: Converts mb to inHg correctly
- **Bad API responses**: Simulates API returning values in wrong units

**Example - Test that catches the production bug:**
```php
public function testSynopticData_AltimeterInHundredths_CorrectedTo30InHg()
{
    // Simulate API returning altimeter as 3038.93 (hundredths) instead of 30.3893
    $response = json_encode([
        'STATION' => [[
            'OBSERVATIONS' => [
                'altimeter_value_1' => ['value' => 3038.93]  // Bug scenario
            ]
        ]]
    ]);
    
    $parsed = parseSynopticDataResponse($response);
    
    // The fix should correct 3038.93 to 30.3893
    $this->assertEqualsWithDelta(30.3893, $parsed['pressure'], 0.01);
}
```

### Why These Tests Matter

A 2025 production incident showed that SynopticData returned pressure as `3038.93` inHg (instead of `30.3893`), causing:
- **Pressure altitude**: -3,005,848 ft (should have been ~-261 ft)
- **Density altitude**: -1,647 ft (affected by the bad pressure input)

This could have led pilots to incorrect performance calculations. The fix adds:
1. **Universal pressure correction** in `UnifiedFetcher.php` (divides by 100 if > 100)
2. **Bounds validation tests** that would catch similar issues early
3. **Adapter smoke tests** that verify final values are within physical limits

## Related Documentation

- [Development Setup](LOCAL_SETUP.md) - Local environment setup
- [Architecture](ARCHITECTURE.md) - System design
- [Configuration](CONFIGURATION.md) - Config options
- [Code Style](../CODE_STYLE.md) - Coding standards







