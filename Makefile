# AviationWX Docker Management
# Quick commands for local development

.PHONY: help init build up down restart logs shell test smoke clean config

help: ## Show this help message
	@echo 'AviationWX Docker Management'
	@echo '=========================='
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-15s\033[0m %s\n", $$1, $$2}'

init: ## Initialize environment (copy env.example to .env)
	@if [ ! -f .env ]; then \
		echo "Creating .env from env.example..."; \
		cp env.example .env; \
		echo "✓ Created .env - please edit with your settings"; \
	else \
		echo "✓ .env already exists"; \
	fi
	@chmod +x config/docker-config.sh

config: ## Generate configuration from .env
	@bash config/docker-config.sh

build: ## Build Docker containers
	@docker compose build

up: build ## Start containers
	@docker compose up -d

down: ## Stop containers
	@docker compose down

restart: ## Restart containers
	@docker compose restart

logs: ## View container logs
	@docker compose logs -f

shell: ## Open shell in web container
	@docker compose exec web bash

test: ## Test the application
	@echo "Testing AviationWX..."
	@curl -f http://localhost:8080 || echo "✗ Homepage failed"
	@echo "✓ Tests complete"

smoke: ## Smoke test main endpoints (requires running containers)
    @echo "Smoke testing..."
    @echo "- Homepage" && curl -sf http://127.0.0.1:8080 >/dev/null && echo " ✓"
    @echo "- Weather (kspb)" && curl -sf "http://127.0.0.1:8080/weather.php?airport=kspb" | grep -q '"success":true' && echo " ✓" || echo " ✗"
    @echo "- Webcam fetch script (PHP present)" && docker compose exec -T web php -v >/dev/null && echo " ✓ (PHP OK)"

clean: ## Remove containers and volumes
	@docker compose down -v
	@docker system prune -f

# Production commands
deploy-prod: ## Deploy to production
	@echo "Deploying to production..."
	@docker compose -f docker-compose.prod.yml up -d --build

logs-prod: ## View production logs
	@docker compose -f docker-compose.prod.yml logs -f

# Quick development workflow
dev: init up logs ## Start development environment

# Configuration update
update-config: ## Update configuration and restart
	@bash config/docker-config.sh
	@docker compose restart

