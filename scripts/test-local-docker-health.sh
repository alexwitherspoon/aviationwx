#!/bin/bash
# Test local Docker environment to ensure healthy container configs
# This script builds and tests the web container locally

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
DOCKER_DIR="$PROJECT_ROOT/docker"

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "Local Docker Health Test"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

cd "$DOCKER_DIR" || { echo "❌ ERROR: Cannot cd to docker directory"; exit 1; }

# Ensure airports.json exists
if [ ! -f "$PROJECT_ROOT/config/airports.json" ]; then
    echo "Creating airports.json from example..."
    cp "$PROJECT_ROOT/config/airports.json.example" "$PROJECT_ROOT/config/airports.json"
fi

echo "1. Stopping any existing containers..."
docker compose -f docker-compose.yml down 2>/dev/null || true
echo ""

echo "2. Building web container..."
docker compose -f docker-compose.yml build web
echo ""

echo "3. Starting web container..."
docker compose -f docker-compose.yml up -d web
echo ""

echo "4. Waiting for container to start (10 seconds)..."
sleep 10
echo ""

echo "5. Checking container status:"
docker compose -f docker-compose.yml ps web
echo ""

echo "6. Checking container logs (last 30 lines):"
docker compose -f docker-compose.yml logs web | tail -30
echo ""

echo "7. Checking if Apache process is running:"
if docker compose -f docker-compose.yml exec -T web ps aux 2>/dev/null | grep -E "apache|httpd" | head -3; then
    echo "✓ Apache process found"
else
    echo "❌ Apache process NOT found"
fi
echo ""

echo "8. Checking if port 80 is listening:"
if docker compose -f docker-compose.yml exec -T web netstat -tuln 2>/dev/null | grep :80; then
    echo "✓ Port 80 is listening"
else
    echo "❌ Port 80 is NOT listening"
fi
echo ""

echo "9. Testing HTTP response:"
if docker compose -f docker-compose.yml exec -T web curl -f -s http://localhost/ > /dev/null 2>&1; then
    echo "✓ Apache is responding to HTTP requests"
    HTTP_STATUS=$(docker compose -f docker-compose.yml exec -T web curl -s -o /dev/null -w "%{http_code}" http://localhost/ 2>/dev/null || echo "000")
    echo "  HTTP Status: $HTTP_STATUS"
else
    echo "❌ Apache is NOT responding to HTTP requests"
    echo "   Error details:"
    docker compose -f docker-compose.yml exec -T web curl -f http://localhost/ 2>&1 | head -5 || true
fi
echo ""

echo "10. Checking container health status:"
HEALTH_STATUS=$(docker inspect aviationwx-web 2>/dev/null | grep -A 5 '"Health"' | grep -o '"Status":"[^"]*"' | cut -d'"' -f4 || echo "unknown")
echo "  Health Status: $HEALTH_STATUS"
echo ""

if [ "$HEALTH_STATUS" = "healthy" ]; then
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo "✅ Container is HEALTHY"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo ""
    echo "Container is running and healthy. You can:"
    echo "  - Access it at: http://localhost:8080"
    echo "  - View logs: docker compose -f docker/docker-compose.yml logs -f web"
    echo "  - Stop it: docker compose -f docker/docker-compose.yml down"
    exit 0
elif [ "$HEALTH_STATUS" = "starting" ]; then
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo "⚠️  Container is still STARTING"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo ""
    echo "Container is starting but not yet healthy. This may be normal if:"
    echo "  - It was just started (healthcheck has start_period)"
    echo "  - Services are still initializing"
    echo ""
    echo "Wait a bit longer and check again, or review logs for issues."
    exit 0
else
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo "❌ Container is UNHEALTHY"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo ""
    echo "Container is not healthy. Check the logs above for errors."
    echo "Common issues:"
    echo "  - Apache not starting (check entrypoint script)"
    echo "  - Port conflicts"
    echo "  - Missing dependencies"
    echo ""
    echo "Full logs: docker compose -f docker/docker-compose.yml logs web"
    exit 1
fi

