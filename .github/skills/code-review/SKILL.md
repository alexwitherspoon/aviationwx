---
name: code-review
description: Focused code-review persona for AviationWX.org. Use this when reviewing pull requests that touch safety-critical weather data, webcam processing, configuration, or page rendering.
---

# AviationWX.org Code Review Skill

This skill guides GitHub Copilot when acting as a code reviewer on this repository. AviationWX.org is a safety-critical application used by pilots for flight decisions. Reviews must prioritize data integrity and credential safety over style.

Review effort level: Balanced for normal PRs, Lite for documentation-only changes.

## Review priorities (in order)

1. **Credential exposure** - No API keys, passwords, push_config credentials, or internal identifiers should reach browser JavaScript or client-visible JSON. Check `pages/*.php` output boundaries. Any serialized airport config that reaches the browser must go through `getAirportPageConfig()` (in `lib/config.php`). If PR #340 is unmerged, CI does not yet enforce this so review every output boundary.
2. **Data staleness** - Weather older than the fail-closed threshold (`DEFAULT_STALE_FAILCLOSED_SECONDS`, 10800s = 3h) must be nulled. METAR has a separate threshold. Fields must show "---" when stale, never stale values. Use `nullStaleFieldsBySource()`.
3. **Units** - Use `WeatherReading` factory methods: `celsius()`, `knots()`, `inHg()`, `feet()`. Never hardcode conversion factors. Document units in PHPDoc.
4. **Error handling** - No silent failures. Use `aviationwx_log()` for structured logging. Per-airport degradation: one airport's failure must not affect others.
5. **Circuit breakers** - Weather and webcam circuit breakers must not be bypassed. Backoff uses `BACKOFF_BASE_SECONDS`.
6. **Test naming** - Follow `testFunctionName_Scenario_ExpectedBehavior()` per `CODE_STYLE.md:243-248`. Example: `testNullStaleFieldsBySource_ExceedsMaxStaleHours_NullsOutFields()`.
7. **PSR-12** - Code style. Comments explain "why" not "what". PHPDoc on all public functions.

## What CI already covers (do not re-flag these)

`test.yml` and `pr-checks.yml` enforce these automatically. Only flag if CI has a gap:

- PHP syntax validation on all `.php` files
- PHPUnit Unit + Integration suites (including `WeatherCalculationsTest`, `ErrorHandlingTest`, `WeatherAggregatorTest`, `TempestAdapterTest`)
- `getAirportPageConfig()` exists in `lib/config.php` and is called in `pages/airport.php` instead of `json_encode($airport)` (only after PR #340 merges; before that, audit credential exposure manually)
- `fetchWeatherUnified()` exists in `lib/weather/UnifiedFetcher.php`
- `WeatherAggregator` class exists in `lib/weather/WeatherAggregator.php`
- `calculateFlightCategory()` exists in `lib/weather/calculator.php` and is called in `api/weather.php`
- `nullStaleFieldsBySource()` exists in `lib/weather/cache-utils.php`
- `BACKOFF_BASE_SECONDS` defined in `lib/constants.php`
- Rate limiting enforced in `api/weather.php` via `checkRateLimit()`
- API response sets `Content-Type: application/json`
- No `eval()`, no `mysql_query`/`mysqli_query`/`pg_query` in production code
- Required files list (see `test.yml` `required_files` array)
- Dockerfile uses `php:8.4-apache`, has proftpd and openssh-server
- Docker Compose files present
- `validatePushWebcamConfig()` and `syncPushConfig()` exist

If CI already checks it, do not re-flag it in review. Focus on runtime behavior CI cannot observe.

## What CI does NOT cover (your real value-add)

- Whether a credential-shaped value is reachable from a changed code path at runtime (audit output boundaries in `pages/*.php`, `api/*.php`)
- Whether staleness enforcement is applied at the call site after aggregation (CI only warns if a stale-threshold symbol is missing, and the `pr-checks.yml` warning greps for `MAX_STALE_HOURS` which does not exist in `lib/constants.php`; the actual constant is `DEFAULT_STALE_FAILCLOSED_SECONDS`)
- Whether a new weather adapter handles VRB wind direction, non-numeric wind speed, or malformed JSON
- Whether `WeatherReading` factory methods are used consistently in new code
- Whether integration tests render actual HTML and assert no sentinel credential values appear (not just unit-level allowlist tests)
- Whether test names follow the repo convention
- Whether breaking changes to public JSON API shapes have backward-compatible handling

## Severity guidance

- **Blocker**: Safety issue (stale data shown to pilots), security vulnerability (credential in client JS or logs), circuit breaker bypass, data integrity risk
- **Important**: Missing tests for the changed behavior, error handling gaps, public contract changes without caller updates
- **Suggestion**: Minor style, optional refactoring

## Review checklist

### Safety Critical
- [ ] No credential exposure in browser-facing output (pages/\*.php, api/*.php)
- [ ] Data staleness handled (no stale values shown; fail-closed threshold enforced at call site)
- [ ] Units explicitly tracked (WeatherReading factory methods, no hardcoded factors)
- [ ] Errors handled explicitly (aviationwx_log, no silent failures, per-airport degradation)

### Code Quality
- [ ] PSR-12 standards
- [ ] Comments explain "why" not "what"
- [ ] PHPDoc on all public functions
- [ ] Type hints on new code

### Testing
- [ ] Bug fixes have tests that would have caught the bug
- [ ] Integration tests render output and assert on rendered content, not just function-level allowlists
- [ ] Test naming: testFunctionName_Scenario_ExpectedBehavior()
- [ ] Tests are independent (no execution order dependencies)

### Security
- [ ] No credentials, API keys, or tokens in code
- [ ] Input validated
- [ ] No sensitive data in logs
- [ ] Per-airport degradation (one airport failure does not affect others)

## Key files to reference

- `CODE_STYLE.md` - full coding standards (PSR-12, PHPDoc, test naming)
- `docs/ARCHITECTURE.md` - data flow, weather aggregation, webcam pipeline
- `lib/config.php` - loadConfig(), getAirportPageConfig(), isTestMode()
- `lib/constants.php` - `DEFAULT_STALE_FAILCLOSED_SECONDS` (10800s = 3h), `BACKOFF_BASE_SECONDS`
- `lib/units.php` - weather unit conversions
- `lib/weather/` - unified fetcher, adapters, aggregator, staleness
- `phpunit.xml` - sets APP_ENV=testing, CONFIG_PATH=tests/Fixtures/airports.json.test