#!/bin/bash
# Test FTPS TLS configuration on production
# Usage: ./test-ftps-tls.sh [production]

set -euo pipefail

MODE="${1:-production}"
COMPOSE_FILE="docker/docker-compose.prod.yml"

if [ "$MODE" != "production" ]; then
    COMPOSE_FILE="docker/docker-compose.yml"
fi

echo "=== Testing FTPS TLS Configuration ==="
echo "Mode: $MODE"
echo ""

echo "1. Checking if SSL is enabled:"
docker compose -f "$COMPOSE_FILE" exec -T web grep "^ssl_enable=" /etc/vsftpd.conf || echo "  Not found"
echo ""

echo "2. Checking TLS version settings:"
docker compose -f "$COMPOSE_FILE" exec -T web grep -E "^ssl_tlsv|^# ssl_tlsv" /etc/vsftpd.conf || echo "  Not found"
echo ""

echo "3. Checking SSL/TLS configuration summary:"
docker compose -f "$COMPOSE_FILE" exec -T web grep -E "ssl_enable|ssl_tlsv|ssl_sslv|ssl_ciphers|rsa_cert" /etc/vsftpd.conf | grep -v "^#" | head -10
echo ""

echo "4. Testing vsftpd configuration syntax:"
if docker compose -f "$COMPOSE_FILE" exec -T web vsftpd -olisten=NO /etc/vsftpd.conf >/dev/null 2>&1; then
    echo "  ✓ Configuration syntax is valid"
else
    echo "  ✗ Configuration syntax error"
    docker compose -f "$COMPOSE_FILE" exec -T web vsftpd -olisten=NO /etc/vsftpd.conf 2>&1 | head -5
fi
echo ""

echo "5. Checking if vsftpd is running:"
if docker compose -f "$COMPOSE_FILE" exec -T web pgrep -x vsftpd >/dev/null 2>&1; then
    echo "  ✓ vsftpd is running"
    VSFTPD_PID=$(docker compose -f "$COMPOSE_FILE" exec -T web pgrep -x vsftpd | head -1)
    echo "  PID: $VSFTPD_PID"
else
    echo "  ✗ vsftpd is not running"
fi
echo ""

echo "6. Testing FTPS connection (if credentials available):"
if [ -f "config/airports.json" ]; then
    # Try to get first airport with FTP credentials
    USERNAME=$(grep -A 20 '"ftp"' config/airports.json | grep -m 1 '"username"' | cut -d'"' -f4 | head -1)
    PASSWORD=$(grep -A 20 '"ftp"' config/airports.json | grep -m 1 '"password"' | cut -d'"' -f4 | head -1)
    
    if [ -n "$USERNAME" ] && [ -n "$PASSWORD" ]; then
        HOST="upload.aviationwx.org"
        if [ "$MODE" != "production" ]; then
            HOST="localhost"
        fi
        
        echo "  Testing FTPS with user: $USERNAME"
        if curl -v --ftp-ssl --insecure --user "$USERNAME:$PASSWORD" "ftp://$HOST:2121/" 2>&1 | grep -q "230\|150\|226"; then
            echo "  ✓ FTPS connection successful"
        else
            echo "  ✗ FTPS connection failed"
            echo "  (This may be expected if certificates aren't configured locally)"
        fi
    else
        echo "  No FTP credentials found in config/airports.json"
    fi
else
    echo "  config/airports.json not found"
fi
echo ""

echo "=== Test Complete ==="

