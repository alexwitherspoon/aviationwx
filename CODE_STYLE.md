# Code Style Guide

This document outlines coding standards and development practices for AviationWX.org. All contributors should follow these guidelines.

**Important**: This is a **safety-critical application** used by pilots for flight decisions. Code quality, reliability, and graceful degradation are paramount.

## Table of Contents

- [Safety & Data Quality](#safety--data-quality)
- [Comments and Documentation](#comments-and-documentation)
- [Testing Requirements](#testing-requirements)
- [Code Quality](#code-quality)
- [File Organization](#file-organization)
- [Error Handling](#error-handling)
- [Configuration Management](#configuration-management)
- [API Integration Patterns](#api-integration-patterns)
- [Dependencies](#dependencies)
- [Git Workflow](#git-workflow)

---

## Safety & Data Quality

### Data Staleness Handling

**Critical**: This application is used for flight safety decisions. Data quality and staleness must be handled explicitly.

- **Show all available data** with "---" for missing fields
- **Never show stale data** - null out fields that exceed `MAX_STALE_HOURS` (3 hours)
- **Show timestamps** - Always display data age to users
- **Warn at thresholds** - Show warnings when data exceeds `WEATHER_STALENESS_WARNING_HOURS_METAR` (1 hour)
- **Fail closed** - After `MAX_STALE_HOURS`, show "---" instead of stale data

**Example:**
```php
// Null out stale fields based on source timestamps
function nullStaleFieldsBySource(&$data, $maxStaleSeconds) {
    $primaryAge = time() - $data['last_updated_primary'];
    if ($primaryAge >= $maxStaleSeconds) {
        // Null out all primary source fields
        $data['temperature'] = null;
        $data['wind_speed'] = null;
        // ... other primary fields
    }
}
```

### Per-Airport Degradation

- **Each airport degrades independently** - One airport's failure doesn't affect others
- **Each component degrades independently** - Weather and webcam can fail separately
- **Never fail silently** - Always show clear indicators:
  - Weather: Show "---" for stale/missing fields with timestamps
  - Webcams: Show placeholder image when too old (use `PLACEHOLDER_CACHE_TTL` constant)
- **Automatic recovery** - Use circuit breaker with backoff, but ensure at least one attempt per day

### Sensor Reliability

- **Design for unreliable sensors** - Sensors may have poor internet connectivity
- **Circuit breaker pattern** - Use exponential backoff with severity-based scaling
- **Never fully stop** - Backoff should ensure at least one attempt per day (when cache is cleared)
- **Recovery logging** - Log recovery events in internal system operational logs

---

## Comments and Documentation

### Comment Philosophy

**Keep comments concise and focused on critical logic.**

- ✅ **DO** comment:
  - Complex business logic or algorithms
  - Non-obvious behavior or edge cases
  - Safety-critical logic (data staleness, validation)
  - Race conditions, concurrency issues, or file locking logic
  - The "why" behind a decision, not the "what"
  - Error suppression rationale

- ❌ **DON'T** comment:
  - Self-explanatory code
  - Obvious operations (e.g., `$counter++`)
  - Code that is clear from function/variable names
  - Verbose explanations of simple logic
  - Transitory comments explaining code changes (e.g., "Changed X to Y")
  - Comments that explain what the code does (code should be self-documenting)

### Comment Maintenance When Modifying Code

**When modifying existing code, refactor comments rather than just appending new ones.**

- ✅ **DO** refactor existing comments to reflect current code behavior
- ✅ **DO** remove outdated comments that no longer apply
- ✅ **DO** update comments when logic changes significantly
- ✅ **DO** ensure comments follow documentation guidelines (concise, focus on "why")
- ❌ **DON'T** append new comments without reviewing existing ones
- ❌ **DON'T** leave transitory comments like "Changed X to fix Y" or "Updated for new API"
- ❌ **DON'T** add comments that explain code changes (use git history for that)
- ❌ **DON'T** leave comments that describe what code does (code should be self-documenting)

**Example - Refactoring Comments:**

```php
// Before: Outdated comment with appended transitory note
// Check if rate limit is exceeded (old implementation)
// Updated to use APCu instead of file-based (2024-12-01)
if (apcu_fetch($key) >= $maxRequests) {
    return false;
}

// After: Refactored to current, concise comment
// Use APCu for rate limiting (preferred) with file-based fallback
if (apcu_fetch($key) >= $maxRequests) {
    return false;
}
```

**Example - Removing Transitory Comments:**

```php
// Bad: Transitory comment explaining a change
// Changed from file-based to APCu for better performance
if (function_exists('apcu_fetch')) {
    // ...
}

// Good: Comment explains current behavior, not the change
// Use APCu if available for faster rate limiting
if (function_exists('apcu_fetch')) {
    // ...
}
```

### Examples

**Good Comment - Safety Critical:**
```php
// Null out stale fields to prevent showing outdated data to pilots
// After MAX_STALE_HOURS (3 hours), data is considered unsafe for flight decisions
nullStaleFieldsBySource($data, MAX_STALE_HOURS * 3600);
```

**Good Comment - File Locking:**
```php
// Use file locking to prevent race conditions in concurrent environments
// Without locking, concurrent requests could both read the same count,
// increment independently, and write back, causing rate limit bypass
$fp = @fopen($rateLimitFile, 'c+');
if (!@flock($fp, LOCK_EX)) {
    return checkRateLimitFileBasedFallback(...);
}
```

**Bad Comment:**
```php
// Increment the counter variable by one
$counter++;
```

### PHPDoc Standards

- All public functions, classes, and methods must have PHPDoc blocks
- Keep descriptions concise (one line when possible)
- Always include `@param` types and descriptions
- Always include `@return` type and description
- For structured arrays, document the structure
- Explain the "why" for critical, complex, or unclear logic
- **When modifying functions**: Update PHPDoc to reflect current behavior, don't just append notes
- **Remove outdated PHPDoc**: If function behavior changes, update or remove outdated documentation

**Example - Simple Return:**
```php
/**
 * Validates airport ID format (ICAO standard)
 * 
 * Airport IDs must be 3-4 lowercase alphanumeric characters.
 * Validates format before trimming to prevent "k spb" from becoming "kspb".
 *
 * @param string $id Airport ID to validate
 * @return bool True if valid ICAO format, false otherwise
 */
function validateAirportId(string $id): bool {
    // ...
}
```

**Example - Structured Array Return:**
```php
/**
 * Check if weather API should be skipped due to backoff
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param string $sourceType Weather source type: 'primary' or 'metar'
 * @return array {
 *   'skip' => bool,              // True if should skip (circuit open)
 *   'reason' => string,          // Reason for skip ('circuit_open' or '')
 *   'backoff_remaining' => int,  // Seconds remaining in backoff period
 *   'failures' => int            // Number of consecutive failures
 * }
 */
function checkWeatherCircuitBreaker(string $airportId, string $sourceType): array {
    // ...
}
```

---

## Testing Requirements

### Test Coverage Policy

**Critical paths, complex logic, and end-to-end tests are required.**

1. **New Features**: Must include tests for critical paths and complex logic
2. **Bug Fixes**: Must include tests that verify the fix
3. **Refactoring Tests**: Encouraged, but don't cheat positive results when bugs exist
4. **Obsolete Tests**: Evaluate and remove tests that are no longer needed
5. **Test Generation**: Use TDD style when developing

### Test Organization

- **Unit Tests** (`tests/Unit/`): Test individual functions/classes in isolation
- **Integration Tests** (`tests/Integration/`): Test component interactions
- **End-to-End Tests**: Test critical paths with mock APIs
- **Reliability Tests**: Test sensor failure scenarios, backoff behavior, data staleness

### Test Naming

Use descriptive test method names following this pattern:
```php
testFunctionName_Scenario_ExpectedBehavior()
```

**Examples:**
- `testNullStaleFieldsBySource_ExceedsMaxStaleHours_NullsOutFields()`
- `testCheckRateLimit_ExceedsThreshold_ReturnsFalse()`
- `testParseTempestResponse_InvalidJson_ReturnsNull()`

### Test Quality Standards

- ✅ Tests should be independent (no execution order dependencies)
- ✅ Use appropriate fixtures and mocks
- ✅ Tests should be fast and reliable
- ✅ Focus on behavior, not implementation details
- ✅ Use `setUp()` and `tearDown()` for test isolation and cleanup
- ✅ Test sensor reliability scenarios (intermittent failures, recovery)
- ✅ Test data staleness handling
- ✅ Keep mock APIs up to date when API versions change

### Example Test Structure

```php
class StaleDataSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Setup test environment
    }
    
    protected function tearDown(): void
    {
        // Clean up test files
        parent::tearDown();
    }
    
    public function testNullStaleFieldsBySource_ExceedsMaxStaleHours_NullsOutFields(): void
    {
        $data = createTestWeatherData([
            'temperature' => 15.0,
            'last_updated_primary' => time() - (MAX_STALE_HOURS * 3600 + 1)
        ]);
        
        nullStaleFieldsBySource($data, MAX_STALE_HOURS * 3600);
        
        $this->assertNull($data['temperature'], 'Stale temperature should be nulled');
    }
}
```

---

## Code Quality

### General Principles

- **Follow PSR-12** coding standards for PHP
- **Use meaningful names**: Variables and functions should clearly express intent
- **Keep functions focused**: Single responsibility principle
- **Prefer composition** over inheritance
- **Handle errors explicitly**: Don't silently fail or ignore errors
- **Be consistent**: Follow existing patterns in the codebase

### Type Hints

- **Add type hints gradually** - When modifying existing code, add type hints
- **New code** - Always include full type hints
- **Use strict types** - When risk of bug is high, use `declare(strict_types=1);`
- **Mixed types** - Use `mixed` with PHPDoc when appropriate
- **Return types** - Always specify return types, even for `void`

**Example:**
```php
function checkRateLimit(string $key, int $maxRequests = 60, int $windowSeconds = 60): bool {
    // ...
}
```

### Function Design

- **Keep functions focused** - Small to medium sized functions
- **Organize by domain** - Group related functions together
- **Proactively refactor** - If functions are brittle, have code smells, or are disorganized
- **Refactor when adding features** - Explore if refactoring would integrate new functionality more cleanly

### Security Guidelines

- **Never commit sensitive data**: API keys, passwords, credentials
- **Validate all input**: Sanitize user input and API responses
- **Fail fast on bad config**: Validate `airports.json` schema on load
- **Isolate failures**: Config errors should affect only the airport(s) with errors when possible

---

## File Organization

### Domain-Based Structure

Organize code by domain to make it easy to find and understand:

```
lib/
  weather/
    fetcher.php          # Weather data fetching
    parser.php           # Weather data parsing
    calculator.php        # Weather calculations (density altitude, etc.)
    adapter/
      tempest-v1.php     # Tempest API adapter v1
      tempest-v2.php     # Tempest API adapter v2
      ambient-v1.php     # Ambient Weather API adapter
      weatherlink-v1.php # WeatherLink API adapter
      metar-v1.php       # METAR adapter
  webcam/
    fetcher.php          # Webcam fetching
    processor.php        # Webcam processing
    adapter/
      rtsp-v1.php        # RTSP adapter
      mjpeg-v1.php       # MJPEG adapter
      push-v1.php        # Push upload adapter
  circuit-breaker/
    base.php             # Base circuit breaker logic
    weather.php          # Weather-specific wrappers
    webcam.php           # Webcam-specific wrappers
  config/
    loader.php           # Config loading and caching
    validator.php        # Config validation
    schema.php           # Config schema definition
```

### File Structure Template

Follow this pattern in each file:

```php
<?php
/**
 * File-level documentation
 * Brief description of what this file contains
 */

// 1. Requires (alphabetical order)
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/logger.php';

// 2. Constants (if file-specific)
if (!defined('LOCAL_CONSTANT')) {
    define('LOCAL_CONSTANT', 'value');
}

// 3. Functions (grouped by purpose)
// Helper functions first
function helperFunction(): void { }

// Public API functions
function publicFunction(): void { }
```

### Naming Conventions

- **Files**: lowercase with hyphens (e.g., `circuit-breaker.php`)
- **Classes**: PascalCase (e.g., `CircuitBreaker`)
- **Functions**: camelCase (e.g., `getWeatherData()`)
- **Constants**: UPPER_SNAKE_CASE (e.g., `MAX_STALE_HOURS`)
- **Variables**: camelCase (e.g., `$stationId`)

---

## Unit Handling

### Overview

**Critical**: Incorrect unit handling is a common source of aviation accidents. All weather data must carry explicit unit information to prevent dangerous misinterpretations.

### Internal Standard Units

AviationWX stores weather data using these standard units:

| Field | Internal Unit | Factory Method |
|-------|--------------|----------------|
| Temperature | Celsius (°C) | `WeatherReading::celsius()` |
| Dewpoint | Celsius (°C) | `WeatherReading::celsius()` |
| Pressure | inHg | `WeatherReading::inHg()` |
| Visibility | Statute miles (SM) | `WeatherReading::statuteMiles()` |
| Precipitation | Inches (in) | `WeatherReading::inches()` |
| Wind Speed | Knots (kt) | `WeatherReading::knots()` |
| Altitude/Ceiling | Feet (ft) | `WeatherReading::feet()` |
| Humidity | Percent (%) | `WeatherReading::percent()` |
| Wind Direction | Degrees | `WeatherReading::degrees()` |

### WeatherReading Factory Methods

**Always use factory methods** to create WeatherReading objects. This ensures the unit is explicitly tracked:

```php
// ✅ CORRECT: Use factory method - unit is explicit
$temp = WeatherReading::celsius(15.5, 'tempest', $obsTime);
$pressure = WeatherReading::inHg(29.92, 'metar', $obsTime);
$wind = WeatherReading::knots(10.0, 'ambient', $obsTime);

// ❌ WRONG: Don't use generic constructor - unit is not tracked
$temp = new WeatherReading(15.5, '', time(), 'tempest', true);
```

### Converting Units

Use the `convertTo()` method for unit conversions:

```php
// Convert temperature from Celsius to Fahrenheit
$tempC = WeatherReading::celsius(20.0, 'source', $time);
$tempF = $tempC->convertTo('F');

// Convert pressure from inHg to hPa
$pressureInHg = WeatherReading::inHg(29.92, 'source', $time);
$pressureHpa = $pressureInHg->convertTo('hPa');
```

### Conversion Libraries

Use the centralized conversion libraries for all unit conversions:

**PHP**: `lib/units.php`
```php
require_once 'lib/units.php';

$hpa = inhgToHpa(29.92);           // Convert inHg to hPa
$mph = knotsToMph(25);             // Convert knots to mph
$meters = feetToMeters(3000);      // Convert feet to meters
```

**JavaScript**: `public/js/units.js`
```javascript
const hpa = AviationWX.units.inhgToHpa(29.92);
const mph = AviationWX.units.knotsToMph(25);
const meters = AviationWX.units.feetToMeters(3000);
```

### Weather Adapter Guidelines

When creating or modifying weather adapters:

1. **Parse raw data** using the legacy parser function
2. **Create WeatherSnapshot** using factory methods for each field
3. **Document units** in the adapter's parseToSnapshot PHPDoc

```php
public static function parseToSnapshot(string $response, array $config = []): ?WeatherSnapshot {
    $parsed = parseMyApiResponse($response);
    if ($parsed === null) {
        return WeatherSnapshot::empty(self::SOURCE_TYPE);
    }
    
    $obsTime = $parsed['obs_time'] ?? time();
    $source = self::SOURCE_TYPE;
    
    return new WeatherSnapshot(
        source: $source,
        fetchTime: time(),
        temperature: WeatherReading::celsius($parsed['temperature'], $source, $obsTime),
        pressure: WeatherReading::inHg($parsed['pressure'], $source, $obsTime),
        // ... other fields with appropriate factory methods
    );
}
```

### Conversion Constants

All conversion factors are defined in `lib/units.php` with authoritative sources:

- **1 inHg = 33.8639 hPa** (ICAO standard)
- **1 statute mile = 1609.344 meters** (exact, US Code Title 15 §205)
- **1 inch = 25.4 mm** (exact, International Yard and Pound Agreement 1959)
- **1 knot = 1.852 km/h** (exact, nautical mile definition)
- **1 foot = 0.3048 meters** (exact, International Yard and Pound Agreement 1959)

**Never hardcode conversion factors** - always use the conversion library.

### Testing Unit Conversions

Unit conversion tests are in `tests/Unit/SafetyCriticalReferenceTest.php`:

```bash
# Run unit conversion tests
vendor/bin/phpunit tests/Unit/SafetyCriticalReferenceTest.php --filter "Conversion"

# Run JavaScript unit conversion tests
node tests/js/unit-conversion.test.js
```

---

## Error Handling

### Explicit Error Handling

**Critical**: This is a safety-critical application. Errors must be handled explicitly.

- **Evaluate if error is truly an error** - Not all failures need special handling
- **Handle errors explicitly** - Don't silently fail
- **Isolate failures** - Per-airport degradation when possible
- **Log with context** - Always include relevant context in error logs

### Error Suppression

When using `@` error suppression, always document why:

```php
// Use @ to suppress errors for non-critical file operations
// We handle failures explicitly with fallback mechanisms below
$backoffData = @json_decode(@file_get_contents($backoffFile), true) ?: [];
if ($backoffData === null) {
    // Fallback: return empty state
    return ['skip' => false, 'reason' => '', 'backoff_remaining' => 0, 'failures' => 0];
}
```

### Result Objects

Use result objects for complex operations when appropriate:

```php
/**
 * @return array {
 *   'success' => bool,
 *   'data' => ?array,
 *   'error' => ?string,
 *   'error_code' => ?string
 * }
 */
function fetchWeatherWithResult(string $airportId): array {
    // ...
}
```

### Custom Exceptions

Create custom exceptions when they help make code easier to understand:

```php
class WeatherApiException extends Exception {}
class CircuitBreakerOpenException extends Exception {}
class InvalidAirportIdException extends Exception {}
```

---

## Configuration Management

### airports.json Structure

- **Single config file** - All configuration in `airports.json` (read-only, no database)
- **Schema validation** - Validate schema on load, fail fast on bad config
- **CI/CD validation** - Catch config issues during CI/CD (in `../aviationwx.org-secrets/`)
- **Breaking changes** - OK, but must go through full PR process with documentation
- **Version API adapters** - Enable picking API provider and version in config:
  ```json
  {
    "weather_source": {
      "type": "tempest",
      "version": "v2",
      "station_id": "...",
      "api_key": "..."
    }
  }
  ```

### Config Error Handling

- **CI/CD failures** - Bad config should block deployment
- **Production isolation** - Try to isolate failures to individual airports when possible
- **Clear error reporting** - Errors should be clearly reported during CI/CD

### Constants Organization

Split constants by domain in `lib/constants/`:

```
lib/constants/
  weather.php      # Weather-related constants
  webcam.php       # Webcam-related constants
  rate-limit.php   # Rate limiting constants
  circuit-breaker.php  # Circuit breaker constants
  staleness.php    # Data staleness thresholds
```

---

## API Integration Patterns

### Adapter Pattern

Use adapter pattern for each API provider with versioning:

```php
// lib/weather/adapter/tempest-v1.php
class TempestApiV1 {
    public function fetchWeather(string $stationId, string $apiKey): ?array {
        // Tempest API v1 implementation
    }
}

// lib/weather/adapter/tempest-v2.php
class TempestApiV2 {
    public function fetchWeather(string $stationId, string $apiKey): ?array {
        // Tempest API v2 implementation
    }
}
```

### Data Source Priority

- **Use newest data available** - Prefer most recent data source
- **Dynamic priority** - Based on availability and freshness
- **Unique fields** - METAR provides visibility/ceiling, weather stations provide other fields
- **Fallback logic** - Use METAR if primary weather station is down

### API Version Testing

- **Test new versions with subset** - Use version in config to test new API versions with subset of airports
- **Keep mock APIs updated** - Update mocks when API versions change
- **Document breaking changes** - When API adapters change, document breaking changes

---

## Dependencies

### Minimize Dependencies

**Preference**: Minimize dependencies to keep project robust long-term.

- **Prefer PHP built-ins** - Use native PHP functions when possible
- **Use dependencies when complexity is high** - Don't rebuild mature software (e.g., ffmpeg)
- **Avoid simple dependencies** - Don't use a dependency for simple JSON parsing (PHP has `json_decode`)
- **Review with maintainer** - Discuss new dependencies before adding
- **Document rationale** - Document why each dependency is needed

**Examples:**
- ✅ **Use**: ffmpeg (complex, mature software)
- ❌ **Avoid**: JSON parser library (PHP has built-in)
- ✅ **Use**: PHPUnit (testing framework)
- ❌ **Avoid**: Simple utility libraries (implement in native PHP)

---

## Temporary Files & Cleanup

### Temporary Documentation Files

**Important**: Clean up temporary documentation files when work is complete.

Temporary files are automatically excluded from git via `.gitignore` patterns:
- `docs/*DIAGNOSTICS*.md`
- `docs/*TROUBLESHOOTING*.md`
- `docs/*TEST*.md`
- `docs/*TEMP*.md`
- `docs/*ANALYSIS*.md`
- `docs/*CHECKLIST*.md`
- `docs/*PLAN*.md`
- `docs/*PLANNING*.md`
- `docs/*QUESTIONS*.md`
- `docs/*RESULTS*.md`
- `docs/*FINDINGS*.md`
- `docs/*DESIGN*.md`
- `docs/*EXECUTION*.md`
- `docs/*FIXES*.md`
- `docs/*SUMMARY*.md`

**Best Practices:**
- ✅ **DO** create temporary files for analysis, planning, or debugging
- ✅ **DO** delete temporary files when work is complete
- ✅ **DO** use descriptive names that match `.gitignore` patterns
- ❌ **DON'T** commit temporary files (they're excluded for a reason)
- ❌ **DON'T** leave temporary files in the repo after work is done

**Examples of temporary files:**
- `docs/ANALYSIS_weather_api.md` - Analysis document
- `docs/PLANNING_refactor.md` - Planning document
- `docs/SUMMARY_code_review.md` - Review summary
- `docs/TEMP_debugging.md` - Temporary debugging notes

**When to clean up:**
- After completing the analysis/planning
- After the work is merged/complete
- Before opening a PR (if created during development)
- Periodically review `docs/` for old temporary files

## Git Workflow

### Commit Messages

Follow this format:

```
Short summary (50 chars or less)

More detailed explanation if needed. Wrap at 72 characters.
Explain what and why vs. how.
```

**Examples:**
```
Fix stale data not being nulled after MAX_STALE_HOURS

The nullStaleFieldsBySource function was not being called
when serving cached data. Added call to ensure stale data
is never shown to pilots.

Fixes #123
```

```
Add Tempest API v2 adapter

Implements new Tempest API v2 with improved error handling.
Can be enabled per-airport via airports.json config.

Breaking change: Requires airports.json update for v2 users.
```

### Branch Naming

- `feature/` - New features (e.g., `feature/tempest-api-v2`)
- `fix/` - Bug fixes (e.g., `fix/stale-data-nulling`)
- `refactor/` - Code refactoring (e.g., `refactor/weather-adapters`)
- `test/` - Test improvements (e.g., `test/add-staleness-tests`)
- `docs/` - Documentation updates (e.g., `docs/update-api-docs`)

### Breaking Changes

- **Breaking changes are OK** - Project is young, large improvements are welcome
- **Full PR process** - Always use PRs for breaking changes
- **Document breaking changes** - Clearly document what changed and migration path
- **Update examples** - Ensure `airports.json.example` and docs are updated

---

## Worker Scripts Organization

### Domain-Based Workers

Organize workers by airport, then by data source:

```
scripts/
  workers/
    weather/
      fetch-weather.php        # Main weather fetcher (handles all sources)
    webcam/
      fetch-webcam.php         # Main webcam fetcher (handles all types)
  utilities/
    sync-push-config.php       # Utility scripts
```

Workers should:
- Process one airport at a time
- Handle all data sources for that domain (weather sources, webcam types)
- Use process pool for parallel execution
- Log recovery events in internal system operational logs

---

## Logging Strategy

### Categorized Logging

**Future direction**: Move towards separate logging for:

1. **Internal system operations** - System health, recovery events, circuit breaker state
2. **Data acquisition activity** - API calls, sensor data, fetch results
3. **User activity** - API requests, page views, errors

**Current**: Use `aviationwx_log()` with appropriate log types and context.

**Recovery logging**: Log recovery events in internal system operational logs.

---

## Development Checklist

Before submitting code:

- [ ] Code follows PSR-12 standards
- [ ] Critical paths and complex logic have tests
- [ ] All tests pass (`make test`)
- [ ] Comments are concise and only where needed
- [ ] PHPDoc blocks added for public functions
- [ ] No sensitive data committed
- [ ] Data staleness handled correctly (never show stale data)
- [ ] Errors handled explicitly
- [ ] Config validation passes
- [ ] Breaking changes documented
- [ ] Examples and docs updated
- [ ] **Temporary documentation files cleaned up** - Remove any temporary `.md` files (analysis, planning, diagnostics, summaries, etc.) when work is complete

---

## Additional Resources

- [PSR-12 Coding Standard](https://www.php-fig.org/psr/psr-12/)
- [Contributing Guide](CONTRIBUTING.md)
- [Security Guidelines](docs/SECURITY.md)
- [Architecture Documentation](docs/ARCHITECTURE.md)

---

**Remember**: This is a safety-critical application. Code quality, reliability, and graceful degradation are paramount. When in doubt, prioritize safety and clarity over cleverness.

