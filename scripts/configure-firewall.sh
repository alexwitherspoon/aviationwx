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
    "2121:tcp:FTP/FTPS (Push webcams - both protocols on same port)"
    "2222:tcp:SFTP (Push webcams)"
    "50000:50100:tcp:FTP passive mode (Push webcams)"
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
    # Split by colon - handle both single ports and port ranges
    IFS=':' read -ra parts <<< "$port_config"
    
    # Check if second part is a number (indicating port range like 50000:50100:tcp:desc)
    if [[ "${parts[1]}" =~ ^[0-9]+$ ]]; then
        # Port range format: PORT_START:PORT_END:PROTOCOL:DESCRIPTION
        port_start="${parts[0]}"
        port_end="${parts[1]}"
        protocol="${parts[2]}"
        description="${parts[3]}"
        port_range="${port_start}:${port_end}"
        
        if $SUDO ufw status | grep -q "${port_range}/${protocol}"; then
            echo "✓ Port range ${port_range}/${protocol} (${description}) already configured"
        else
            echo "Adding port range ${port_range}/${protocol} (${description})..."
            $SUDO ufw allow ${port_range}/${protocol} comment "${description}"
            echo "✓ Port range ${port_range}/${protocol} added"
        fi
    else
        # Single port format: PORT:PROTOCOL:DESCRIPTION
        port="${parts[0]}"
        protocol="${parts[1]}"
        description="${parts[2]}"
        
        if $SUDO ufw status | grep -q "^${port}/${protocol}"; then
            echo "✓ Port ${port}/${protocol} (${description}) already configured"
        else
            echo "Adding port ${port}/${protocol} (${description})..."
            $SUDO ufw allow ${port}/${protocol} comment "${description}"
            echo "✓ Port ${port}/${protocol} added"
        fi
    fi
done

echo ""
echo "Current firewall status:"
$SUDO ufw status numbered

echo ""
echo "✓ Firewall configuration complete"

