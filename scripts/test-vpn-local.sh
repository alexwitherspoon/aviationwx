#!/bin/bash
# Local VPN Testing Script
# Provides multiple testing approaches for VPN functionality

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
DOCKER_DIR="$PROJECT_ROOT/docker"

cd "$DOCKER_DIR"

echo "VPN Local Testing Options"
echo "========================"
echo ""
echo "1. Config Validation (Recommended for macOS)"
echo "   - Validates VPN configuration syntax"
echo "   - Checks for required fields"
echo "   - No network connections required"
echo ""
echo "2. Bridge Network Testing (macOS compatible)"
echo "   - Uses Docker bridge networking"
echo "   - Tests actual VPN connections"
echo "   - May have some limitations"
echo ""
echo "3. Full Integration Test (Linux only)"
echo "   - Uses host networking"
echo "   - Full VPN functionality"
echo "   - Requires Linux or production environment"
echo ""

read -p "Select test option (1-3): " choice

case $choice in
    1)
        echo ""
        echo "Running config validation..."
        echo ""
        if command -v docker-compose >/dev/null 2>&1 && docker-compose ps vpn-manager >/dev/null 2>&1; then
            docker-compose run --rm -e CONFIG_PATH=/var/www/html/config/airports.json \
                -v "$PROJECT_ROOT/config:/var/www/html/config:ro" \
                vpn-manager python3 /app/test-vpn-config.py
        else
            python3 "$PROJECT_ROOT/scripts/test-vpn-config.py"
        fi
        ;;
    2)
        echo ""
        echo "Starting bridge network test..."
        echo ""
        docker-compose -f docker-compose.yml -f docker-compose.test-bridge.yml up -d
        echo ""
        echo "Services started. Check logs with:"
        echo "  docker logs aviationwx-vpn-test-server"
        echo "  docker logs aviationwx-vpn-test-manager"
        echo "  docker logs aviationwx-vpn-test-client"
        echo ""
        echo "To stop: docker-compose -f docker-compose.yml -f docker-compose.test-bridge.yml down"
        ;;
    3)
        echo ""
        echo "Starting full integration test (host networking)..."
        echo ""
        echo "Note: This uses host networking which works best on Linux."
        echo "On macOS Docker Desktop, host networking has limitations."
        echo ""
        read -p "Continue anyway? (y/N): " confirm
        if [[ "$confirm" != "y" && "$confirm" != "Y" ]]; then
            echo "Cancelled. Use option 2 (bridge network) for macOS."
            exit 0
        fi
        
        cd "$DOCKER_DIR"
        docker-compose up -d vpn-server vpn-manager
        docker-compose -f docker-compose.yml -f docker-compose.test.yml up -d vpn-client-mock
        echo ""
        echo "Services started. Check status with:"
        echo "  docker exec aviationwx-vpn ipsec status"
        echo "  docker exec aviationwx-vpn-client-mock ipsec status"
        echo ""
        echo "Check logs with:"
        echo "  docker logs aviationwx-vpn"
        echo "  docker logs aviationwx-vpn-manager"
        echo "  docker logs aviationwx-vpn-client-mock"
        ;;
    *)
        echo "Invalid option"
        exit 1
        ;;
esac

