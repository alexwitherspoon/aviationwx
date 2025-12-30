#!/bin/bash
# Stop and disable host vsftpd service to allow container vsftpd to bind to port 2121
# This is needed when using network_mode: host in docker-compose

set -e

echo "Checking for host vsftpd service..."

# Check if vsftpd systemd service exists and is running
if systemctl is-active --quiet vsftpd 2>/dev/null; then
    echo "Host vsftpd service is running. Stopping..."
    sudo systemctl stop vsftpd
    
    echo "Disabling vsftpd service to prevent auto-start..."
    sudo systemctl disable vsftpd
    
    echo "✓ Host vsftpd service stopped and disabled"
elif systemctl list-unit-files | grep -q "^vsftpd.service"; then
    echo "Host vsftpd service exists but is not running. Disabling to prevent auto-start..."
    sudo systemctl disable vsftpd
    echo "✓ Host vsftpd service disabled"
else
    echo "No vsftpd systemd service found"
fi

# Check for vsftpd processes running outside container
HOST_VSFTPD_PIDS=$(ps aux | grep '[v]sftpd' | grep -v 'aviationwx-web' | awk '{print $2}' || true)

if [ -n "$HOST_VSFTPD_PIDS" ]; then
    echo "Found vsftpd processes running on host (outside container):"
    ps aux | grep '[v]sftpd' | grep -v 'aviationwx-web'
    echo ""
    echo "Killing host vsftpd processes..."
    for pid in $HOST_VSFTPD_PIDS; do
        echo "  Killing PID $pid"
        sudo kill $pid 2>/dev/null || true
    done
    sleep 1
    echo "✓ Host vsftpd processes stopped"
else
    echo "No host vsftpd processes found (outside container)"
fi

# Verify port 2121 is now available
echo ""
echo "Checking port 2121..."
if sudo ss -tlnp | grep -q ':2121'; then
    echo "⚠️  Warning: Port 2121 is still in use:"
    sudo ss -tlnp | grep ':2121'
    echo ""
    echo "You may need to restart the container for vsftpd to bind to port 2121:"
    echo "  cd /home/aviationwx/aviationwx"
    echo "  docker compose -f docker/docker-compose.prod.yml restart web"
else
    echo "✓ Port 2121 is now available"
    echo ""
    echo "Container vsftpd should be able to bind to port 2121."
    echo "If it's not already listening, restart the container:"
    echo "  cd /home/aviationwx/aviationwx"
    echo "  docker compose -f docker/docker-compose.prod.yml restart web"
fi

