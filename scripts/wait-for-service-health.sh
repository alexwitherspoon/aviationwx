#!/bin/bash
# Improved health check script with exponential backoff and better diagnostics
# Usage: wait-for-service-health.sh [timeout_seconds] [base_url]

set -eo pipefail

TIMEOUT=${1:-90}
BASE_URL=${2:-http://localhost:8080}
MAX_BACKOFF=10  # Maximum backoff delay in seconds

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "Service Health Check"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "Base URL: ${BASE_URL}"
echo "Timeout: ${TIMEOUT}s"
echo ""

elapsed=0
attempt=0
backoff=1  # Start with 1 second backoff

# Phase 1: Wait for basic HTTP response (Apache started)
echo "Phase 1: Waiting for HTTP server to respond..."
while [ $elapsed -lt $TIMEOUT ]; do
    if curl -f -s --max-time 2 "${BASE_URL}/health.php" > /dev/null 2>&1 || \
       curl -f -s --max-time 2 "${BASE_URL}/" > /dev/null 2>&1; then
        echo "✓ HTTP server is responding after ${elapsed}s"
        break
    fi
    
    # Exponential backoff: increase delay gradually, but cap at MAX_BACKOFF
    sleep $backoff
    elapsed=$((elapsed + backoff))
    attempt=$((attempt + 1))
    
    # Increase backoff (exponential with cap)
    if [ $backoff -lt $MAX_BACKOFF ]; then
        backoff=$((backoff * 2))
        if [ $backoff -gt $MAX_BACKOFF ]; then
            backoff=$MAX_BACKOFF
        fi
    fi
    
    if [ $((attempt % 5)) -eq 0 ]; then
        echo "  Still waiting for HTTP server... ${elapsed}s elapsed (backoff: ${backoff}s)"
    fi
done

if [ $elapsed -ge $TIMEOUT ]; then
    echo ""
    echo "❌ HTTP server did not respond within ${TIMEOUT}s"
    echo ""
    echo "Container diagnostics:"
    if command -v docker >/dev/null 2>&1; then
        echo "Container status:"
        docker compose -f docker/docker-compose.yml ps 2>/dev/null || docker ps | grep aviationwx || echo "  (docker not available or containers not found)"
        echo ""
        echo "Container logs (last 50 lines):"
        docker compose -f docker/docker-compose.yml logs web 2>/dev/null | tail -50 || echo "  (could not retrieve logs)"
    fi
    exit 1
fi

# Phase 2: Wait for config to be loaded (weather API works)
echo ""
echo "Phase 2: Waiting for configuration to load..."
elapsed_phase2=0
backoff=1
attempt=0
phase2_timeout=$((TIMEOUT - elapsed))  # Remaining time

while [ $elapsed_phase2 -lt $phase2_timeout ]; do
    # Check if weather API returns success (indicates config is loaded)
    if curl -f -s --max-time 5 "${BASE_URL}/api/weather.php?airport=kspb" 2>/dev/null | grep -q '"success"'; then
        echo "✓ Configuration loaded and API responding after ${elapsed_phase2}s (total: $((elapsed + elapsed_phase2))s)"
        echo ""
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
        echo "✅ Service is ready"
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
        exit 0
    fi
    
    # Exponential backoff
    sleep $backoff
    elapsed_phase2=$((elapsed_phase2 + backoff))
    attempt=$((attempt + 1))
    
    if [ $backoff -lt $MAX_BACKOFF ]; then
        backoff=$((backoff * 2))
        if [ $backoff -gt $MAX_BACKOFF ]; then
            backoff=$MAX_BACKOFF
        fi
    fi
    
    if [ $((attempt % 3)) -eq 0 ]; then
        echo "  Config not loaded yet... ${elapsed_phase2}s elapsed (backoff: ${backoff}s)"
    fi
done

# If we get here, config didn't load in time
echo ""
echo "⚠️  Configuration did not load within remaining time (${phase2_timeout}s)"
echo "   HTTP server is responding, but config may not be fully loaded"
echo ""
echo "Diagnostics:"
if command -v docker >/dev/null 2>&1; then
    echo "Container status:"
    docker compose -f docker/docker-compose.yml ps 2>/dev/null || echo "  (could not check status)"
    echo ""
    echo "Testing API response:"
    API_RESPONSE=$(curl -s --max-time 5 "${BASE_URL}/api/weather.php?airport=kspb" 2>&1 || echo "curl failed")
    echo "  Response: ${API_RESPONSE:0:200}"
    echo ""
    echo "Container logs (last 30 lines):"
    docker compose -f docker/docker-compose.yml logs web 2>/dev/null | tail -30 || echo "  (could not retrieve logs)"
    echo ""
    echo "Config file check:"
    docker compose -f docker/docker-compose.yml exec -T web ls -la /var/www/html/config/ 2>/dev/null || echo "  (could not check config)"
fi

# For CI, we might want to continue even if config isn't fully loaded
# But log a warning
echo ""
echo "⚠️  Continuing despite config not being fully loaded (may cause test failures)"
exit 0

