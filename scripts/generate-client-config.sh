#!/bin/bash
# Generate and display client VPN configuration
# Usage: ./generate-client-config.sh <airport_id>

set -e

AIRPORT_ID="${1}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
CLIENT_CONFIG_DIR="${PROJECT_ROOT}/config/vpn-clients"

if [ -z "$AIRPORT_ID" ]; then
    echo "Usage: $0 <airport_id>"
    echo "Example: $0 kspb"
    exit 1
fi

# Check if running in Docker or locally
if [ -f "/app/vpn-manager.py" ]; then
    # Running in Docker container
    CONFIG_DIR="/var/www/html/config/vpn-clients"
    python3 /app/vpn-manager.py --export-client "$AIRPORT_ID" 2>/dev/null || true
    
    # Find and display client config
    CONFIG_FILE=$(find "$CONFIG_DIR" -name "${AIRPORT_ID}_*_client.conf" 2>/dev/null | head -1)
else
    # Running locally
    CONFIG_FILE=$(find "$CLIENT_CONFIG_DIR" -name "${AIRPORT_ID}_*_client.conf" 2>/dev/null | head -1)
fi

if [ -z "$CONFIG_FILE" ] || [ ! -f "$CONFIG_FILE" ]; then
    echo "Error: Client configuration not found for airport '$AIRPORT_ID'"
    echo ""
    echo "Available configs:"
    if [ -d "$CLIENT_CONFIG_DIR" ]; then
        ls -1 "$CLIENT_CONFIG_DIR"/*.conf 2>/dev/null | xargs -n1 basename || echo "  (none)"
    else
        echo "  (config directory not found)"
    fi
    exit 1
fi

echo "Client configuration for $AIRPORT_ID:"
echo "=================================="
echo ""
cat "$CONFIG_FILE"
echo ""
echo "=================================="
echo "File location: $CONFIG_FILE"
echo ""
echo "To copy this config:"
echo "  cat '$CONFIG_FILE' | pbcopy    # macOS"
echo "  cat '$CONFIG_FILE' | xclip -sel clip    # Linux"





