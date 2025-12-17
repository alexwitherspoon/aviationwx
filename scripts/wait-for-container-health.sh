#!/bin/bash
# Efficient health check script that polls instead of using fixed sleeps
# Usage: wait-for-container-health.sh [options]
#
# Options:
#   --container <name>     Container name to check (default: web)
#   --timeout <seconds>    Maximum time to wait (default: 60)
#   --interval <seconds>   Polling interval (default: 2)
#   --check-type <type>    Type of check: status, health, http, fail2ban (default: health)
#   --compose-file <file>  Docker compose file path (default: docker/docker-compose.prod.yml)
#   --url <url>            URL to check for http type (default: http://localhost/)
#
# Exit codes:
#   0: Service is ready
#   1: Timeout exceeded
#   2: Invalid arguments

set -euo pipefail

# Default values
CONTAINER_NAME="web"
TIMEOUT=60
INTERVAL=2
CHECK_TYPE="health"
COMPOSE_FILE="docker/docker-compose.prod.yml"
CHECK_URL="http://localhost:8080/"

# Parse arguments
while [[ $# -gt 0 ]]; do
  case $1 in
    --container)
      CONTAINER_NAME="$2"
      shift 2
      ;;
    --timeout)
      TIMEOUT="$2"
      shift 2
      ;;
    --interval)
      INTERVAL="$2"
      shift 2
      ;;
    --check-type)
      CHECK_TYPE="$2"
      shift 2
      ;;
    --compose-file)
      COMPOSE_FILE="$2"
      shift 2
      ;;
    --url)
      CHECK_URL="$2"
      shift 2
      ;;
    --help)
      echo "Usage: $0 [options]"
      echo "Options:"
      echo "  --container <name>     Container name (default: web)"
      echo "  --timeout <seconds>    Max wait time (default: 60)"
      echo "  --interval <seconds>   Polling interval (default: 2)"
      echo "  --check-type <type>    Check type: status, health, http, fail2ban (default: health)"
      echo "  --compose-file <file>  Docker compose file (default: docker/docker-compose.prod.yml)"
      echo "  --url <url>            URL for http check (default: http://localhost:8080/)"
      exit 0
      ;;
    *)
      echo "Unknown option: $1"
      echo "Use --help for usage information"
      exit 2
      ;;
  esac
done

# Validate check type
if [[ ! "$CHECK_TYPE" =~ ^(status|health|http|fail2ban)$ ]]; then
  echo "❌ ERROR: Invalid check type: $CHECK_TYPE"
  echo "Valid types: status, health, http, fail2ban"
  exit 2
fi

# Function to check container status
check_container_status() {
  if command -v jq >/dev/null 2>&1; then
    docker compose -f "$COMPOSE_FILE" ps "$CONTAINER_NAME" --format json 2>/dev/null | \
      jq -r '.[0].State // "unknown"' 2>/dev/null || echo "unknown"
  else
    # Fallback: use grep to check if container is running
    if docker compose -f "$COMPOSE_FILE" ps "$CONTAINER_NAME" 2>/dev/null | grep -q "Up"; then
      echo "running"
    else
      echo "unknown"
    fi
  fi
}

# Function to check container health
check_container_health() {
  if command -v jq >/dev/null 2>&1; then
    docker compose -f "$COMPOSE_FILE" ps "$CONTAINER_NAME" --format json 2>/dev/null | \
      jq -r '.[0].Health // "unknown"' 2>/dev/null || echo "unknown"
  else
    # Fallback: use grep to extract health status
    HEALTH=$(docker compose -f "$COMPOSE_FILE" ps "$CONTAINER_NAME" --format json 2>/dev/null | \
      grep -o '"Health":"[^"]*"' | cut -d'"' -f4 2>/dev/null || echo "unknown")
    echo "$HEALTH"
  fi
}

# Function to check HTTP response
check_http_response() {
  docker compose -f "$COMPOSE_FILE" exec -T "$CONTAINER_NAME" \
    curl -f -s --max-time 3 "$CHECK_URL" > /dev/null 2>&1
}

