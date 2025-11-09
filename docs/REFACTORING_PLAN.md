# Project Structure Refactoring Plan

This document outlines the complete refactoring plan to reorganize the project structure with improved file naming.

## Overview

**Goal**: Reorganize files into logical directories with better naming conventions while maintaining all functionality.

**Approach**: Incremental phases with testing after each phase to catch issues early.

## Directory Structure

```
aviationwx.org/
├── index.php                    # Main router (stays at root)
├── api/                         # Public API endpoints
│   ├── weather.php              # Renamed from weather.php
│   └── webcam.php               # Renamed from webcam.php
├── pages/                       # Public page templates
│   ├── airport.php              # Renamed from airport-template.php
│   ├── homepage.php             # Moved from root
│   ├── status.php               # Moved from root
│   └── error-404.php            # Renamed from 404.php
├── lib/                         # Utility/library files
│   ├── config.php               # Renamed from config-utils.php
│   ├── logger.php               # Moved from root
│   ├── rate-limit.php          # Moved from root
│   └── seo.php                  # Renamed from seo-utils.php
├── scripts/                     # Background/cron scripts
│   ├── fetch-weather.php        # Renamed from fetch-weather-safe.php
│   ├── fetch-webcam.php         # Renamed from fetch-webcam-safe.php
│   └── update-cache-version.sh  # Already here
├── admin/                       # Admin/diagnostics endpoints
│   ├── diagnostics.php          # Moved from root
│   ├── cache-clear.php          # Renamed from clear-cache.php
│   └── metrics.php              # Moved from root
├── health/                      # Health check endpoints
│   ├── health.php               # Moved from root
│   └── ready.php                # Moved from root
├── public/                      # Static assets
│   ├── css/
│   │   └── styles.css           # Moved from root
│   ├── js/
│   │   └── service-worker.js    # Renamed from sw.js
│   ├── images/
│   │   ├── placeholder.jpg      # Moved from root
│   │   └── about-photo.jpg      # Moved from root
│   ├── favicons/                # Renamed from aviationwx_favicons/
│   │   └── ...
│   └── robots.txt               # Moved from root
├── config/                      # Configuration files
│   ├── airports.json            # Moved from root
│   ├── airports.json.example    # Moved from root
│   ├── airports.json.test       # Moved from root
│   ├── env.example              # Moved from root
│   ├── crontab                  # Moved from root
│   └── docker-config.sh         # Already here
├── docker/                      # Docker/deployment files
│   ├── Dockerfile
│   ├── docker-compose.yml
│   ├── docker-compose.prod.yml
│   ├── docker-entrypoint.sh
│   └── nginx.conf
├── docs/                        # Documentation
│   └── [all .md files except README.md]
├── dev/                         # Development helpers
│   ├── router.php               # Renamed from test-local.php
│   └── test.sh                  # Renamed from test-local.sh
├── tests/                       # Test files (keep as is)
├── cache/                       # Runtime cache (keep as is)
└── [root config files: composer.json, phpunit.xml, Makefile, etc.]
```

## Phase-by-Phase Breakdown

### Phase 1: Create Directory Structure
**Status**: Pending  
**Risk**: Low  
**Testing**: Verify directories created

**Tasks**:
- Create all new directories: `lib/`, `api/`, `pages/`, `admin/`, `health/`, `public/`, `public/css/`, `public/js/`, `public/images/`, `public/favicons/`, `config/`, `docker/`, `docs/`, `dev/`

**Verification**:
- Run `ls -la` to confirm all directories exist
- Check directory permissions

---

### Phase 2: Move Utility Files to lib/
**Status**: Pending  
**Risk**: Medium (requires path updates)  
**Testing**: Run tests after path updates

**File Moves**:
- `config-utils.php` → `lib/config.php`
- `seo-utils.php` → `lib/seo.php`
- `logger.php` → `lib/logger.php`
- `rate-limit.php` → `lib/rate-limit.php`

**Path Updates Required**:

