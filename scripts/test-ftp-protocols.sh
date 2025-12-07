#!/bin/bash
# Test FTP, SFTP, and FTPS protocols for push webcams
# Usage: ./test-ftp-protocols.sh [local|production] [airport_id] [cam_index]
#   local: Test against local Docker container (default)
#   production: Test against production server
#   airport_id: Specific airport to test (optional)
#   cam_index: Specific camera index to test (optional)

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
CONFIG_FILE="$PROJECT_ROOT/config/airports.json"

# Default to local testing
MODE="${1:-local}"
AIRPORT_ID="${2:-}"
CAM_INDEX="${3:-}"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Test results
TOTAL_TESTS=0
PASSED_TESTS=0
FAILED_TESTS=0

log_info() {
    echo -e "${BLUE}ℹ️  $1${NC}"
}

log_success() {
    echo -e "${GREEN}✓ $1${NC}"
    PASSED_TESTS=$((PASSED_TESTS + 1))
    TOTAL_TESTS=$((TOTAL_TESTS + 1))
}

log_error() {
    echo -e "${RED}❌ $1${NC}"
    FAILED_TESTS=$((FAILED_TESTS + 1))
    TOTAL_TESTS=$((TOTAL_TESTS + 1))
}

log_warning() {
    echo -e "${YELLOW}⚠️  $1${NC}"
}

