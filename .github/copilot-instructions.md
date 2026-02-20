# GitHub Copilot Instructions – AviationWX.org

Repository custom instructions for GitHub Copilot coding agent and code review. This file provides context so Copilot can work efficiently with minimal exploration.

---

## Project Overview

**AviationWX.org** is a safety-critical application used by pilots for flight decisions. Real-time weather, webcams, and aviation metrics (density altitude, VFR/IFR status) are displayed per airport. Code quality, reliability, and graceful degradation are paramount.

### AviationWX.org Ecosystem

- **This repository**: Main application. Multi-airport platform with subdomain routing (e.g., `kspb.aviationwx.org`, `cyav.aviationwx.org`).
- **airports.aviationwx.org**: Airport directory map and listing (in `pages/airports.php`).
- **embed.aviationwx.org**: Embed generator for external weather widgets.
- **api.aviationwx.org**: Public API (OpenAPI spec, separate docs).
- **Secrets**: Production config (`airports.json` with API keys) lives in private repo `aviationwx.org-secrets`.

### Technology Stack

- **Backend**: PHP 8.x (no framework, minimal dependencies)
- **Frontend**: Vanilla JavaScript, CSS (no React/Vue)
- **Infrastructure**: Docker, nginx, PHP-FPM
- **Testing**: PHPUnit (Unit + Integration suites)
- **Data**: JSON config (no database), APCu caching
- **Maps**: Leaflet (airports directory)

---

## Build & Validation

**Always run `make test-ci` before committing or pushing.** This matches GitHub Actions validation.

### Commands (use these, in order)

| Purpose | Command | Notes |
|---------|---------|-------|
| **Start dev** | `make dev` | Builds containers, tails logs. Use Docker, never `php -S`. |
| **Run tests** | `make test-ci` | Required before commit. PHP syntax + PHPUnit + safety tests + JS validation. |
| **Faster tests** | `make test` | PHPUnit only (incomplete – misses syntax in untested files). |
| **Unit only** | `make test-unit` | Fast iteration. |
| **Config check** | `make config-check` | Validates config, shows mock mode. |
| **Without secrets** | `make config-example` then `make dev` | Mock mode auto-activates. |

### Local Development

- **URL**: http://localhost:8080
- **DO NOT** use `php -S localhost:8080` – always use Docker.
- **With secrets**: Mount `aviationwx.org-secrets/airports.json` via `docker-compose.override.yml`, set `CONFIG_PATH`.

### CI Pipeline (.github/workflows/test.yml)

Runs on PR and push to main: PHP syntax check, PHPUnit Unit + Integration, critical safety tests, JavaScript static analysis, config validation, required file checks. Replicate locally with `make test-ci`.

---

## Project Layout

```
aviationwx.org/
├── index.php              # Router – subdomain/query → airport or homepage
├── pages/
│   ├── airport.php        # Airport dashboard (weather, webcam, wind)
│   ├── airports.php       # Airport directory map
│   ├── homepage.php       # Homepage
│   └── ...
├── api/
│   ├── weather.php        # Weather JSON API
│   ├── webcam.php         # Webcam image server
│   └── ...
├── lib/
│   ├── config.php         # Config loading, env detection (use loadConfig(), never read JSON directly)
│   ├── constants.php      # MAX_STALE_HOURS, etc.
│   ├── weather/           # UnifiedFetcher, adapters, WeatherSnapshot
│   └── ...
├── scripts/
│   ├── scheduler.php      # Daemon – weather, webcam, NOTAM
│   ├── unified-webcam-worker.php
│   └── fetch-weather.php
├── tests/
│   ├── Unit/
│   ├── Integration/
│   └── Fixtures/airports.json.test
├── config/airports.json.example
├── docker/
├── CODE_STYLE.md          # Full coding standards – MUST follow
└── docs/ARCHITECTURE.md   # System design
```

### Key Files to Reference

- `CODE_STYLE.md` – Coding standards (PSR-12, comments, PHPDoc, testing)
- `docs/ARCHITECTURE.md` – Data flow, weather aggregation, webcam pipeline
- `lib/config.php` – `loadConfig()`, `isTestMode()`, `shouldMockExternalServices()`
- `lib/constants.php` – `MAX_STALE_HOURS`, `PLACEHOLDER_CACHE_TTL`
- `phpunit.xml` – Sets `APP_ENV=testing`, `CONFIG_PATH=tests/Fixtures/airports.json.test`

---

## Code Conventions

### Config & Environment

- Use `loadConfig()` – never read `airports.json` directly. Config is cached in APCu.
- Use `isTestMode()`, `shouldMockExternalServices()` for environment checks.
- Tests use `tests/Fixtures/airports.json.test`; mock mode is automatic.

### Error Handling

- **Never silently fail.** Handle errors explicitly.
- Use `aviationwx_log()` for structured logging.
- Per-airport degradation: one airport’s failure must not affect others.

### Naming

- Files: `lowercase-with-hyphens`
- Classes: `PascalCase`
- Functions: `camelCase`
- Constants: `UPPER_SNAKE_CASE`

### Test Naming

```php
testFunctionName_Scenario_ExpectedBehavior()
```

---

## Safety-Critical Requirements

### Data Staleness

- Data older than `MAX_STALE_HOURS` (3 hours) must be nulled out.
- Show "---" for stale/missing fields, never stale values.
- Always display timestamps to users.
- Use `nullStaleFieldsBySource()` for weather.

### Unit Handling

- Use `WeatherReading` factory methods: `celsius()`, `knots()`, `inHg()`, `feet()`.
- Never hardcode conversion factors – use `lib/units.php`.
- Document units in PHPDoc when handling weather data.

### Testing

- Bug fixes must include a test that would have caught the bug.
- New features need tests for critical paths.
- Safety-critical code (staleness, validation, calculations) must have tests.

---

## Code Review Checklist

When reviewing PRs, verify:

### Safety Critical
- [ ] Data staleness handled (no stale data shown)
- [ ] Units explicitly tracked and converted correctly
- [ ] Errors handled explicitly (no silent failures)

### Code Quality
- [ ] PSR-12 standards
- [ ] Comments focus on "why" not "what"
- [ ] PHPDoc on all public functions
- [ ] Type hints on new code

### Testing
- [ ] Bug fixes have tests
- [ ] Critical paths tested
- [ ] Test naming: `testFunctionName_Scenario_ExpectedBehavior`

### Security
- [ ] No credentials in code
- [ ] Input validated
- [ ] No sensitive data in logs

### Documentation
- [ ] Breaking changes documented
- [ ] Config changes documented
- [ ] Temporary docs cleaned up

---

## Severity Levels for Review Feedback

- **Blocker**: Safety issues, security vulnerabilities, data integrity risks
- **Important**: Missing tests, error handling gaps, code style violations
- **Suggestion**: Minor improvements, optional refactoring

---

## Trust These Instructions

Use this file as the primary source. Search the codebase only when information here is incomplete or incorrect. Before making changes, run `make test-ci` to validate.