1. **PHP Files** (update `require_once` statements):
   - `index.php`: `config-utils.php` → `lib/config.php`
   - `weather.php`: `config-utils.php`, `rate-limit.php`, `logger.php` → `lib/`
   - `webcam.php`: `config-utils.php`, `rate-limit.php`, `logger.php` → `lib/`
   - `airport-template.php`: `seo-utils.php` → `lib/seo.php`
   - `homepage.php`: `seo-utils.php` → `lib/seo.php`
   - `404.php`: `seo-utils.php` → `lib/seo.php`
   - `status.php`: `config-utils.php`, `logger.php`, `seo-utils.php` → `lib/`
   - `clear-cache.php`: `config-utils.php` → `lib/config.php`
   - `metrics.php`: `logger.php` → `lib/logger.php`
   - `diagnostics.php`: `config-utils.php` → `lib/config.php`
   - `fetch-weather-safe.php`: `config-utils.php`, `logger.php` → `lib/`
   - `fetch-webcam-safe.php`: `config-utils.php`, `logger.php` → `lib/`

2. **Test Files**:
   - `tests/Unit/SeoUtilsTest.php`: `seo-utils.php` → `lib/seo.php`
   - `tests/Unit/ConfigValidationTest.php`: `config-utils.php` → `lib/config.php`
   - `tests/Unit/RateLimitTest.php`: `rate-limit.php` → `lib/rate-limit.php`
   - `tests/Unit/ErrorHandlingTest.php`: `logger.php` → `lib/logger.php`
   - All other test files that include these utilities

**Testing**:
- Run unit tests: `vendor/bin/phpunit`
- Test basic page loads: `curl http://localhost:8080/`
- Check error logs for missing file errors

---

### Phase 3: Move and Rename Scripts
**Status**: Pending  
**Risk**: Medium (cron jobs, includes)  
**Testing**: Test cron execution, verify includes work

**File Moves**:
- `fetch-weather-safe.php` → `scripts/fetch-weather.php`
- `fetch-webcam-safe.php` → `scripts/fetch-webcam.php`

**Path Updates Required**:

1. **PHP Includes**:
   - `webcam.php`: `fetch-webcam-safe.php` → `scripts/fetch-webcam.php`

2. **Cron Jobs** (`config/crontab`):
   - Update paths: `/var/www/html/fetch-webcam-safe.php` → `/var/www/html/scripts/fetch-webcam.php`
   - Update paths: `/var/www/html/fetch-weather-safe.php` → `/var/www/html/scripts/fetch-weather.php`

3. **Dockerfile**:
   - `COPY crontab` → `COPY config/crontab` (after Phase 9)

4. **Documentation**:
   - `LOCAL_COMMANDS.md`: Update all references
   - `ARCHITECTURE.md`: Update references
   - `README.md`: Update references
   - `PRODUCTION_DEPLOYMENT.md`: Update cron examples

5. **Test Files**:
   - `tests/Unit/WebcamFetchTest.php`: Update path
   - `tests/Unit/WebcamBackoffTest.php`: Update path
   - `tests/Integration/CronWeatherRefreshTest.php`: Update path
   - `tests/Integration/WebcamBackgroundRefreshTest.php`: Update path

6. **Scripts**:
   - `test-local.sh`: Update references

**Testing**:
- Manually run scripts: `php scripts/fetch-webcam.php`, `php scripts/fetch-weather.php`
- Verify cron jobs execute (if in Docker)
- Run integration tests

---

### Phase 4: Move Page Templates to pages/
**Status**: Pending  
**Risk**: Medium (routing, includes)  
**Testing**: Test all page routes

**File Moves**:
- `airport-template.php` → `pages/airport.php`
- `homepage.php` → `pages/homepage.php`
- `404.php` → `pages/error-404.php`
- `status.php` → `pages/status.php`

**Path Updates Required**:

1. **index.php** (main router):
   - `include 'airport-template.php'` → `include 'pages/airport.php'`
   - `include 'homepage.php'` → `include 'pages/homepage.php'`
   - `include '404.php'` → `include 'pages/error-404.php'`
   - `include 'status.php'` → `include 'pages/status.php'`

2. **Web Server Config** (`.htaccess` or `nginx.conf`):
   - Update 404 error handler if needed
   - Update status page routing if needed

**Testing**:
- Test homepage: `curl http://localhost:8080/`
- Test airport page: `curl http://localhost:8080/?airport=kspb`
- Test 404 page: `curl http://localhost:8080/nonexistent`
- Test status page: `curl http://localhost:8080/status.php` or subdomain

