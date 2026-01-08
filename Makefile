# AviationWX Docker Management
# Quick commands for local development and testing
#
# SETUP OPTIONS:
#   With Secrets (maintainers):  Configure docker-compose.override.yml to mount secrets
#   Without Secrets (contrib.):  cp config/airports.json.example config/airports.json
#                                Mock mode auto-activates for development
#
# See docs/LOCAL_SETUP.md and docs/TESTING.md for complete documentation.

.PHONY: help init build build-force up down down-prod restart logs shell test test-unit test-integration test-browser test-local test-error-detector metrics-test smoke clean config config-check dev update-leaflet

help: ## Show this help message
	@echo ''
	@echo '\033[1mAviationWX Docker Management\033[0m'
	@echo '============================'
	@echo ''
	@echo '\033[1;33mDevelopment:\033[0m'
	@grep -E '^(dev|up|down|down-prod|restart|logs|shell):.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}'
	@echo ''
	@echo '\033[1;33mTesting:\033[0m'
	@grep -E '^(test|test-unit|test-integration|test-browser|test-local|metrics-test|smoke):.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}'
	@echo ''
	@echo '\033[1;33mConfiguration:\033[0m'
	@grep -E '^(init|config|config-check|config-example):.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}'
	@echo ''
	@echo '\033[1;33mBuild & Cleanup:\033[0m'
	@grep -E '^(build|build-force|clean|update-leaflet):.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}'
	@echo ''
	@echo 'See docs/LOCAL_SETUP.md for complete setup guide'
	@echo ''

init: ## Initialize environment (copy env.example to .env)
	@if [ ! -f .env ]; then \
		echo "Creating .env from config/env.example..."; \
		cp config/env.example .env; \
		echo "✓ Created .env - please edit with your settings"; \
	else \
		echo "✓ .env already exists"; \
	fi
	@chmod +x config/docker-config.sh

config: ## Generate configuration from .env
	@bash config/docker-config.sh

config-check: ## Validate current configuration and show mock mode status
	@echo "Configuration Check"
	@echo "==================="
	@php -r " \
		require 'lib/config.php'; \
		echo 'Config file: ' . (getConfigFilePath() ?: 'NOT FOUND') . PHP_EOL; \
		echo 'Test mode: ' . (isTestMode() ? 'YES' : 'NO') . PHP_EOL; \
		echo 'Mock mode: ' . (shouldMockExternalServices() ? 'YES (external services will be mocked)' : 'NO (real API calls)') . PHP_EOL; \
		echo 'Production: ' . (isProduction() ? 'YES' : 'NO') . PHP_EOL; \
		\$$config = loadConfig(); \
		if (\$$config) { \
			\$$airports = array_keys(\$$config['airports'] ?? []); \
			echo 'Airports: ' . count(\$$airports) . ' (' . implode(', ', array_slice(\$$airports, 0, 5)) . (count(\$$airports) > 5 ? '...' : '') . ')' . PHP_EOL; \
		} else { \
			echo 'ERROR: Could not load config' . PHP_EOL; \
		} \
	"

config-example: ## Copy example config for local development (mock mode)
	@if [ -f config/airports.json ]; then \
		echo "⚠️  config/airports.json already exists"; \
		echo "   Remove it first if you want to reset: rm config/airports.json"; \
	else \
		cp config/airports.json.example config/airports.json; \
		echo "✓ Created config/airports.json from example"; \
		echo "  Mock mode will auto-activate (test API keys detected)"; \
		echo "  Run 'make dev' to start development server"; \
	fi

build: ## Build Docker containers (local development)
	@docker compose -f docker/docker-compose.local.yml -f docker/docker-compose.override.yml build

build-force: ## Force rebuild Docker containers (no cache)
	@docker compose -f docker/docker-compose.local.yml -f docker/docker-compose.override.yml build --no-cache

up: build ## Start containers (local development)
	@docker compose -f docker/docker-compose.local.yml -f docker/docker-compose.override.yml up -d

down: ## Stop local development containers
	@docker compose -f docker/docker-compose.local.yml -f docker/docker-compose.override.yml down

down-prod: ## Stop production containers
	@docker compose -f docker/docker-compose.prod.yml down

restart: ## Restart containers (quick restart, doesn't pick up env var changes)
	@docker compose -f docker/docker-compose.local.yml -f docker/docker-compose.override.yml restart

restart-env: ## Restart containers and recreate to pick up environment variable changes
	@echo "Recreating containers to pick up environment variable changes..."
	@docker compose -f docker/docker-compose.local.yml -f docker/docker-compose.override.yml up -d --force-recreate
	@echo "✓ Containers recreated - environment variables updated"

logs: ## View container logs
	@docker compose -f docker/docker-compose.local.yml -f docker/docker-compose.override.yml logs -f

