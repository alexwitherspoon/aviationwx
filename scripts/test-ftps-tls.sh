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

echo "1. Checking if SSL is enabled (IPv4 config):"
docker compose -f "$COMPOSE_FILE" exec -T web grep "^ssl_enable=" /etc/vsftpd/vsftpd_ipv4.conf 2>/dev/null || echo "  Config not found or SSL not enabled"
echo ""

echo "2. Checking if SSL is enabled (IPv6 config):"
docker compose -f "$COMPOSE_FILE" exec -T web grep "^ssl_enable=" /etc/vsftpd/vsftpd_ipv6.conf 2>/dev/null || echo "  Config not found or SSL not enabled"
echo ""

echo "3. Checking TLS version settings (IPv4 config):"
docker compose -f "$COMPOSE_FILE" exec -T web grep -E "^ssl_tlsv|^# ssl_tlsv" /etc/vsftpd/vsftpd_ipv4.conf 2>/dev/null | head -5 || echo "  Not found"
echo ""

echo "4. Checking SSL/TLS configuration summary (IPv4 config):"
docker compose -f "$COMPOSE_FILE" exec -T web grep -E "ssl_enable|ssl_tlsv|ssl_sslv|ssl_ciphers|rsa_cert" /etc/vsftpd/vsftpd_ipv4.conf 2>/dev/null | grep -v "^#" | head -10 || echo "  No SSL settings found"
echo ""

echo "5. Testing vsftpd configuration syntax (IPv4):"
if docker compose -f "$COMPOSE_FILE" exec -T web test -f /etc/vsftpd/vsftpd_ipv4.conf; then
    if docker compose -f "$COMPOSE_FILE" exec -T web vsftpd -olisten=NO /etc/vsftpd/vsftpd_ipv4.conf >/dev/null 2>&1; then
        echo "  ✓ IPv4 configuration syntax is valid"
    else
        echo "  ✗ IPv4 configuration syntax error"
        docker compose -f "$COMPOSE_FILE" exec -T web vsftpd -olisten=NO /etc/vsftpd/vsftpd_ipv4.conf 2>&1 | head -5
    fi
else
    echo "  ⚠ IPv4 config not found (may not be using dual-stack)"
fi

echo "6. Testing vsftpd configuration syntax (IPv6):"
if docker compose -f "$COMPOSE_FILE" exec -T web test -f /etc/vsftpd/vsftpd_ipv6.conf; then
    if docker compose -f "$COMPOSE_FILE" exec -T web vsftpd -olisten=NO /etc/vsftpd/vsftpd_ipv6.conf >/dev/null 2>&1; then
        echo "  ✓ IPv6 configuration syntax is valid"
    else
        echo "  ✗ IPv6 configuration syntax error"
        docker compose -f "$COMPOSE_FILE" exec -T web vsftpd -olisten=NO /etc/vsftpd/vsftpd_ipv6.conf 2>&1 | head -5
    fi
else
    echo "  ⚠ IPv6 config not found (may not be using dual-stack)"
fi
echo ""

echo "7. Checking if vsftpd is running:"
VSFTPD_PIDS=$(docker compose -f "$COMPOSE_FILE" exec -T web pgrep -x vsftpd 2>/dev/null || true)
if [ -n "$VSFTPD_PIDS" ]; then
    echo "  ✓ vsftpd is running"
    echo "$VSFTPD_PIDS" | while read pid; do
        echo "    PID: $pid"
    done
    # Check if multiple instances (dual-stack)
    PID_COUNT=$(echo "$VSFTPD_PIDS" | wc -l)
    if [ "$PID_COUNT" -gt 1 ]; then
        echo "  ✓ Multiple vsftpd instances detected (dual-stack mode)"
    fi
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