# Check if required tools are available
check_dependencies() {
    local missing=()
    
    if ! command -v curl &> /dev/null; then
        missing+=("curl")
    fi
    
    if ! command -v sftp &> /dev/null && ! command -v ssh &> /dev/null; then
        missing+=("sftp/ssh")
    fi
    
    if ! command -v jq &> /dev/null; then
        missing+=("jq")
    fi
    
    if [ ${#missing[@]} -gt 0 ]; then
        log_error "Missing required tools: ${missing[*]}"
        log_info "Install missing tools and try again"
        exit 1
    fi
}

# Determine host and ports based on mode
get_connection_info() {
    if [ "$MODE" = "production" ]; then
        HOST="upload.aviationwx.org"
        SFTP_PORT="2222"
        FTP_PORT="2121"
    else
        HOST="localhost"
        # Local docker-compose.yml maps ports to avoid conflicts
        SFTP_PORT="12222"
        FTP_PORT="12121"
        
        # Check if local Docker is running
        if ! docker compose -f "$PROJECT_ROOT/docker/docker-compose.yml" ps web 2>/dev/null | grep -q "Up"; then
            log_warning "Local Docker container is not running"
            log_info "Start it with: docker compose -f docker/docker-compose.yml up -d"
            log_info "Or test against production with: $0 production"
        fi
    fi
}

# Check if port is accessible
check_port() {
    local host="$1"
    local port="$2"
    local protocol="$3"
    
    if command -v nc &> /dev/null; then
        if nc -z -w 2 "$host" "$port" 2>/dev/null; then
            return 0
        fi
    elif command -v timeout &> /dev/null && command -v bash &> /dev/null; then
        if timeout 2 bash -c "echo > /dev/tcp/$host/$port" 2>/dev/null; then
            return 0
        fi
    fi
    return 1
}

# Test SFTP connection
test_sftp() {
    local username="$1"
    local password="$2"
    local airport_id="$3"
    local cam_index="$4"
    
    log_info "Testing SFTP connection (port $SFTP_PORT)..."
    
    # Create a test file
    local test_file="test_sftp_$(date +%s).txt"
    local test_content="SFTP test file created at $(date)"
    echo "$test_content" > "/tmp/$test_file"
    
    # Use sshpass if available, otherwise use expect
    if command -v sshpass &> /dev/null; then
        # Test connection and upload
        local sftp_commands="put /tmp/$test_file
ls -la
get $test_file /tmp/${test_file}.downloaded
rm $test_file
quit"
        
        if echo "$sftp_commands" | sshpass -p "$password" sftp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -P "$SFTP_PORT" "$username@$HOST" > /tmp/sftp_output.log 2>&1; then
            # Verify downloaded file
            if [ -f "/tmp/${test_file}.downloaded" ]; then
                if diff -q "/tmp/$test_file" "/tmp/${test_file}.downloaded" > /dev/null 2>&1; then
                    log_success "SFTP test passed (upload, download, delete)"
                    rm -f "/tmp/$test_file" "/tmp/${test_file}.downloaded" "/tmp/sftp_output.log"
                    return 0
                else
                    log_error "SFTP test failed: downloaded file content mismatch"
                    rm -f "/tmp/$test_file" "/tmp/${test_file}.downloaded" "/tmp/sftp_output.log"
                    return 1
                fi
            else
                log_error "SFTP test failed: file not downloaded"
                log_info "SFTP output: $(cat /tmp/sftp_output.log 2>/dev/null | head -10)"
                rm -f "/tmp/$test_file" "/tmp/sftp_output.log"
                return 1
            fi
        else
            log_error "SFTP test failed: connection or command error"
            log_info "SFTP output: $(cat /tmp/sftp_output.log 2>/dev/null | head -10)"
            rm -f "/tmp/$test_file" "/tmp/sftp_output.log"
            return 1
        fi
    else
        log_warning "sshpass not available - skipping SFTP test (install sshpass for SFTP testing)"
        log_info "To install: brew install sshpass (macOS) or apt-get install sshpass (Linux)"
        return 2
    fi
}

# Test FTP connection (plain, unencrypted)
test_ftp() {
    local username="$1"
    local password="$2"
    local airport_id="$3"
    local cam_index="$4"
    
    log_info "Testing FTP connection (port $FTP_PORT, plain/unencrypted)..."
    
    # Create a test file
    local test_file="test_ftp_$(date +%s).txt"
    local test_content="FTP test file created at $(date)"
    echo "$test_content" > "/tmp/$test_file"
    
    # Use curl for FTP testing
    local ftp_url="ftp://${username}:${password}@${HOST}:${FTP_PORT}/"
    
    # Test upload
    if curl -s --ftp-create-dirs --upload-file "/tmp/$test_file" "$ftp_url$test_file" > /dev/null 2>&1; then
        # Test download
        if curl -s --fail "$ftp_url$test_file" -o "/tmp/${test_file}.downloaded" > /dev/null 2>&1; then
            # Verify content
            if diff -q "/tmp/$test_file" "/tmp/${test_file}.downloaded" > /dev/null 2>&1; then
                # Test delete
                if curl -s --fail -X "DELE $test_file" "$ftp_url" > /dev/null 2>&1; then
                    log_success "FTP test passed (upload, download, delete)"
                    rm -f "/tmp/$test_file" "/tmp/${test_file}.downloaded"
                    return 0
                else
                    log_warning "FTP test: upload/download worked but delete failed (may be expected)"
                    log_success "FTP test passed (upload, download)"
                    rm -f "/tmp/$test_file" "/tmp/${test_file}.downloaded"
                    return 0
                fi
            else
                log_error "FTP test failed: downloaded file content mismatch"
                rm -f "/tmp/$test_file" "/tmp/${test_file}.downloaded"
                return 1
            fi
        else
            log_error "FTP test failed: file not downloaded"
            rm -f "/tmp/$test_file"
            return 1
        fi
    else
        log_error "FTP test failed: connection or upload error"
        rm -f "/tmp/$test_file"
        return 1
    fi
}

# Test FTPS connection (FTP over SSL/TLS)
test_ftps() {
    local username="$1"
    local password="$2"
    local airport_id="$3"
    local cam_index="$4"
    
    log_info "Testing FTPS connection (port $FTP_PORT, SSL/TLS encrypted)..."
    
    # Create a test file
    local test_file="test_ftps_$(date +%s).txt"
    local test_content="FTPS test file created at $(date)"
    echo "$test_content" > "/tmp/$test_file"
    
    # Use curl for FTPS testing with SSL
    local ftps_url="ftps://${username}:${password}@${HOST}:${FTP_PORT}/"
    
    # Test upload with SSL (verbose for debugging)
    local curl_output=$(curl -v --ftp-ssl --insecure --ftp-create-dirs --upload-file "/tmp/$test_file" "$ftps_url$test_file" 2>&1)
    local curl_exit=$?
    
    if [ $curl_exit -eq 0 ]; then
        # Test download with SSL
        if curl -s --ftp-ssl --insecure --fail "$ftps_url$test_file" -o "/tmp/${test_file}.downloaded" > /dev/null 2>&1; then
            # Verify content
            if diff -q "/tmp/$test_file" "/tmp/${test_file}.downloaded" > /dev/null 2>&1; then
                # Test delete with SSL
                if curl -s --ftp-ssl --insecure --fail -X "DELE $test_file" "$ftps_url" > /dev/null 2>&1; then
                    log_success "FTPS test passed (upload, download, delete)"
                    rm -f "/tmp/$test_file" "/tmp/${test_file}.downloaded"
                    return 0
                else
                    log_warning "FTPS test: upload/download worked but delete failed (may be expected)"
                    log_success "FTPS test passed (upload, download)"
                    rm -f "/tmp/$test_file" "/tmp/${test_file}.downloaded"
                    return 0
                fi
            else
                log_error "FTPS test failed: downloaded file content mismatch"
                rm -f "/tmp/$test_file" "/tmp/${test_file}.downloaded"
                return 1
            fi
        else
            log_error "FTPS test failed: file not downloaded"
            rm -f "/tmp/$test_file"
            return 1
        fi
    else
        log_error "FTPS test failed: connection or upload error"
        log_info "Note: FTPS requires SSL certificates to be configured on the server"
        log_info "Curl exit code: $curl_exit"
        log_info "Error details: $(echo "$curl_output" | grep -i "error\|fail\|refused" | head -3 || echo "No specific error found")"
        rm -f "/tmp/$test_file"
        return 1
    fi
}

# Test a single push camera
test_camera() {
    local airport_id="$1"
    local cam_index="$2"
    
    # Extract camera config using jq
    local has_push_config=$(jq -r ".airports[\"$airport_id\"].webcams[$cam_index] | has(\"push_config\")" "$CONFIG_FILE")
    
    if [ "$has_push_config" != "true" ]; then
        log_warning "Camera $airport_id[$cam_index] has no push_config, skipping"
        return
    fi
    
    local protocol=$(jq -r ".airports[\"$airport_id\"].webcams[$cam_index].push_config.protocol // \"sftp\"" "$CONFIG_FILE")
    local username=$(jq -r ".airports[\"$airport_id\"].webcams[$cam_index].push_config.username // empty" "$CONFIG_FILE")
    local password=$(jq -r ".airports[\"$airport_id\"].webcams[$cam_index].push_config.password // empty" "$CONFIG_FILE")
    
    if [ -z "$username" ] || [ -z "$password" ] || [ "$username" = "null" ] || [ "$password" = "null" ]; then
        log_warning "Camera $airport_id[$cam_index] missing username or password, skipping"
        return
    fi
    
    echo ""
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    log_info "Testing camera: $airport_id[$cam_index]"
    log_info "Protocol: $protocol"
    log_info "Username: $username"
    log_info "Host: $HOST"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    
    # Skip port check - try connection directly (port check may fail due to firewall)
    # The actual connection test will reveal if the service is accessible
    
    # Test based on configured protocol
    case "$protocol" in
        sftp)
            test_sftp "$username" "$password" "$airport_id" "$cam_index"
            ;;
        ftp)
            test_ftp "$username" "$password" "$airport_id" "$cam_index"
            ;;
        ftps)
            test_ftps "$username" "$password" "$airport_id" "$cam_index"
            ;;
        *)
            log_error "Unknown protocol: $protocol"
            ;;
    esac
    
    # Also test other protocols if configured
    if [ "$protocol" != "sftp" ]; then
        log_info "Also testing SFTP (even though configured as $protocol)..."
        test_sftp "$username" "$password" "$airport_id" "$cam_index" || true
    fi
    
    if [ "$protocol" != "ftp" ]; then
        log_info "Also testing FTP (even though configured as $protocol)..."
        test_ftp "$username" "$password" "$airport_id" "$cam_index" || true
    fi
    
    if [ "$protocol" != "ftps" ]; then
        log_info "Also testing FTPS (even though configured as $protocol)..."
        test_ftps "$username" "$password" "$airport_id" "$cam_index" || true
    fi
}