---

### Phase 5: Move API Endpoints to api/
**Status**: Pending  
**Risk**: High (routing, external references)  
**Testing**: Test all API endpoints

**File Moves**:
- `weather.php` → `api/weather.php`
- `webcam.php` → `api/webcam.php`

**Path Updates Required**:

1. **Web Server Config** (`.htaccess`):
   - Add rewrite rules for `/weather.php` → `/api/weather.php`
   - Add rewrite rules for `/webcam.php` → `/api/webcam.php`
   - Or update all references to use new paths

2. **Web Server Config** (`nginx.conf`):
   - Update location blocks for weather and webcam endpoints
   - Update proxy_pass if using reverse proxy

3. **PHP Files** (if they reference API endpoints):
   - `airport-template.php`: Check for AJAX calls to `weather.php` or `webcam.php`
   - `homepage.php`: Check for API references
   - `fetch-weather-safe.php`: May call weather endpoint internally

4. **Documentation**:
   - `API.md`: Update all endpoint paths
   - `README.md`: Update API examples
   - All other docs with API references

5. **Test Files**:
   - `tests/Integration/WeatherApiTest.php`: Update paths
   - `tests/Integration/WeatherEndpointTest.php`: Update paths
   - All test files that call API endpoints

**Testing**:
- Test weather API: `curl http://localhost:8080/api/weather.php?airport=kspb`
- Test webcam API: `curl http://localhost:8080/api/webcam.php?id=kspb&cam=0`
- Test from browser (airport pages should load weather/webcam data)
- Run integration tests

---

### Phase 6: Move Admin Files to admin/
**Status**: Pending  
**Risk**: Medium (routing, security)  
**Testing**: Test admin endpoints, verify security

**File Moves**:
- `diagnostics.php` → `admin/diagnostics.php`
- `clear-cache.php` → `admin/cache-clear.php`
- `metrics.php` → `admin/metrics.php`

**Path Updates Required**:

1. **Web Server Config** (`.htaccess`):
   - Update security rules (currently blocks these files)
   - Add rewrite rules if needed: `/diagnostics.php` → `/admin/diagnostics.php`
   - Add rewrite rules: `/clear-cache.php` → `/admin/cache-clear.php`
   - Add rewrite rules: `/metrics.php` → `/admin/metrics.php`

2. **Web Server Config** (`nginx.conf`):
   - Update location blocks for admin endpoints
   - Ensure security rules still apply

3. **PHP Files**:
   - `diagnostics.php`: Update internal paths (if any)
   - Check for references to these files in other PHP files

4. **Documentation**:
   - `README.md`: Update admin endpoint paths
   - `SECURITY.md`: Update paths if mentioned
   - All docs with admin references

**Testing**:
- Test diagnostics: `curl http://localhost:8080/admin/diagnostics.php`
- Test cache clear: `curl http://localhost:8080/admin/cache-clear.php`
- Test metrics: `curl http://localhost:8080/admin/metrics.php`
- Verify security rules still block unauthorized access

---

### Phase 7: Move Health Checks to health/
**Status**: Pending  
**Risk**: Low (simple endpoints)  
**Testing**: Test health endpoints

**File Moves**:
- `health.php` → `health/health.php`
- `ready.php` → `health/ready.php`

**Path Updates Required**:

1. **Web Server Config** (`.htaccess`):
   - Add rewrite rules: `/health.php` → `/health/health.php`
   - Add rewrite rules: `/ready.php` → `/health/ready.php`

2. **Web Server Config** (`nginx.conf`):
   - Update location blocks for health endpoints

3. **Docker Configuration**:
   - `docker-compose.yml`: Update healthcheck URLs
   - `docker-compose.prod.yml`: Update healthcheck URLs
   - `Dockerfile`: Update HEALTHCHECK command if needed

4. **Documentation**:
   - Update any references to health endpoints

**Testing**:
- Test health: `curl http://localhost:8080/health/health.php`
- Test ready: `curl http://localhost:8080/health/ready.php`
- Verify Docker healthchecks work

---

### Phase 8: Move Static Assets to public/
**Status**: Pending  
**Risk**: High (many references)  
**Testing**: Test all pages load assets correctly

