#!/bin/sh
set -e

# Function to create symlink to shared config
create_symlink() {
    if [ -f /etc/wireguard-shared/wg0.conf ]; then
        ln -sf /etc/wireguard-shared/wg0.conf /etc/wireguard/wg0.conf
        echo "Linked WireGuard configuration"
    else
        echo "Warning: WireGuard config not found at /etc/wireguard-shared/wg0.conf"
    fi
}

# Function to set up firewall rules for airport isolation
setup_firewall_rules() {
    echo "Setting up firewall rules for airport isolation..."
    
    # Allow forwarding on wg0
    iptables -A FORWARD -i wg0 -j ACCEPT 2>/dev/null || true
    iptables -A FORWARD -o wg0 -j ACCEPT 2>/dev/null || true
    
    # Get server IP in VPN subnet (10.0.0.1)
    SERVER_IP=$(ip -4 addr show wg0 | grep -oP 'inet \K[\d.]+' | head -1)
    
    if [ -z "$SERVER_IP" ]; then
        echo "Warning: Could not determine server IP, skipping firewall rules"
        return
    fi
    
    # Allow traffic to/from server
    iptables -A FORWARD -i wg0 -d "$SERVER_IP" -j ACCEPT 2>/dev/null || true
    iptables -A FORWARD -o wg0 -s "$SERVER_IP" -j ACCEPT 2>/dev/null || true
    
    # Block peer-to-peer communication (prevent airports from talking to each other)
    # Allow established/related connections
    iptables -A FORWARD -i wg0 -o wg0 -m state --state ESTABLISHED,RELATED -j ACCEPT 2>/dev/null || true
    
    # Block all other peer-to-peer traffic
    iptables -A FORWARD -i wg0 -o wg0 -j DROP 2>/dev/null || true
    
    echo "Firewall rules configured"
}

# Wait for initial config to be written by VPN manager
# Check every 2 seconds for up to 30 seconds
timeout=30
elapsed=0
while [ $elapsed -lt $timeout ]; do
    if [ -f /etc/wireguard-shared/wg0.conf ]; then
        break
    fi
    sleep 2
    elapsed=$((elapsed + 2))
done

# Create initial symlink
create_symlink

# Start WireGuard interface if config exists
if [ -f /etc/wireguard/wg0.conf ]; then
    echo "Starting WireGuard interface wg0..."
    wg-quick up wg0 || {
        echo "Failed to start WireGuard interface"
        exit 1
    }
    echo "WireGuard interface wg0 started"
    
    # Set up firewall rules for airport isolation
    setup_firewall_rules
else
    echo "Warning: No WireGuard config found, interface not started"
fi

# Watch for config changes and reload
last_conf_mtime=0

while true; do
    sleep 5
    
    # Check if config file changed
    if [ -f /etc/wireguard-shared/wg0.conf ]; then
        conf_mtime=$(stat -c %Y /etc/wireguard-shared/wg0.conf 2>/dev/null || echo 0)
        if [ "$conf_mtime" != "$last_conf_mtime" ]; then
            echo "Config file changed, reloading WireGuard..."
            create_symlink
            
            # Reload WireGuard configuration
            if wg show wg0 > /dev/null 2>&1; then
                # Interface exists, sync config
                wg syncconf wg0 /etc/wireguard/wg0.conf || {
                    echo "Failed to sync config, restarting interface..."
                    wg-quick down wg0 2>/dev/null || true
                    wg-quick up wg0 || echo "Failed to restart WireGuard"
                }
            else
                # Interface doesn't exist, start it
                wg-quick up wg0 || echo "Failed to start WireGuard"
            fi
            
            last_conf_mtime=$conf_mtime
        fi
    fi
    
    # Health check: ensure interface is up
    if [ -f /etc/wireguard/wg0.conf ] && ! wg show wg0 > /dev/null 2>&1; then
        echo "WireGuard interface down, attempting to restart..."
        wg-quick up wg0 || echo "Failed to restart WireGuard"
    fi
done