shell: ## Open shell in web container
	@docker compose -f docker/docker-compose.local.yml -f docker/docker-compose.override.yml exec web bash

test: ## Run all PHPUnit tests (unit + integration)
	@echo "Running all tests..."
	@APP_ENV=testing vendor/bin/phpunit --testdox

test-ci: ## Run all tests that GitHub CI runs (comprehensive)
	@echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
	@echo "Running CI Test Suite (matches GitHub Actions)"
	@echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
	@echo ""
	@echo "1️⃣  Validating PHP syntax..."
	@bash -c 'errors=$$(find . -name "*.php" -not -path "./vendor/*" -exec php -l {} \; 2>&1 | grep -v "No syntax errors" | grep -v "Deprecated:" | grep -v "PHP Deprecated:" || true); \
	if [ ! -z "$$errors" ]; then echo "❌ PHP syntax errors found:"; echo "$$errors"; exit 1; else echo "✓ All PHP files have valid syntax"; fi'
	@echo ""
	@echo "2️⃣  Running Unit Tests..."
	@APP_ENV=testing vendor/bin/phpunit --testsuite Unit --testdox --log-junit unit-results.xml --no-coverage || exit_code=$$?; \
	exit_code=$${exit_code:-$$?}; \
	if [ $$exit_code -gt 1 ]; then echo "❌ Unit tests failed"; exit 1; elif [ $$exit_code -eq 1 ]; then echo "⚠️  Unit tests passed with warnings"; else echo "✓ Unit tests passed"; fi
	@echo ""
	@echo "3️⃣  Running Integration Tests..."
	@APP_ENV=testing vendor/bin/phpunit --testsuite Integration --testdox --log-junit integration-results.xml --no-coverage || exit_code=$$?; \
	exit_code=$${exit_code:-$$?}; \
	if [ $$exit_code -gt 1 ]; then echo "❌ Integration tests failed"; exit 1; elif [ $$exit_code -eq 1 ]; then echo "⚠️  Integration tests passed with warnings"; else echo "✓ Integration tests passed"; fi
	@echo ""
	@echo "4️⃣  Running Critical Safety Tests..."
	@APP_ENV=testing vendor/bin/phpunit tests/Unit/WeatherCalculationsTest.php --testdox --stop-on-failure --no-coverage || exit_code=$$?; \
	if [ $$exit_code -gt 1 ]; then echo "❌ WeatherCalculationsTest failed"; exit 1; fi
	@APP_ENV=testing vendor/bin/phpunit tests/Unit/ErrorHandlingTest.php --testdox --stop-on-failure --no-coverage || exit_code=$$?; \
	if [ $$exit_code -gt 1 ]; then echo "❌ ErrorHandlingTest failed"; exit 1; fi
	@APP_ENV=testing vendor/bin/phpunit tests/Unit/WeatherAggregatorTest.php --testdox --stop-on-failure --no-coverage || exit_code=$$?; \
	if [ $$exit_code -gt 1 ]; then echo "❌ WeatherAggregatorTest failed"; exit 1; fi
	@echo "✓ Critical safety tests passed"
	@echo ""
	@echo "5️⃣  Validating JavaScript..."
	@php scripts/validate-javascript.php
	@echo ""
	@echo "6️⃣  Checking for required files..."
	@bash -c 'required_files=("index.php" "api/weather.php" "api/webcam.php" "lib/config.php" "lib/rate-limit.php" "lib/constants.php" "lib/circuit-breaker.php" "scripts/process-push-webcams.php" "lib/push-webcam-validator.php" "pages/config-generator.php" "pages/status.php"); \
	for file in "$${required_files[@]}"; do if [ ! -f "$$file" ]; then echo "❌ Required file missing: $$file"; exit 1; fi; done; \
	echo "✓ All required files present"'
	@echo ""
	@echo "7️⃣  Validating JSON files..."
	@bash -c 'if [ -f "config/airports.json.example" ]; then \
	php -r "json_decode(file_get_contents(\"config/airports.json.example\"), true); if (json_last_error() !== JSON_ERROR_NONE) { echo \"Invalid JSON: \" . json_last_error_msg() . PHP_EOL; exit(1); }"; \
	echo "✓ config/airports.json.example is valid JSON"; fi'
	@echo ""
	@echo "8️⃣  Testing config utilities..."
	@php scripts/ci-test-config.php
	@echo ""
	@echo "9️⃣  Testing rate limiting functions..."
	@php scripts/ci-test-rate-limit.php
	@echo ""
	@echo "🔟  Testing circuit breaker functions..."
	@php scripts/ci-test-circuit-breaker.php
	@echo ""
	@echo "✅ All CI tests passed!"
	@echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

test-unit: ## Run unit tests only (fast, no Docker needed)
	@echo "Running unit tests..."
	@APP_ENV=testing vendor/bin/phpunit --testsuite Unit --testdox

