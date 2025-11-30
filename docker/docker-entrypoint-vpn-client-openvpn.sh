#!/bin/sh
set -e

# Environment variables for client configuration
PSK="${PSK}"
SERVER_ENDPOINT="${SERVER_ENDPOINT:-127.0.0.1:1194}"
PROTOCOL="${PROTOCOL:-udp}"

if [ -z "$PSK" ]; then
    echo "Error: PSK must be set"
    exit 1
fi

echo "Configuring OpenVPN client..."

# Create PSK file
echo "$PSK" > /etc/openvpn/psk.key
chmod 600 /etc/openvpn/psk.key

# Create OpenVPN client configuration
cat > /etc/openvpn/client.conf <<EOF
client
dev tun
proto ${PROTOCOL}
remote ${SERVER_ENDPOINT}
resolv-retry infinite
nobind
persist-key
persist-tun
secret /etc/openvpn/psk.key
cipher AES-256-CBC
auth SHA256
comp-lzo
verb 3
EOF

echo "OpenVPN client configuration created"

# Start OpenVPN client
echo "Starting OpenVPN client..."
openvpn --config /etc/openvpn/client.conf --daemon openvpn-client || {
    echo "Failed to start OpenVPN client"
    exit 1
}

echo "OpenVPN client connected"

# Keep container running and monitor
echo "OpenVPN client running. Monitoring connection..."
while true; do
    sleep 30
    if ! pgrep -f "openvpn.*client.conf" > /dev/null 2>&1; then
        echo "OpenVPN client down, attempting to restart..."
        openvpn --config /etc/openvpn/client.conf --daemon openvpn-client || {
            echo "Failed to restart OpenVPN client"
        }
    fi
done





