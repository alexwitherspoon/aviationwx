#!/bin/bash
# Script to open FTP/SFTP ports in ufw firewall
# Run this on the production server

set -e

echo "Opening FTP/SFTP ports in ufw firewall..."

# Allow FTP (port 2121)
echo "Allowing port 2121 (FTP)..."
sudo ufw allow 2121/tcp comment 'FTP for push webcams'

# Allow FTPS (port 2122)
echo "Allowing port 2122 (FTPS)..."
sudo ufw allow 2122/tcp comment 'FTPS for push webcams'

# Allow SFTP (port 2222)
echo "Allowing port 2222 (SFTP)..."
sudo ufw allow 2222/tcp comment 'SFTP for push webcams'

echo ""
echo "Firewall rules updated. Current status:"
sudo ufw status

echo ""
echo "Ports should now be accessible. Test with:"
echo "  curl -v --user USERNAME:PASSWORD ftp://upload.aviationwx.org:2121/"

