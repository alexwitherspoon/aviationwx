#!/bin/bash
# Log filtering utility for AviationWX Docker logs
# Usage: ./scripts/dev-filter-logs.sh [access|app|error|nginx|apache|all] [container]

set -euo pipefail

LOG_TYPE="${1:-all}"
CONTAINER="${2:-}"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Check if jq is available
if ! command -v jq &> /dev/null; then
    echo -e "${YELLOW}Warning: jq is not installed. JSON parsing will be limited.${NC}"
    echo "Install with: brew install jq (macOS) or apt-get install jq (Linux)"
    JQ_AVAILABLE=false
else
    JQ_AVAILABLE=true
fi

# Function to get logs from container
get_logs() {
    local container=$1
    if [ -n "$container" ]; then
        docker compose logs "$container" 2>/dev/null || docker logs "$container" 2>/dev/null
    else
        docker compose logs 2>/dev/null || docker-compose logs 2>/dev/null
    fi
}

# Function to filter Nginx access logs
filter_nginx_access() {
    local logs=$1
    if [ "$JQ_AVAILABLE" = true ]; then
        echo "$logs" | grep -o '{"source":"nginx_access"[^}]*}' | jq .
    else
        echo "$logs" | grep -o '{"source":"nginx_access"[^}]*}'
    fi
}

# Function to filter Apache access logs
filter_apache_access() {
    local logs=$1
    echo "$logs" | grep '\[apache_access\]'
}

# Function to filter PHP application logs
filter_app_logs() {
    local logs=$1
    if [ "$JQ_AVAILABLE" = true ]; then
        echo "$logs" | grep -E '^\{"ts":' | jq .
    else
        echo "$logs" | grep -E '^\{"ts":'
    fi
}

# Function to filter error logs
filter_errors() {
    local logs=$1
    if [ "$JQ_AVAILABLE" = true ]; then
        # PHP errors (JSON)
        echo "$logs" | grep -E '^\{"ts":' | jq 'select(.level == "error" or .level == "warning" or .level == "critical")'
        # Nginx/Apache errors (plain text)
        echo "$logs" | grep -iE '(error|warning|critical)' | grep -v '{"source":"nginx_access"'
    else
        echo "$logs" | grep -iE '(error|warning|critical)'
    fi
}

# Main filtering logic
case "$LOG_TYPE" in
    access)
        echo -e "${BLUE}=== Access Logs ===${NC}"
        logs=$(get_logs "$CONTAINER")
        echo -e "${GREEN}Nginx Access Logs:${NC}"
        filter_nginx_access "$logs"
        echo ""
        echo -e "${GREEN}Apache Access Logs:${NC}"
        filter_apache_access "$logs"
        ;;
    nginx)
        echo -e "${BLUE}=== Nginx Logs ===${NC}"
        logs=$(get_logs "${CONTAINER:-nginx}")
        echo -e "${GREEN}Nginx Access Logs (JSON):${NC}"
        filter_nginx_access "$logs"
        ;;
    apache)
        echo -e "${BLUE}=== Apache Logs ===${NC}"
        logs=$(get_logs "${CONTAINER:-web}")
        echo -e "${GREEN}Apache Access Logs:${NC}"
        filter_apache_access "$logs"
        ;;
    app)
        echo -e "${BLUE}=== Application Logs ===${NC}"
        logs=$(get_logs "${CONTAINER:-web}")
        filter_app_logs "$logs"
        ;;
    error)
        echo -e "${RED}=== Error Logs ===${NC}"
        logs=$(get_logs "$CONTAINER")
        filter_errors "$logs"
        ;;
    all)
        echo -e "${BLUE}=== All Logs ===${NC}"
        get_logs "$CONTAINER"
        ;;
    *)
        echo "Usage: $0 [access|app|error|nginx|apache|all] [container]"
        echo ""
        echo "Examples:"
        echo "  $0 access          # Show all access logs"
        echo "  $0 app             # Show application logs"
        echo "  $0 error           # Show error logs"
        echo "  $0 nginx           # Show Nginx logs"
        echo "  $0 apache          # Show Apache logs"
        echo "  $0 all web         # Show all logs from web container"
        exit 1
        ;;
esac

