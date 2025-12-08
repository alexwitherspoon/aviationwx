# High Priority Refactoring Opportunities

This document identifies high-priority refactoring opportunities based on the code style guidelines and best practices established for AviationWX.org.

**Review Date**: 2024-12-XX  
**Priority**: High - Safety-critical application, code quality is paramount

---

## 🔴 Priority 1: Critical Safety & Organization

### 1.1 Organize Weather Code by Domain (HIGHEST PRIORITY)

**File**: `api/weather.php` (2,662 lines - very large)

**Issue**: All weather-related code is in a single massive file, making it:
- Hard to find specific functionality
- Difficult to test individual components
- Hard to update API adapters when APIs change
- Violates domain-based organization principle

**Current Structure**:
```
api/weather.php (2,662 lines)
├── Parsing functions (parseTempestResponse, parseAmbientResponse, parseMETARResponse, parseWeatherLinkResponse)
├── Fetching functions (fetchTempestWeather, fetchAmbientWeather, fetchWeatherLinkWeather, fetchMETAR)
├── Async fetching (fetchWeatherAsync - 380+ lines)
├── Sync fetching (fetchWeatherSync)
├── Calculation functions (calculateDewpoint, calculateDensityAltitude, calculateFlightCategory, etc.)
├── Daily tracking (updatePeakGust, getPeakGust, updateTempExtremes, getTempExtremes)
├── Utility functions (getAirportTimezone, getSunriseTime, getSunsetTime)
└── Endpoint logic (mixed with business logic)
```

**Proposed Structure** (Domain-Based):
```
lib/weather/
├── fetcher.php              # Main weather fetching orchestration
│   ├── fetchWeatherAsync()
│   └── fetchWeatherSync()
├── parser.php               # Response parsing (common interface)
│   └── (parsing utilities)
├── calculator.php           # Weather calculations
│   ├── calculateDewpoint()
│   ├── calculateDensityAltitude()
│   ├── calculatePressureAltitude()
│   ├── calculateFlightCategory()
│   └── calculateHumidityFromDewpoint()
├── daily-tracking.php       # Daily high/low/peak tracking
│   ├── updatePeakGust()
│   ├── getPeakGust()
│   ├── updateTempExtremes()
│   └── getTempExtremes()
├── staleness.php            # Data staleness handling
│   ├── nullStaleFieldsBySource()
│   └── mergeWeatherDataWithFallback()
├── adapter/
│   ├── tempest-v1.php       # Tempest API adapter
│   │   ├── fetchTempestWeather()
│   │   └── parseTempestResponse()
│   ├── ambient-v1.php       # Ambient Weather adapter
│   │   ├── fetchAmbientWeather()
│   │   └── parseAmbientResponse()
│   ├── weatherlink-v1.php   # WeatherLink adapter
│   │   ├── fetchWeatherLinkWeather()
│   │   └── parseWeatherLinkResponse()
│   └── metar-v1.php         # METAR adapter
│       ├── fetchMETAR()
│       ├── fetchMETARFromStation()
│       └── parseMETARResponse()
└── utils.php                # Weather utilities
    ├── getAirportTimezone()
    ├── getSunriseTime()
    └── getSunsetTime()

api/weather.php (reduced to ~200 lines)
└── Endpoint logic only
    ├── Rate limiting
    ├── Input validation
    ├── Cache handling
    └── Response formatting
```

**Benefits**:
- Easy to find weather-related code
- Simple to add new API adapters (just add new adapter file)
- Easy to test adapters independently
- Clear separation of concerns
- Enables API versioning (tempest-v1, tempest-v2, etc.)

**Migration Strategy**:
1. Create `lib/weather/` directory structure
2. Move parsing functions to adapters (one adapter per API)
3. Move calculations to `calculator.php`
4. Move daily tracking to `daily-tracking.php`
5. Move staleness logic to `staleness.php`
6. Refactor `api/weather.php` to use new structure
7. Update tests to use new structure

**Estimated Effort**: Medium-High (significant refactoring, but well-defined)

---

### 1.2 Add Type Hints to Critical Safety Functions

**Issue**: Many safety-critical functions lack type hints, making code harder to reason about and more error-prone.

**Functions Needing Type Hints** (Priority Order):