**File Moves**:
- `styles.css` → `public/css/styles.css`
- `sw.js` → `public/js/service-worker.js`
- `placeholder.jpg` → `public/images/placeholder.jpg`
- `about-photo.jpg` → `public/images/about-photo.jpg`
- `aviationwx_favicons/` → `public/favicons/`
- `robots.txt` → `public/robots.txt`

**Path Updates Required**:

1. **PHP Files** (HTML/CSS/JS references):
   - `airport-template.php`:
     - `styles.css` → `public/css/styles.css`
     - `sw.js` → `public/js/service-worker.js`
     - `placeholder.jpg` → `public/images/placeholder.jpg`
     - `about-photo.jpg` → `public/images/about-photo.jpg`
     - `aviationwx_favicons/` → `public/favicons/`
   - `homepage.php`:
     - `styles.css` → `public/css/styles.css`
     - `about-photo.jpg` → `public/images/about-photo.jpg`
     - `aviationwx_favicons/` → `public/favicons/`
   - `404.php`:
     - `styles.css` → `public/css/styles.css`
     - `aviationwx_favicons/` → `public/favicons/`
   - `status.php`: Check for asset references

2. **JavaScript** (`sw.js` → `public/js/service-worker.js`):
   - Update cache list in service worker
   - Update registration path in `airport-template.php`

3. **PHP Files** (file_exists checks):
   - `webcam.php`: `placeholder.jpg` → `public/images/placeholder.jpg`
   - `seo-utils.php`: `aviationwx_favicons/` → `public/favicons/`
   - `seo-utils.php`: `about-photo.jpg` → `public/images/about-photo.jpg`

4. **Web Server Config** (`.htaccess`):
   - Update static file serving rules
   - Ensure CSS/JS/images are served correctly

5. **Web Server Config** (`nginx.conf`):
   - Update location blocks for static assets
   - Update cache rules for CSS/JS/images

6. **Scripts**:
   - `scripts/update-cache-version.sh`: `sw.js` → `public/js/service-worker.js`

7. **Makefile**:
   - Update minification paths: `styles.css` → `public/css/styles.css`

8. **Documentation**:
   - Update all references to static assets

9. **Test Files**:
   - Update any test files that reference static assets

**Testing**:
- Load homepage in browser, verify CSS loads
- Load airport page, verify all assets load (CSS, images, favicons)
- Test service worker registration
- Check browser console for 404 errors
- Verify robots.txt is accessible

---

### Phase 9: Move Config Files to config/
**Status**: Pending  
**Risk**: High (many references, Docker mounts)  
**Testing**: Test config loading, Docker mounts

**File Moves**:
- `airports.json` → `config/airports.json`
- `airports.json.example` → `config/airports.json.example`
- `airports.json.test` → `config/airports.json.test`
- `env.example` → `config/env.example`
- `crontab` → `config/crontab`

**Path Updates Required**:

1. **PHP Files** (config loading):
   - `lib/config.php`: Update default path: `__DIR__ . '/../config/airports.json'`
   - Check `CONFIG_PATH` env var handling
   - `diagnostics.php`: Update config file path checks

2. **Docker Configuration**:
   - `docker-compose.yml`: Update volume mount: `./airports.json` → `./config/airports.json`
   - `docker-compose.prod.yml`: Update volume mount: `/home/aviationwx/airports.json` → (check if path changes)
   - `Dockerfile`: `COPY crontab` → `COPY config/crontab`

3. **Documentation**:
   - `README.md`: Update config file paths
   - `CONFIGURATION.md`: Update all paths
   - `LOCAL_SETUP.md`: Update paths
   - `PRODUCTION_DEPLOYMENT.md`: Update paths
   - All docs with config references

4. **Test Files**:
   - `tests/Fixtures/airports.json.test`: May need path updates
   - All test files that reference config files

5. **Scripts**:
   - Any scripts that reference config files

**Testing**:
- Test config loading: Verify airports load correctly
- Test Docker mounts: Verify config file is accessible in container
- Run tests that use config files
- Verify cron jobs still work (after Dockerfile update)

---

### Phase 10: Move Docker Files to docker/
**Status**: Pending  
**Risk**: Medium (build context, volume mounts)  
**Testing**: Test Docker builds and runs

