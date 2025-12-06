#!/bin/bash
# Configure firewall ports for all production services
# This script ensures all required ports are open in ufw

set -e

# Ports required for production services
# Format: PORT:PROTOCOL:DESCRIPTION
# Note: SSH (22) is typically already configured, but included for completeness
PORTS=(
    "80:tcp:HTTP (Nginx)"
    "443:tcp:HTTPS (Nginx)"
    "2121:tcp:FTP (Push webcams)"
    "2122:tcp:FTPS (Push webcams)"
    "2222:tcp:SFTP (Push webcams)"
    "22:tcp:SSH (System access)"
    "500:udp:IPsec IKE (VPN)"
    "4500:udp:IPsec NAT-T (VPN)"
)

echo "Configuring firewall ports for production services..."
echo ""

# Check if ufw is installed
if ! command -v ufw >/dev/null 2>&1; then
    echo "❌ ufw is not installed"
    echo "Install with: sudo apt-get install ufw"
    exit 1
fi

# Check if running as root or with sudo
if [ "$EUID" -ne 0 ]; then
    echo "⚠️  Not running as root, using sudo..."
    SUDO="sudo"
else
    SUDO=""
fi

# Enable ufw if not already enabled (non-interactive)
if ! $SUDO ufw status | grep -q "Status: active"; then
    echo "Enabling ufw..."
    echo "y" | $SUDO ufw --force enable
fi

# Configure each port
for port_config in "${PORTS[@]}"; do
    IFS=':' read -r port protocol description <<< "$port_config"
    
    # Check if rule already exists
    if $SUDO ufw status | grep -q "^${port}/${protocol}"; then
        echo "✓ Port ${port}/${protocol} (${description}) already configured"
    else
        echo "Adding port ${port}/${protocol} (${description})..."
        $SUDO ufw allow ${port}/${protocol} comment "${description}"
        echo "✓ Port ${port}/${protocol} added"
    fi
done

echo ""
echo "Current firewall status:"
$SUDO ufw status numbered

echo ""
echo "✓ Firewall configuration complete"

