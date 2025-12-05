#!/bin/bash
# Generate WireGuard keys for an airport
# Usage: ./generate-wireguard-keys.sh <airport_id>

set -e

AIRPORT_ID="${1}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

if [ -z "$AIRPORT_ID" ]; then
    echo "Usage: $0 <airport_id>"
    echo "Example: $0 kspb"
    exit 1
fi

echo "Generating WireGuard keys for $AIRPORT_ID..."
echo ""

# Check if Docker is available
if ! command -v docker &> /dev/null; then
    echo "Error: Docker is not available"
    echo "Please install Docker or run this in an environment with wireguard-tools"
    exit 1
fi

# Use a temporary container to generate keys
# We'll use a WireGuard image or the vpn-manager image
TEMP_CONTAINER="wg-keygen-$$"

# Try to use wireguard-tools image, or fall back to alpine with wg
if docker run --rm --name "$TEMP_CONTAINER" \
    -v "$PROJECT_ROOT/config:/config:ro" \
    lscr.io/linuxserver/wireguard:latest \
    sh -c "wg genkey | tee /tmp/server_priv && wg pubkey < /tmp/server_priv > /tmp/server_pub && wg genkey | tee /tmp/client_priv && wg pubkey < /tmp/client_priv > /tmp/client_pub && echo 'SERVER_PRIV:' && cat /tmp/server_priv && echo 'SERVER_PUB:' && cat /tmp/server_pub && echo 'CLIENT_PRIV:' && cat /tmp/client_priv && echo 'CLIENT_PUB:' && cat /tmp/client_pub" 2>/dev/null; then
    
    echo ""
    echo "Keys generated successfully!"
    echo ""
    echo "Add these to airports.json under vpn.wireguard:"
    echo ""
    
elif docker run --rm --name "$TEMP_CONTAINER" \
    alpine:latest sh -c "apk add --no-cache wireguard-tools > /dev/null 2>&1 && wg genkey | tee /tmp/server_priv && wg pubkey < /tmp/server_priv > /tmp/server_pub && wg genkey | tee /tmp/client_priv && wg pubkey < /tmp/client_priv > /tmp/client_pub && echo 'SERVER_PRIV:' && cat /tmp/server_priv && echo 'SERVER_PUB:' && cat /tmp/server_pub && echo 'CLIENT_PRIV:' && cat /tmp/client_priv && echo 'CLIENT_PUB:' && cat /tmp/client_pub" 2>/dev/null; then
    
    echo ""
    echo "Keys generated successfully!"
    echo ""
    echo "Add these to airports.json under vpn.wireguard:"
    echo ""
    
else
    echo "Error: Could not generate keys using Docker"
    echo ""
    echo "Alternative: Keys will be auto-generated when VPN containers start."
    echo "Check docker logs vpn-manager after starting containers to see generated keys."
    exit 1
fi