**File Moves**:
- `Dockerfile` → `docker/Dockerfile`
- `docker-compose.yml` → `docker/docker-compose.yml`
- `docker-compose.prod.yml` → `docker/docker-compose.prod.yml`
- `docker-entrypoint.sh` → `docker/docker-entrypoint.sh`
- `nginx.conf` → `docker/nginx.conf`

**Path Updates Required**:

1. **Dockerfile**:
   - Update `COPY` commands for new paths
   - `COPY crontab` → `COPY config/crontab` (after Phase 9)
   - `COPY docker-entrypoint.sh` → Already in docker/, but path changes
   - Update all relative paths

2. **docker-compose.yml**:
   - Update build context: `build: .` → `build: ./docker` or `context: .` with `dockerfile: docker/Dockerfile`
   - Update volume mounts for new paths
   - Update nginx volume: `./nginx.conf` → `./docker/nginx.conf`

3. **docker-compose.prod.yml**:
   - Same updates as docker-compose.yml
   - Update nginx volume mount

4. **GitHub Actions** (`.github/workflows/deploy-docker.yml`):
   - Update docker-compose file reference: `docker-compose.prod.yml` → `docker/docker-compose.prod.yml`
   - Check if build context needs updating

5. **Documentation**:
   - `DOCKER_DEPLOYMENT.md`: Update all paths
   - `PRODUCTION_DEPLOYMENT.md`: Update docker-compose commands
   - `LOCAL_SETUP.md`: Update docker-compose commands
   - All docs with Docker references

**Testing**:
- Test Docker build: `docker build -f docker/Dockerfile .`
- Test docker-compose: `docker compose -f docker/docker-compose.yml up`
- Test production compose: `docker compose -f docker/docker-compose.prod.yml up`
- Verify all volumes mount correctly
- Verify nginx config loads

---

### Phase 11: Move Documentation to docs/
**Status**: Pending  
**Risk**: Low (mostly references)  
**Testing**: Verify links work

**File Moves**:
- All `.md` files except `README.md` → `docs/`

**Path Updates Required**:

1. **README.md**:
   - Update all links to other docs: `[LOCAL_SETUP.md](LOCAL_SETUP.md)` → `[LOCAL_SETUP.md](docs/LOCAL_SETUP.md)`
   - Update all relative links

2. **Documentation Files**:
   - Update cross-references between docs
   - Update any relative links

3. **Other Files**:
   - Check for any code comments or scripts that reference docs

**Testing**:
- Verify all links in README.md work
- Check cross-references in docs
- Verify GitHub renders docs correctly

---

### Phase 12: Move Dev Tools to dev/
**Status**: Pending  
**Risk**: Low  
**Testing**: Test dev tools work

**File Moves**:
- `test-local.php` → `dev/router.php`
- `test-local.sh` → `dev/test.sh`

**Path Updates Required**:

1. **Documentation**:
   - `LOCAL_COMMANDS.md`: Update references
   - `LOCAL_SETUP.md`: Update references
   - Any other docs with dev tool references

2. **Scripts**:
   - Update any scripts that call these files

**Testing**:
- Test dev router: `php dev/router.php`
- Test dev script: `bash dev/test.sh`

---

### Phase 13: Update nginx.conf
**Status**: Pending  
**Risk**: Medium (routing, static assets)  
**Testing**: Test all routes work

**Updates Required**:

1. **API Routes**:
   - Update `/weather.php` → `/api/weather.php`
   - Update `/webcam.php` → `/api/webcam.php`

2. **Admin Routes**:
   - Update `/diagnostics.php` → `/admin/diagnostics.php`
   - Update `/clear-cache.php` → `/admin/cache-clear.php`
   - Update `/metrics.php` → `/admin/metrics.php`

3. **Health Routes**:
   - Update `/health.php` → `/health/health.php`
   - Update `/ready.php` → `/health/ready.php`

4. **Static Assets**:
   - Update CSS: `/styles.css` → `/public/css/styles.css`
   - Update JS: `/sw.js` → `/public/js/service-worker.js`
   - Update images: `/placeholder.jpg` → `/public/images/placeholder.jpg`
   - Update images: `/about-photo.jpg` → `/public/images/about-photo.jpg`
   - Update favicons: `/aviationwx_favicons/` → `/public/favicons/`
   - Update robots.txt: `/robots.txt` → `/public/robots.txt`