**Critical Safety Functions**:
- `nullStaleFieldsBySource(&$data, $maxStaleSeconds)` - **CRITICAL** - Data staleness handling
- `mergeWeatherDataWithFallback($newData, $existingData, $maxStaleSeconds)` - **CRITICAL** - Data merging
- `checkCircuitBreakerBase($key, $backoffFile)` - Circuit breaker logic
- `recordCircuitBreakerFailureBase($key, $backoffFile, $severity = 'transient')` - Failure recording
- `checkWeatherCircuitBreaker($airportId, $sourceType)` - Weather circuit breaker
- `checkWebcamCircuitBreaker($airportId, $camIndex)` - Webcam circuit breaker

**API Parsing Functions** (High Priority):
- `parseTempestResponse($response)` - Should be `parseTempestResponse(string $response): ?array`
- `parseAmbientResponse($response)` - Should be `parseAmbientResponse(string $response): ?array`
- `parseMETARResponse($response, $airport)` - Should be `parseMETARResponse(string $response, array $airport): ?array`
- `parseWeatherLinkResponse($response)` - Should be `parseWeatherLinkResponse(string $response): ?array`

**Calculation Functions**:
- `calculateDewpoint($tempC, $humidity)` - Should be `calculateDewpoint(float $tempC, float $humidity): ?float`
- `calculateDensityAltitude($weather, $airport)` - Should be `calculateDensityAltitude(array $weather, array $airport): ?float`
- `calculateFlightCategory($weather)` - Should be `calculateFlightCategory(array $weather): ?string`

**Migration Strategy**:
1. Start with safety-critical functions (staleness, circuit breaker)
2. Add type hints when modifying functions
3. Gradually add to all functions

**Estimated Effort**: Low-Medium (add as we go, or batch for critical functions)

---

### 1.3 Break Down Large Functions

**Issue**: Several functions exceed recommended size and do too much.

**Large Functions Identified**:

1. **`fetchWeatherAsync()`** - ~380 lines in `api/weather.php`
   - **Does**: Circuit breaker checks, URL building, curl_multi setup, parallel execution, response parsing, error handling, METAR fallback
   - **Should be broken into**:
     - `buildWeatherApiUrl()` - URL construction
     - `executeParallelWeatherRequests()` - curl_multi execution
     - `parseWeatherResponses()` - Response parsing and merging
     - `handleWeatherApiErrors()` - Error handling and circuit breaker updates

2. **`fetchWeatherSync()`** - ~200+ lines in `api/weather.php`
   - Similar breakdown needed

3. **`sync-push-config.php`** - 1,304 lines (entire file)
   - **Issue**: Single script file with many responsibilities
   - **Should be broken into**:
     - `lib/push-webcam/config-sync.php` - Configuration synchronization logic
     - `lib/push-webcam/user-management.php` - User creation/deletion
     - `lib/push-webcam/directory-management.php` - Directory setup
     - `scripts/sync-push-config.php` - Main script (orchestration only)

**Migration Strategy**:
- Refactor when modifying these functions
- Extract logical units into separate functions
- Keep functions focused on single responsibility

**Estimated Effort**: Medium (can be done incrementally)

---

## 🟡 Priority 2: Code Organization & Maintainability

### 2.1 Organize Webcam Code by Domain

**File**: `api/webcam.php` (904 lines), `scripts/fetch-webcam.php` (1,046 lines)

**Issue**: Webcam code is split across multiple files but not organized by domain.

**Current Structure**:
```
api/webcam.php               # Endpoint + fetching logic
scripts/fetch-webcam.php     # Worker script + fetching logic
lib/push-webcam-validator.php  # Push webcam validation
```

**Proposed Structure**:
```
lib/webcam/
├── fetcher.php              # Main webcam fetching orchestration
│   ├── fetchWebcamImage()
│   └── fetchWebcamImageBackground()
├── processor.php            # Image processing (WEBP conversion, etc.)
├── staleness.php            # Webcam staleness handling
├── adapter/
│   ├── rtsp-v1.php          # RTSP adapter
│   │   └── fetchRTSPFrame()
│   ├── mjpeg-v1.php         # MJPEG adapter
│   │   └── fetchMJPEGStream()
│   ├── static-v1.php        # Static image adapter
│   │   └── fetchStaticImage()
│   └── push-v1.php         # Push upload adapter
│       └── (push processing)
└── validator.php            # Push webcam validation
    └── (moved from lib/push-webcam-validator.php)

api/webcam.php (reduced to ~200 lines)
└── Endpoint logic only

scripts/workers/webcam/fetch-webcam.php (reduced)
└── Worker orchestration only
```