# Main function
main() {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo "FTP/SFTP/FTPS Protocol Test"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo ""
    
    check_dependencies
    get_connection_info
    
    log_info "Mode: $MODE"
    log_info "Host: $HOST"
    log_info "SFTP Port: $SFTP_PORT"
    log_info "FTP/FTPS Port: $FTP_PORT"
    echo ""
    
    if [ "$MODE" = "local" ]; then
        log_info "Note: For local testing, ensure users are created by running:"
        log_info "  docker compose -f docker/docker-compose.yml exec web php /var/www/html/scripts/sync-push-config.php"
        echo ""
    fi
    
    if [ ! -f "$CONFIG_FILE" ]; then
        log_error "Configuration file not found: $CONFIG_FILE"
        exit 1
    fi
    
    # Check if jq can parse the config
    if ! jq empty "$CONFIG_FILE" 2>/dev/null; then
        log_error "Invalid JSON in configuration file: $CONFIG_FILE"
        exit 1
    fi
    
    # Find push cameras
    if [ -n "$AIRPORT_ID" ] && [ -n "$CAM_INDEX" ]; then
        # Test specific camera
        local has_cam=$(jq -r ".airports[\"$AIRPORT_ID\"].webcams[$CAM_INDEX] != null" "$CONFIG_FILE")
        if [ "$has_cam" != "true" ]; then
            log_error "Camera not found: $AIRPORT_ID[$CAM_INDEX]"
            exit 1
        fi
        test_camera "$AIRPORT_ID" "$CAM_INDEX"
    elif [ -n "$AIRPORT_ID" ]; then
        # Test all cameras for specific airport
        local cam_count=$(jq -r ".airports[\"$AIRPORT_ID\"].webcams | length" "$CONFIG_FILE")
        if [ "$cam_count" = "0" ] || [ "$cam_count" = "null" ]; then
            log_error "Airport not found or has no cameras: $AIRPORT_ID"
            exit 1
        fi
        for ((i=0; i<cam_count; i++)); do
            test_camera "$AIRPORT_ID" "$i"
        done
    else
        # Test all push cameras
        jq -r '.airports | keys[]' "$CONFIG_FILE" | while read -r airport_id; do
            local cam_count=$(jq -r ".airports[\"$airport_id\"].webcams | length" "$CONFIG_FILE")
            for ((i=0; i<cam_count; i++)); do
                local has_push=$(jq -r ".airports[\"$airport_id\"].webcams[$i] | has(\"push_config\")" "$CONFIG_FILE")
                if [ "$has_push" = "true" ]; then
                    test_camera "$airport_id" "$i"
                fi
            done
        done
    fi
    
    echo ""
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo "Test Summary"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo "Total tests: $TOTAL_TESTS"
    echo -e "${GREEN}Passed: $PASSED_TESTS${NC}"
    echo -e "${RED}Failed: $FAILED_TESTS${NC}"
    
    if [ $FAILED_TESTS -eq 0 ]; then
        echo ""
        log_success "All tests passed!"
        exit 0
    else
        echo ""
        log_error "Some tests failed"
        exit 1
    fi
}

main "$@"

