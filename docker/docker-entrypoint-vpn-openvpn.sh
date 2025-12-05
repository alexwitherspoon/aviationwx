#!/bin/sh
set -e

# Function to create symlinks to shared configs
create_symlinks() {
    if [ -f /etc/openvpn-shared/server.conf ]; then
        ln -sf /etc/openvpn-shared/server.conf /etc/openvpn/server.conf
        echo "Linked OpenVPN server configuration"
    else
        echo "Warning: OpenVPN server config not found at /etc/openvpn-shared/server.conf"
    fi
    
    if [ -f /etc/openvpn-shared/psk.key ]; then
        ln -sf /etc/openvpn-shared/psk.key /etc/openvpn/psk.key
        chmod 600 /etc/openvpn/psk.key 2>/dev/null || true
        echo "Linked OpenVPN PSK file"
    else
        echo "Warning: OpenVPN PSK file not found at /etc/openvpn-shared/psk.key"
    fi
}

# Function to set up firewall rules for airport isolation
setup_firewall_rules() {
    echo "Setting up firewall rules for airport isolation..."
    
    # Allow forwarding on tun0
    iptables -A FORWARD -i tun0 -j ACCEPT 2>/dev/null || true
    iptables -A FORWARD -o tun0 -j ACCEPT 2>/dev/null || true
    
    # Get server IP in VPN subnet (10.0.0.1)
    SERVER_IP=$(ip -4 addr show tun0 | grep -oP 'inet \K[\d.]+' | head -1)
    
    if [ -z "$SERVER_IP" ]; then
        echo "Warning: Could not determine server IP, skipping firewall rules"
        return
    fi
    
    # Allow traffic to/from server
    iptables -A FORWARD -i tun0 -d "$SERVER_IP" -j ACCEPT 2>/dev/null || true
    iptables -A FORWARD -o tun0 -s "$SERVER_IP" -j ACCEPT 2>/dev/null || true
    
    # Block peer-to-peer communication (prevent airports from talking to each other)
    # Allow established/related connections
    iptables -A FORWARD -i tun0 -o tun0 -m state --state ESTABLISHED,RELATED -j ACCEPT 2>/dev/null || true
    
    # Block all other peer-to-peer traffic
    iptables -A FORWARD -i tun0 -o tun0 -j DROP 2>/dev/null || true
    
    echo "Firewall rules configured"
}

# Wait for initial config to be written by VPN manager
# Check every 2 seconds for up to 30 seconds
timeout=30
elapsed=0
while [ $elapsed -lt $timeout ]; do
    if [ -f /etc/openvpn-shared/server.conf ] && [ -f /etc/openvpn-shared/psk.key ]; then
        break
    fi
    sleep 2
    elapsed=$((elapsed + 2))
done

# Create initial symlinks
create_symlinks

# Start OpenVPN server if config exists
if [ -f /etc/openvpn/server.conf ] && [ -f /etc/openvpn/psk.key ]; then
    echo "Starting OpenVPN server..."
    openvpn --config /etc/openvpn/server.conf --daemon openvpn-server || {
        echo "Failed to start OpenVPN server"
        exit 1
    }
    echo "OpenVPN server started"
    
    # Wait a moment for interface to come up
    sleep 2
    
    # Set up firewall rules for airport isolation
    setup_firewall_rules
else
    echo "Warning: OpenVPN config files not found, server not started"
fi

# Watch for config changes and reload
last_conf_mtime=0
last_psk_mtime=0

while true; do
    sleep 5
    
    # Check if config files changed
    if [ -f /etc/openvpn-shared/server.conf ]; then
        conf_mtime=$(stat -c %Y /etc/openvpn-shared/server.conf 2>/dev/null || echo 0)
        if [ "$conf_mtime" != "$last_conf_mtime" ]; then
            echo "Server config file changed, reloading OpenVPN..."
            create_symlinks
            
            # Restart OpenVPN server
            pkill -f "openvpn.*server.conf" 2>/dev/null || true
            sleep 1
            
            if [ -f /etc/openvpn/server.conf ] && [ -f /etc/openvpn/psk.key ]; then
                openvpn --config /etc/openvpn/server.conf --daemon openvpn-server || {
                    echo "Failed to restart OpenVPN server"
                }
                sleep 2
                setup_firewall_rules
            fi
            
            last_conf_mtime=$conf_mtime
        fi
    fi
    
    if [ -f /etc/openvpn-shared/psk.key ]; then
        psk_mtime=$(stat -c %Y /etc/openvpn-shared/psk.key 2>/dev/null || echo 0)
        if [ "$psk_mtime" != "$last_psk_mtime" ]; then
            echo "PSK file changed, reloading OpenVPN..."
            create_symlinks
            
            # Restart OpenVPN server
            pkill -f "openvpn.*server.conf" 2>/dev/null || true
            sleep 1
            
            if [ -f /etc/openvpn/server.conf ] && [ -f /etc/openvpn/psk.key ]; then
                openvpn --config /etc/openvpn/server.conf --daemon openvpn-server || {
                    echo "Failed to restart OpenVPN server"
                }
                sleep 2
                setup_firewall_rules
            fi
            
            last_psk_mtime=$psk_mtime
        fi
    fi
    
    # Health check: ensure server is running
    if [ -f /etc/openvpn/server.conf ] && ! pgrep -f "openvpn.*server.conf" > /dev/null 2>&1; then
        echo "OpenVPN server down, attempting to restart..."
        if [ -f /etc/openvpn/server.conf ] && [ -f /etc/openvpn/psk.key ]; then
            openvpn --config /etc/openvpn/server.conf --daemon openvpn-server || {
                echo "Failed to restart OpenVPN server"
            }
            sleep 2
            setup_firewall_rules
        fi
    fi
done