test-integration: ## Run integration tests only
	@echo "Running integration tests..."
	@APP_ENV=testing vendor/bin/phpunit --testsuite Integration --testdox

test-browser: up ## Run Playwright browser tests (requires Docker)
	@echo "Running browser tests..."
	@cd tests/Browser && npm install && npx playwright test

test-local: build-force up ## Rebuild containers and run PHPUnit tests locally
	@echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
	@echo "Rebuilding Docker containers and running local tests"
	@echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
	@echo ""
	@echo "Waiting for containers to be ready..."
	@sleep 5
	@echo ""
	@echo "Running PHPUnit tests..."
	@TEST_API_URL=http://localhost:8080 vendor/bin/phpunit --testdox || (echo ""; echo "⚠️  Some tests failed. Check output above."; exit 1)

test-error-detector: ## Test webcam error frame detector (requires running containers)
	@echo "Testing webcam error frame detector..."
	@docker compose -f docker/docker-compose.local.yml -f docker/docker-compose.override.yml exec -T web php /var/www/html/scripts/test-error-detector.php || (echo ""; echo "⚠️  Error detector test failed. Check output above."; exit 1)

metrics-test: ## Generate test metrics data for status page visualization
	@echo "Generating test metrics..."
	@php scripts/generate-test-metrics.php
	@echo "✓ Test metrics generated. View at: http://localhost:8080/pages/status.php"

smoke: ## Smoke test main endpoints (requires running containers)
	@echo "Smoke testing..."
	@echo "- Homepage" && curl -sf http://127.0.0.1:8080 >/dev/null && echo " ✓"
	@echo "- Weather (kspb)" && curl -sf "http://127.0.0.1:8080/api/weather.php?airport=kspb" | grep -q '"success":true' && echo " ✓" || echo " ✗"
	@echo "- Webcam fetch script (PHP present)" && docker compose -f docker/docker-compose.local.yml -f docker/docker-compose.override.yml exec -T web php -v >/dev/null && echo " ✓ (PHP OK)"

clean: ## Remove containers and volumes
	@docker compose -f docker/docker-compose.local.yml -f docker/docker-compose.override.yml down -v
	@docker system prune -f

# Production commands
deploy-prod: ## Deploy to production
	@echo "Deploying to production..."
	@docker compose -f docker/docker-compose.prod.yml up -d --build

logs-prod: ## View production logs
	@docker compose -f docker/docker-compose.prod.yml logs -f

# Quick development workflow
dev: init up logs ## Start development environment

# Testing workflow
test-rebuild: test-local ## Rebuild containers before testing (alias for test-local)

# Minification (optional - CSS minification for production)
minify: ## Minify CSS (requires perl or sed)
	@echo "Minifying CSS..."
	@perl -pe 's/\/\*.*?\*\///g; s/^\s*//; s/\s*$$//; s/\s+/ /g; s/\s*\{\s*/{/g; s/\s*\}\s*/}/g; s/\s*;\s*/;/g; s/\s*:\s*/:/g; s/\s*,\s*/,/g' public/css/styles.css > public/css/styles.min.css 2>/dev/null || \
	 sed 's|/\*.*\*/||g; s/^[[:space:]]*//; s/[[:space:]]*$$//; s/[[:space:]]\{1,\}/ /g' public/css/styles.css > public/css/styles.min.css 2>/dev/null || \
	 echo "Warning: minification failed (install perl or use online tool)"
	@if [ -f public/css/styles.min.css ]; then \
		echo "✓ Created public/css/styles.min.css"; \
		ls -lh public/css/styles.css public/css/styles.min.css; \
	fi

# Configuration update
update-config: ## Update configuration and restart (recreates containers to pick up env var changes)
	@bash config/docker-config.sh
	@docker compose -f docker/docker-compose.local.yml -f docker/docker-compose.override.yml up -d --force-recreate

# Guides validation
validate-guides: ## Validate guides markdown files
	@echo "Validating guides..."
	@php scripts/validate-guides.php

# Guides testing (requires running containers)
test-guides: ## Test guides pages (requires running containers)
	@echo "Testing guides pages..."
	@echo "- Guides index" && curl -sf http://127.0.0.1:8080 -H "Host: guides.localhost" >/dev/null && echo " ✓" || echo " ✗"
	@echo "- Guides 404" && curl -sf http://127.0.0.1:8080/nonexistent -H "Host: guides.localhost" | grep -q "404" && echo " ✓" || echo " ✗"

# Leaflet library management
update-leaflet: ## Update Leaflet library (install npm package and copy to public/)
	@echo "Updating Leaflet library..."
	@npm install
	@npm run build:leaflet
	@echo "✓ Leaflet updated successfully"