**Estimated Effort**: Medium

---

### 2.2 Organize Circuit Breaker by Domain

**File**: `lib/circuit-breaker.php` (361 lines)

**Current**: All circuit breaker logic in one file with weather/webcam wrappers.

**Proposed**:
```
lib/circuit-breaker/
├── base.php                 # Base circuit breaker logic
│   ├── checkCircuitBreakerBase()
│   ├── recordCircuitBreakerFailureBase()
│   └── recordCircuitBreakerSuccessBase()
├── weather.php              # Weather-specific wrappers
│   ├── checkWeatherCircuitBreaker()
│   ├── recordWeatherFailure()
│   └── recordWeatherSuccess()
└── webcam.php               # Webcam-specific wrappers
    ├── checkWebcamCircuitBreaker()
    ├── recordWebcamFailure()
    └── recordWebcamSuccess()
```

**Estimated Effort**: Low (mostly moving code)

---

### 2.3 Split Constants by Domain

**File**: `lib/constants.php` (115 lines)

**Current**: All constants in one file.

**Proposed**:
```
lib/constants/
├── weather.php              # Weather-related constants
├── webcam.php               # Webcam-related constants
├── rate-limit.php           # Rate limiting constants
├── circuit-breaker.php      # Circuit breaker constants
├── staleness.php            # Data staleness thresholds
└── index.php                # Loads all constants (for backward compatibility)
```

**Estimated Effort**: Low

---

## 🟢 Priority 3: Code Quality Improvements

### 3.1 Add Missing Type Hints (Gradual)

**Strategy**: Add type hints when modifying functions, prioritize critical paths.

**High Priority Functions** (safety-critical):
- All functions in `lib/circuit-breaker.php`
- All functions in `lib/rate-limit.php`
- Staleness functions in `api/weather.php`
- Calculation functions in `api/weather.php`

**Estimated Effort**: Low (gradual, as we modify code)

---

### 3.2 Refactor Comments

**Issue**: Some comments may be transitory or outdated.

**Action Items**:
- Review comments in recently modified files
- Remove transitory comments explaining changes
- Ensure comments explain "why", not "what"
- Update outdated comments

**Estimated Effort**: Low (ongoing maintenance)

---

### 3.3 Improve Error Handling

**Issue**: Some error handling could be more explicit.

**Areas to Review**:
- File operations with `@` suppression - ensure fallbacks are documented
- API error handling - ensure all failure modes are handled
- Circuit breaker error paths - ensure graceful degradation

**Estimated Effort**: Low-Medium

---

## 📋 Refactoring Priority Summary

### Immediate (Do First)
1. ✅ **Organize weather code by domain** - Biggest impact on maintainability
2. ✅ **Add type hints to safety-critical functions** - Critical for safety
3. ✅ **Break down `fetchWeatherAsync()`** - Large, complex function

### Next (Do Soon)
4. ✅ **Organize webcam code by domain** - Similar to weather refactor
5. ✅ **Organize circuit breaker by domain** - Easy win
6. ✅ **Split constants by domain** - Easy win

### Ongoing (Do Gradually)
7. ✅ **Add type hints to all functions** - As we modify code
8. ✅ **Refactor comments** - As we modify code
9. ✅ **Improve error handling** - As we modify code

---

## Migration Notes

- **Breaking changes are OK** - Project is young, large improvements welcome
- **Use full PR process** - All refactoring should go through PRs
- **Update tests** - Ensure tests work with new structure
- **Update documentation** - Keep docs in sync with code structure
- **Gradual migration** - Don't try to do everything at once

---

## Success Criteria

After refactoring:
- ✅ Code organized by domain (weather, webcam, circuit-breaker)
- ✅ API adapters in separate files (easy to update when APIs change)
- ✅ Functions are focused and under 50 lines where possible
- ✅ Type hints on all safety-critical functions
- ✅ Easy to find and modify code
- ✅ Tests still pass
- ✅ Documentation updated

---

**Remember**: This is a safety-critical application. Refactoring should improve code quality, reliability, and maintainability while preserving all existing functionality.

