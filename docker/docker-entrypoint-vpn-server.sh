#!/bin/sh
set -e

# Function to create symlinks
create_symlinks() {
    if [ -f /etc/ipsec-shared/ipsec.conf ]; then
        ln -sf /etc/ipsec-shared/ipsec.conf /etc/ipsec.conf
    fi
    if [ -f /etc/ipsec-shared/ipsec.secrets ]; then
        ln -sf /etc/ipsec-shared/ipsec.secrets /etc/ipsec.secrets
        # Note: chmod on symlink target, not the symlink itself
        chmod 600 /etc/ipsec-shared/ipsec.secrets 2>/dev/null || true
    fi
}

# Wait for initial config to be written by VPN manager
# Check every 2 seconds for up to 30 seconds
timeout=30
elapsed=0
while [ $elapsed -lt $timeout ]; do
    if [ -f /etc/ipsec-shared/ipsec.conf ] && [ -f /etc/ipsec-shared/ipsec.secrets ]; then
        break
    fi
    sleep 2
    elapsed=$((elapsed + 2))
done

# Create initial symlinks
create_symlinks

# Start strongSwan in background
ipsec start --nofork &
IPSEC_PID=$!

# Watch for config changes and reload
last_conf_mtime=0
last_secrets_mtime=0

while kill -0 $IPSEC_PID 2>/dev/null; do
    sleep 5
    
    # Check if config files changed
    if [ -f /etc/ipsec-shared/ipsec.conf ]; then
        conf_mtime=$(stat -c %Y /etc/ipsec-shared/ipsec.conf 2>/dev/null || echo 0)
        if [ "$conf_mtime" != "$last_conf_mtime" ]; then
            echo "Config file changed, reloading..."
            create_symlinks
            ipsec reload
            last_conf_mtime=$conf_mtime
        fi
    fi
    
    if [ -f /etc/ipsec-shared/ipsec.secrets ]; then
        secrets_mtime=$(stat -c %Y /etc/ipsec-shared/ipsec.secrets 2>/dev/null || echo 0)
        if [ "$secrets_mtime" != "$last_secrets_mtime" ]; then
            echo "Secrets file changed, reloading..."
            create_symlinks
            ipsec reload
            last_secrets_mtime=$secrets_mtime
        fi
    fi
done

wait $IPSEC_PID

