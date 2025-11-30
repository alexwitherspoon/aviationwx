#!/bin/bash
# Synthetic VPN Testing Script
# Tests all three VPN protocols (IPsec, WireGuard, OpenVPN) with mock clients

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
DOCKER_DIR="$PROJECT_ROOT/docker"

cd "$DOCKER_DIR"

echo "Multi-Protocol VPN Synthetic Testing"
echo "===================================="
echo ""
echo "This will test all three VPN protocols simultaneously:"
echo "  - IPsec (strongSwan)"
echo "  - WireGuard"
echo "  - OpenVPN"
echo ""
echo "Note: Requires host networking (works best on Linux)"
echo ""

read -p "Continue with synthetic testing? (y/N): " confirm
if [[ "$confirm" != "y" && "$confirm" != "Y" ]]; then
    echo "Cancelled"
    exit 0
fi

echo ""
echo "Starting VPN servers and manager..."
docker-compose up -d vpn-server vpn-wireguard vpn-openvpn vpn-manager

echo ""
echo "Waiting for services to initialize..."
sleep 10

echo ""
echo "Checking service status..."
echo "=========================="
docker-compose ps

echo ""
echo "Checking VPN Manager logs..."
docker-compose logs --tail 20 vpn-manager

echo ""
echo "To start mock clients for testing:"
echo "  docker-compose -f docker-compose.yml -f docker-compose.test-multi-protocol.yml up -d"
echo ""
echo "To view logs:"
echo "  docker-compose logs -f vpn-manager"
echo "  docker-compose -f docker-compose.yml -f docker-compose.test-multi-protocol.yml logs -f"
echo ""
echo "To stop all services:"
echo "  docker-compose -f docker-compose.yml -f docker-compose.test-multi-protocol.yml down"





