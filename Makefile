# AviationWX Docker Management
# Quick commands for local development

.PHONY: help init build build-force up down restart logs shell test test-local test-error-detector smoke clean config

help: ## Show this help message
	@echo 'AviationWX Docker Management'
	@echo '=========================='
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-15s\033[0m %s\n", $$1, $$2}'

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

build: ## Build Docker containers (local development)
	@docker compose -f docker/docker-compose.local.yml -f docker/docker-compose.override.yml build

build-force: ## Force rebuild Docker containers (no cache)
	@docker compose -f docker/docker-compose.local.yml -f docker/docker-compose.override.yml build --no-cache

up: build ## Start containers (local development)
	@docker compose -f docker/docker-compose.local.yml -f docker/docker-compose.override.yml up -d

down: ## Stop containers
	@docker compose -f docker/docker-compose.local.yml -f docker/docker-compose.override.yml down

restart: ## Restart containers
	@docker compose -f docker/docker-compose.local.yml -f docker/docker-compose.override.yml restart

logs: ## View container logs
	@docker compose -f docker/docker-compose.local.yml -f docker/docker-compose.override.yml logs -f

shell: ## Open shell in web container
	@docker compose -f docker/docker-compose.local.yml -f docker/docker-compose.override.yml exec web bash

test: ## Test the application (quick curl test)
	@echo "Testing AviationWX..."
	@curl -f http://localhost:8080 || echo "✗ Homepage failed"
	@echo "✓ Tests complete"

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
update-config: ## Update configuration and restart
	@bash config/docker-config.sh
	@docker compose -f docker/docker-compose.local.yml -f docker/docker-compose.override.yml restart

