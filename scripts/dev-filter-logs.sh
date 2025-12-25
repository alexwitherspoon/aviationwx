#!/bin/bash
# Log filtering utility for AviationWX logs
# Reads from log files inside the Docker container
# Usage: ./scripts/dev-filter-logs.sh [access|app|error|apache|all]

set -euo pipefail

LOG_TYPE="${1:-all}"
COMPOSE_FILE="${COMPOSE_FILE:-docker/docker-compose.yml}"

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

# Function to get log file from container
get_log_file() {
    local log_file=$1
    docker compose -f "$COMPOSE_FILE" exec -T web cat "/var/log/aviationwx/$log_file" 2>/dev/null || echo ""
}

# Function to tail log file from container
tail_log_file() {
    local log_file=$1
    local lines=${2:-100}
    docker compose -f "$COMPOSE_FILE" exec -T web tail -n "$lines" "/var/log/aviationwx/$log_file" 2>/dev/null || echo ""
}

# Function to filter Apache access logs
filter_apache_access() {
    local logs=$1
    echo "$logs" | grep '\[apache_access\]' || true
}

# Function to filter PHP application logs
filter_app_logs() {
    local logs=$1
    if [ "$JQ_AVAILABLE" = true ]; then
        echo "$logs" | grep -E '^\{' | jq . 2>/dev/null || echo "$logs"
    else
        echo "$logs" | grep -E '^\{'
    fi
}

# Function to filter error logs
filter_errors() {
    local logs=$1
    if [ "$JQ_AVAILABLE" = true ]; then
        # PHP errors (JSON)
        echo "$logs" | grep -E '^\{' | jq 'select(.level == "error" or .level == "warning" or .level == "critical")' 2>/dev/null || true
    else
        echo "$logs" | grep -iE '(error|warning|critical)' || true
    fi
}

# Main filtering logic
case "$LOG_TYPE" in
    access)
        echo -e "${BLUE}=== Apache Access Logs ===${NC}"
        logs=$(tail_log_file "apache-access.log" 100)
        filter_apache_access "$logs"
        ;;
    apache)
        echo -e "${BLUE}=== Apache Logs ===${NC}"
        echo -e "${GREEN}Access Logs (last 50 lines):${NC}"
        tail_log_file "apache-access.log" 50
        echo ""
        echo -e "${GREEN}Error Logs (last 50 lines):${NC}"
        tail_log_file "apache-error.log" 50
        ;;
    app)
        echo -e "${BLUE}=== Application Logs ===${NC}"
        logs=$(tail_log_file "app.log" 100)
        filter_app_logs "$logs"
        ;;
    error)
        echo -e "${RED}=== Error Logs ===${NC}"
        echo -e "${GREEN}PHP Application Errors:${NC}"
        logs=$(get_log_file "app.log")
        filter_errors "$logs"
        echo ""
        echo -e "${GREEN}Apache Error Logs (last 50 lines):${NC}"
        tail_log_file "apache-error.log" 50
        echo ""
        echo -e "${GREEN}PHP Runtime Errors (last 50 lines):${NC}"
        tail_log_file "php-error.log" 50
        ;;
    all)
        echo -e "${BLUE}=== All Logs (last 50 lines each) ===${NC}"
        echo -e "${GREEN}Application Logs:${NC}"
        tail_log_file "app.log" 50
        echo ""
        echo -e "${GREEN}Apache Access Logs:${NC}"
        tail_log_file "apache-access.log" 50
        echo ""
        echo -e "${GREEN}Apache Error Logs:${NC}"
        tail_log_file "apache-error.log" 50
        ;;
    *)
        echo "Usage: $0 [access|app|error|apache|all]"
        echo ""
        echo "Log files are located at /var/log/aviationwx/ inside the container."
        echo ""
        echo "Examples:"
        echo "  $0 access          # Show Apache access logs"
        echo "  $0 app             # Show application logs (JSONL)"
        echo "  $0 error           # Show error logs from all sources"
        echo "  $0 apache          # Show Apache access and error logs"
        echo "  $0 all             # Show all log types"
        echo ""
        echo "Environment variables:"
        echo "  COMPOSE_FILE       # Docker compose file (default: docker/docker-compose.yml)"
        exit 1
        ;;
esac