# Function to check fail2ban
check_fail2ban() {
  # Check if process is running
  if ! docker compose -f "$COMPOSE_FILE" exec -T "$CONTAINER_NAME" \
    pgrep -x fail2ban-server > /dev/null 2>&1; then
    return 1
  fi
  
  # Check if client is responsive
  if ! docker compose -f "$COMPOSE_FILE" exec -T "$CONTAINER_NAME" \
    fail2ban-client ping > /dev/null 2>&1; then
    return 1
  fi
  
  # Check if jails are active
  STATUS_OUTPUT=$(docker compose -f "$COMPOSE_FILE" exec -T "$CONTAINER_NAME" \
    fail2ban-client status 2>&1 || echo "")
  
  if echo "$STATUS_OUTPUT" | grep -q "Jail list"; then
    JAIL_LIST=$(echo "$STATUS_OUTPUT" | grep -A 10 "Jail list" | tail -n +2 | tr -d ' \t' || echo "")
    if [ -n "$JAIL_LIST" ]; then
      return 0
    fi
  fi
  
  return 1
}

# Main polling loop
elapsed=0
attempt=0
max_attempts=$((TIMEOUT / INTERVAL))

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "Container Health Check"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "Container: $CONTAINER_NAME"
echo "Check type: $CHECK_TYPE"
echo "Timeout: ${TIMEOUT}s"
echo "Interval: ${INTERVAL}s"
echo ""

while [ $elapsed -lt $TIMEOUT ]; do
  case "$CHECK_TYPE" in
    status)
      STATUS=$(check_container_status)
      if [ "$STATUS" = "running" ]; then
        echo "✓ Container is running after ${elapsed}s"
        exit 0
      fi
      ;;
    health)
      HEALTH=$(check_container_health)
      if [ "$HEALTH" = "healthy" ]; then
        echo "✓ Container is healthy after ${elapsed}s"
        exit 0
      elif [ "$HEALTH" = "unhealthy" ]; then
        echo "❌ Container is unhealthy"
        exit 1
      fi
      ;;
    http)
      if check_http_response; then
        echo "✓ HTTP service is responding after ${elapsed}s"
        exit 0
      fi
      ;;
    fail2ban)
      if check_fail2ban; then
        echo "✓ fail2ban is ready after ${elapsed}s"
        exit 0
      fi
      ;;
  esac
  
  # Progress update every 6 seconds (or every 3 attempts if interval is 2s)
  if [ $((attempt % 3)) -eq 0 ] && [ $attempt -gt 0 ]; then
    case "$CHECK_TYPE" in
      status)
        CURRENT_STATUS=$(check_container_status)
        echo "  Container status: $CURRENT_STATUS (${elapsed}s elapsed, attempt ${attempt}/${max_attempts})"
        ;;
      health)
        CURRENT_HEALTH=$(check_container_health)
        echo "  Container health: $CURRENT_HEALTH (${elapsed}s elapsed, attempt ${attempt}/${max_attempts})"
        ;;
      http)
        echo "  HTTP service not responding yet (${elapsed}s elapsed, attempt ${attempt}/${max_attempts})"
        ;;
      fail2ban)
        echo "  fail2ban not ready yet (${elapsed}s elapsed, attempt ${attempt}/${max_attempts})"
        ;;
    esac
  fi
  
  sleep $INTERVAL
  elapsed=$((elapsed + INTERVAL))
  attempt=$((attempt + 1))
done

# Timeout reached
echo ""
echo "❌ Timeout: Service did not become ready within ${TIMEOUT}s"
echo ""
echo "Diagnostics:"
echo "Container status:"
docker compose -f "$COMPOSE_FILE" ps "$CONTAINER_NAME" 2>/dev/null || echo "  (could not check status)"
echo ""
echo "Container logs (last 30 lines):"
docker compose -f "$COMPOSE_FILE" logs "$CONTAINER_NAME" 2>/dev/null | tail -30 || echo "  (could not retrieve logs)"

exit 1

