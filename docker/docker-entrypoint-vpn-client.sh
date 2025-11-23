#!/bin/bash
set -e

# Configuration from environment variables
VPN_SERVER_IP="${VPN_SERVER_IP:-127.0.0.1}"
CONNECTION_NAME="${CONNECTION_NAME:-test_vpn}"
REMOTE_SUBNET="${REMOTE_SUBNET:-192.168.1.0/24}"
PSK="${PSK:-test-psk}"
IKE_VERSION="${IKE_VERSION:-2}"
ENCRYPTION="${ENCRYPTION:-aes256gcm128}"
DH_GROUP="${DH_GROUP:-14}"

# If VPN_SERVER_IP is a hostname (not an IP), try to resolve it
if ! echo "$VPN_SERVER_IP" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$'; then
    # It's a hostname, try to resolve it
    RESOLVED_IP=$(getent hosts "$VPN_SERVER_IP" | awk '{print $1}' | head -1)
    if [ -n "$RESOLVED_IP" ]; then
        echo "Resolved $VPN_SERVER_IP to $RESOLVED_IP"
        VPN_SERVER_IP="$RESOLVED_IP"
    else
        echo "Warning: Could not resolve $VPN_SERVER_IP, using as-is"
    fi
fi

echo "Starting mock VPN client..."
echo "Server: $VPN_SERVER_IP"
echo "Connection: $CONNECTION_NAME"
echo "Remote subnet: $REMOTE_SUBNET"

# Generate ipsec.conf
cat > /etc/ipsec.conf <<EOF
config setup
    charondebug="ike 2, knl 2, cfg 2"
    uniqueids=never
    strictcrlpolicy=no

conn $CONNECTION_NAME
    type=tunnel
    auto=start
    keyexchange=ikev$IKE_VERSION
    ike=$ENCRYPTION-sha256-modp2048!
    esp=$ENCRYPTION-sha256-modp2048!
    # Use static IP for local testing to avoid %defaultroute resolution issues
    left=${VPN_CLIENT_LEFT_IP:-%defaultroute}
    leftid=@kspb.remote
    leftsubnet=$REMOTE_SUBNET
    leftauth=psk
    right=$VPN_SERVER_IP
    rightid=@vpn.aviationwx.org
    rightsubnet=0.0.0.0/0
    rightauth=psk
    dpdaction=restart
    dpddelay=30s
    dpdtimeout=120s
    rekey=yes
    reauth=yes
    fragmentation=yes
    forceencaps=yes
EOF

# Generate ipsec.secrets
cat > /etc/ipsec.secrets <<EOF
: PSK "$PSK"
EOF

chmod 600 /etc/ipsec.secrets

echo "Configuration generated. Starting IPsec..."

# Start strongSwan in background first to load config
ipsec start --nofork &
IPSEC_PID=$!

# Wait a moment for strongSwan to fully start and load config
sleep 2

# Verify config is loaded
if ipsec listall | grep -q "test_vpn"; then
    echo "Configuration loaded successfully"
else
    echo "Warning: Configuration may not be loaded, attempting reload..."
    ipsec reload
    sleep 1
fi

# Wait for IPsec process
wait $IPSEC_PID