5. **Sitemap**:
   - Verify `/sitemap.xml` → `/api/sitemap.php` still works (sitemap.php moved to api/)

**Testing**:
- Test all API endpoints
- Test all admin endpoints
- Test all health endpoints
- Test static assets load
- Test sitemap
- Test subdomain routing still works

---

### Phase 14: Update .htaccess
**Status**: Pending  
**Risk**: Medium (routing, security)  
**Testing**: Test all routes work, verify security

**Updates Required**:

1. **Rewrite Rules**:
   - Add rules for API endpoints
   - Add rules for admin endpoints
   - Add rules for health endpoints
   - Add rules for static assets
   - Update existing rules if needed

2. **Security Rules**:
   - Update file blocking rules for new paths
   - Ensure config files still blocked
   - Ensure test files still blocked
   - Update admin endpoint blocking (if needed)

3. **Static File Serving**:
   - Ensure CSS/JS/images are served correctly
   - Update cache headers if needed

**Testing**:
- Test all routes work
- Verify security rules still apply
- Test static assets serve correctly
- Verify 404 handling works

---

### Phase 15: Update Deployment Scripts
**Status**: Pending  
**Risk**: Medium (deployment)  
**Testing**: Test deployment workflow

**Updates Required**:

1. **GitHub Actions** (`.github/workflows/deploy-docker.yml`):
   - Update docker-compose file path: `docker-compose.prod.yml` → `docker/docker-compose.prod.yml`
   - Update script paths: `scripts/update-cache-version.sh` (should still work)
   - Update service worker path: `sw.js` → `public/js/service-worker.js` in script
   - Verify rsync excludes still work

2. **Other Deployment Scripts**:
   - Check for any other deployment scripts
   - Update paths as needed

**Testing**:
- Test deployment workflow (if possible in test environment)
- Verify all paths are correct
- Check deployment logs

---

### Phase 16: Update Tests
**Status**: Pending  
**Risk**: Medium (test coverage)  
**Testing**: Run all tests

**Updates Required**:

1. **Unit Tests**:
   - Update all `require_once` paths for lib files
   - Update all file path references
   - Update config file paths

2. **Integration Tests**:
   - Update API endpoint paths
   - Update script paths
   - Update config file paths

3. **Browser Tests**:
   - Update asset paths if needed
   - Update API endpoint paths

4. **Test Fixtures**:
   - Update paths to fixtures if needed

**Testing**:
- Run all unit tests: `vendor/bin/phpunit tests/Unit/`
- Run all integration tests: `vendor/bin/phpunit tests/Integration/`
- Run browser tests if available
- Verify all tests pass

---

### Phase 17: Final Verification and Cleanup
**Status**: Pending  
**Risk**: Low  
**Testing**: Full system test

**Tasks**:
- Remove any old files (if not already moved)
- Verify no broken links
- Run full test suite
- Manual testing of all features
- Check error logs
- Verify deployment works

**Testing**:
- Full manual test of all features
- Run complete test suite
- Check for any remaining old path references
- Verify production deployment (if possible)

---

## Testing Strategy

### After Each Phase:
1. **Unit Tests**: Run `vendor/bin/phpunit` for affected components
2. **Integration Tests**: Run integration tests for affected features
3. **Manual Testing**: Test affected features manually
4. **Error Logs**: Check for missing file errors
5. **Browser Console**: Check for 404 errors on assets

### Final Testing:
1. **Full Test Suite**: Run all tests
2. **Manual Feature Test**: Test all major features
3. **Browser Testing**: Test in multiple browsers
4. **Docker Testing**: Test Docker build and run
5. **Deployment Test**: Test deployment workflow (if possible)

## Rollback Plan

If issues are found:
1. Revert the phase that introduced the issue
2. Fix the issue
3. Re-apply the phase
4. Continue with next phase

## Notes

- **Order Matters**: Some phases depend on others (e.g., Phase 9 before Phase 10)
- **Test Incrementally**: Don't wait until the end to test
- **Update Documentation**: Keep docs updated as you go
- **Commit Frequently**: Commit after each successful phase
- **Backup**: Consider creating a backup branch before starting

