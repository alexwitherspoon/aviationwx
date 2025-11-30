#!/bin/sh
set -e

# Environment variables for client configuration
CLIENT_PRIVATE_KEY="${CLIENT_PRIVATE_KEY}"
SERVER_PUBLIC_KEY="${SERVER_PUBLIC_KEY}"
SERVER_ENDPOINT="${SERVER_ENDPOINT:-127.0.0.1:51820}"
CLIENT_IP="${CLIENT_IP:-10.0.0.2/32}"
ALLOWED_IPS="${ALLOWED_IPS:-192.168.1.0/24}"

if [ -z "$CLIENT_PRIVATE_KEY" ] || [ -z "$SERVER_PUBLIC_KEY" ]; then
    echo "Error: CLIENT_PRIVATE_KEY and SERVER_PUBLIC_KEY must be set"
    exit 1
fi

echo "Configuring WireGuard client..."

# Create WireGuard client configuration
cat > /etc/wireguard/wg0.conf <<EOF
[Interface]
PrivateKey = ${CLIENT_PRIVATE_KEY}
Address = ${CLIENT_IP}

[Peer]
PublicKey = ${SERVER_PUBLIC_KEY}
Endpoint = ${SERVER_ENDPOINT}
AllowedIPs = ${ALLOWED_IPS}
PersistentKeepalive = 25
EOF

echo "WireGuard client configuration created"
cat /etc/wireguard/wg0.conf

# Start WireGuard interface
echo "Starting WireGuard client interface..."
wg-quick up wg0 || {
    echo "Failed to start WireGuard interface"
    exit 1
}

echo "WireGuard client connected"
echo "Interface status:"
wg show

# Keep container running
echo "WireGuard client running. Monitoring connection..."
while true; do
    sleep 30
    if ! wg show wg0 > /dev/null 2>&1; then
        echo "WireGuard interface down, attempting to restart..."
        wg-quick up wg0 || echo "Failed to restart"
    fi
done





