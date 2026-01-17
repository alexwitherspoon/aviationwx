# GitHub Copilot Code Review Instructions

This document provides context and guidelines for GitHub Copilot when reviewing pull requests for AviationWX.org.

## Project Context

**AviationWX.org is a safety-critical application used by pilots for flight decisions.** Code quality, reliability, and graceful degradation are paramount. When reviewing code, prioritize safety and clarity over cleverness.

### Technology Stack
- **Backend**: PHP 8.x (no framework, minimal dependencies)
- **Frontend**: Vanilla JavaScript, CSS
- **Infrastructure**: Docker, nginx, PHP-FPM
- **Testing**: PHPUnit
- **Data**: JSON files (no database), APCu caching

---

## Critical Review Areas

### 1. Data Staleness Handling (SAFETY CRITICAL)

**Always flag code that could show stale data to pilots.**

- Data older than `MAX_STALE_HOURS` (3 hours) must be nulled out
- Timestamps must always be displayed to users
- Warnings should appear when data exceeds staleness thresholds
- Fields should show "---" instead of stale values

**Flag if:**
- Weather/webcam data is displayed without age checks
- Stale data could be served without warnings
- Missing null checks for stale fields

### 2. Error Handling

**This application must never silently fail.**

- All errors must be handled explicitly
- Each airport should degrade independently
- Use `aviationwx_log()` for structured logging with context

**Flag if:**
- Errors are caught but not logged or handled
- Empty catch blocks
- Missing error handling for external API calls
- Silent failures that could affect data quality

### 3. Unit Handling (SAFETY CRITICAL)

**Incorrect unit handling is a common source of aviation accidents.**

- Always use `WeatherReading` factory methods: `celsius()`, `knots()`, `inHg()`, `feet()`, etc.
- Never hardcode conversion factors - use `lib/units.php`
- Document units in PHPDoc when handling weather data

**Flag if:**
- Raw numbers used without unit context
- Hardcoded conversion factors
- Ambiguous unit handling

---

## Code Style Requirements

### Comments

**Focus on "why" not "what"** - code should be self-documenting.

**Good comments explain:**
- Complex business logic or algorithms
- Non-obvious behavior or edge cases
- Safety-critical logic
- Race conditions or concurrency issues
- The rationale behind a decision

**Flag comments that:**
- Describe what code does (obvious from reading)
- Are transitory ("Changed X to Y", "Updated for new API")
- Include dates or version history (use git for that)
- Explain simple operations (`// Increment counter`)

### PHPDoc Requirements

All public functions must have PHPDoc blocks:

```php
/**
 * Brief description (one line preferred)
 *
 * @param string $airportId Airport ICAO identifier
 * @return array{success: bool, data: ?array, error: ?string}
 */
```

**Flag if:**
- Public functions missing PHPDoc
- Missing `@param` or `@return` annotations
- Outdated PHPDoc that doesn't match function signature

### PHP Standards

- Follow **PSR-12** coding standards
- Use type hints on all new code
- Use meaningful variable and function names
- Keep functions focused (single responsibility)

**Flag if:**
- Inconsistent naming conventions
- Missing type hints on new functions
- Functions doing too many things
- Inconsistent code style with existing codebase

---

## Testing Requirements

### What Must Have Tests

1. **Bug fixes** - Must include a test that would have caught the bug
2. **New features** - Critical paths and complex logic
3. **Safety-critical code** - Data staleness, validation, calculations

### Test Naming Convention

```php
testFunctionName_Scenario_ExpectedBehavior()
```

**Examples:**
- `testNullStaleFields_ExceedsMaxHours_NullsTemperature()`
- `testFetchWeather_ApiTimeout_ReturnsNull()`

**Flag if:**
- Bug fixes without corresponding tests
- New safety-critical code without tests
- Tests that don't follow naming convention
- Tests with unclear assertions

---

## Security Review Points

**Flag if:**
- API keys, passwords, or credentials in code
- Unsanitized user input
- Missing input validation on API endpoints
- Sensitive data in logs

---

## Configuration Management

- All config is in `airports.json` (validated on load)
- Use `loadConfig()` - never read JSON directly
- Config errors should fail fast in CI, isolate in production

**Flag if:**
- Direct file reads of airports.json
- Missing config validation
- Config changes without schema updates

---

## Dependencies

**Preference: Minimize dependencies.**

- Prefer PHP built-ins over libraries
- Use dependencies only for complex, mature software (e.g., ffmpeg)
- Avoid simple utility libraries

**Flag if:**
- New dependency added without justification
- Dependency for something PHP can do natively
- Missing documentation for why dependency is needed

---

## File Organization

### Naming Conventions
- **Files**: lowercase with hyphens (`circuit-breaker.php`)
- **Classes**: PascalCase (`CircuitBreaker`)
- **Functions**: camelCase (`getWeatherData()`)
- **Constants**: UPPER_SNAKE_CASE (`MAX_STALE_HOURS`)

### Structure
```
lib/           # Shared libraries
scripts/       # CLI scripts and workers
api/           # API endpoints
pages/         # Page templates
tests/Unit/    # Unit tests
tests/Integration/  # Integration tests
```

---

## Review Checklist Summary

When reviewing a PR, check for:

### Safety Critical
- [ ] Data staleness properly handled
- [ ] No stale data shown to users
- [ ] Units explicitly tracked and converted correctly
- [ ] Errors handled explicitly (no silent failures)

### Code Quality
- [ ] Follows PSR-12 standards
- [ ] Comments focus on "why" not "what"
- [ ] PHPDoc on all public functions
- [ ] Type hints on new code
- [ ] Meaningful names

### Testing
- [ ] Bug fixes have tests
- [ ] Critical paths tested
- [ ] Tests follow naming convention

### Security
- [ ] No credentials in code
- [ ] Input validated
- [ ] No sensitive data logged

### Documentation
- [ ] Breaking changes documented
- [ ] Config changes documented
- [ ] Temporary files cleaned up

---

## Tone and Approach

When providing feedback:

1. **Be constructive** - Explain why something is problematic
2. **Prioritize safety** - Safety issues are blockers, style issues are suggestions
3. **Reference guidelines** - Point to CODE_STYLE.md or this document
4. **Suggest improvements** - Don't just flag issues, offer solutions
5. **Acknowledge good patterns** - Recognize when code follows best practices

### Severity Levels

- **🔴 Blocker**: Safety-critical issues, security vulnerabilities, data integrity risks
- **🟡 Important**: Missing tests, error handling gaps, code style violations
- **🟢 Suggestion**: Minor improvements, optional refactoring, style preferences

---

## Quick Reference

### Key Files
- `CODE_STYLE.md` - Full coding standards
- `docs/ARCHITECTURE.md` - System design
- `lib/constants.php` - Application constants
- `.cursorrules` - Development environment rules

### Key Constants
- `MAX_STALE_HOURS = 3` - Maximum data age before nulling
- `WEATHER_STALENESS_WARNING_HOURS_METAR = 1` - Warning threshold
- `PLACEHOLDER_CACHE_TTL` - Webcam placeholder timeout

### Key Functions
- `loadConfig()` - Load airports.json (cached)
- `aviationwx_log()` - Structured logging
- `nullStaleFieldsBySource()` - Null stale weather fields
- `WeatherReading::celsius()` etc. - Unit-safe weather values
