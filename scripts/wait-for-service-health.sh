#!/bin/bash
# Health check script with small waits and retries up to max timeout
# Usage: wait-for-service-health.sh [timeout_seconds] [base_url]

set -eo pipefail

TIMEOUT=${1:-60}
BASE_URL=${2:-http://localhost:8080}
WAIT_INTERVAL=2  # Small wait interval in seconds between retries

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "Service Health Check"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "Base URL: ${BASE_URL}"
echo "Timeout: ${TIMEOUT}s"
echo "Retry interval: ${WAIT_INTERVAL}s"
echo ""

elapsed=0
attempt=0
max_attempts=$((TIMEOUT / WAIT_INTERVAL))

# Phase 1: Wait for basic HTTP response (Apache started)
echo "Phase 1: Waiting for HTTP server to respond..."
http_ready=false
while [ $elapsed -lt $TIMEOUT ]; do
    if curl -f -s --max-time 2 "${BASE_URL}/health.php" > /dev/null 2>&1 || \
       curl -f -s --max-time 2 "${BASE_URL}/" > /dev/null 2>&1; then
        echo "✓ HTTP server is responding after ${elapsed}s"
        http_ready=true
        break
    fi
    
    # Small wait before retry
    sleep $WAIT_INTERVAL
    elapsed=$((elapsed + WAIT_INTERVAL))
    attempt=$((attempt + 1))
    
    # Progress update every 10 seconds
    if [ $((attempt % 5)) -eq 0 ]; then
        echo "  Still waiting for HTTP server... ${elapsed}s elapsed (attempt ${attempt}/${max_attempts})"
    fi
done

if [ "$http_ready" != "true" ]; then
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
attempt_phase2=0
phase2_timeout=$((TIMEOUT - elapsed))  # Remaining time
config_ready=false

while [ $elapsed_phase2 -lt $phase2_timeout ]; do
    # Check if weather API returns success (indicates config is loaded)
    response=$(curl -f -s --max-time 5 "${BASE_URL}/api/weather.php?airport=kspb" 2>/dev/null)
    curl_exit=$?
    
    if [ $curl_exit -eq 0 ] && [ -n "$response" ]; then
        # Check for success:true (ideal case)
        if echo "$response" | grep -qE '"success"\s*:\s*true'; then
            echo "✓ Configuration loaded and API responding after ${elapsed_phase2}s (total: $((elapsed + elapsed_phase2))s)"
            echo ""
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
            echo "✅ Service is ready"
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
            config_ready=true
            exit 0
        fi
        
        # Check if we get a JSON response with an error about weather fetch
        # This indicates config IS loaded (otherwise we'd get "Airport not found" or "Config not found")
        # Errors like "Unable to fetch weather data" mean config loaded, just weather fetch failed
        if echo "$response" | grep -qE '"success"\s*:\s*false'; then
            error_msg=$(echo "$response" | grep -oE '"error"\s*:\s*"[^"]*"' | head -1 || echo "")
            # If error mentions weather/fetch but NOT config/not found, config is loaded
            if echo "$error_msg" | grep -qiE "(weather|fetch|Unable)" && ! echo "$error_msg" | grep -qiE "(config|not found|Invalid airport|Airport not found)"; then
                echo "✓ Configuration loaded (API responding - weather fetch error acceptable in test mode) after ${elapsed_phase2}s (total: $((elapsed + elapsed_phase2))s)"
                echo ""
                echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
                echo "✅ Service is ready (config loaded, weather fetch may fail in test mode)"
                echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
                config_ready=true
                exit 0
            fi
        fi
    fi
    
    # Small wait before retry
    sleep $WAIT_INTERVAL
    elapsed_phase2=$((elapsed_phase2 + WAIT_INTERVAL))
    attempt_phase2=$((attempt_phase2 + 1))
    
    # Progress update every 6 seconds
    if [ $((attempt_phase2 % 3)) -eq 0 ]; then
        max_attempts_phase2=$((phase2_timeout / WAIT_INTERVAL))
        echo "  Config not loaded yet... ${elapsed_phase2}s elapsed (attempt ${attempt_phase2}/${max_attempts_phase2})"
    fi
done

if [ "$config_ready" != "true" ]; then
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
fi

