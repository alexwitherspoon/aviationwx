#!/bin/bash
# Test FTP/FTPS/SFTP connections to production from local environment
# Usage: ./scripts/test-production-ftp.sh [username] [password]

PROD_HOST="upload.aviationwx.org"
FTP_PORT=2121
FTPS_PORT=2122
SFTP_PORT=2222

echo "=========================================="
echo "Production FTP/SFTP Connection Test"
echo "Host: $PROD_HOST"
echo "=========================================="
echo ""

# Test port connectivity
echo "1. Testing Port Connectivity"
echo "----------------------------"
for port in $FTP_PORT $FTPS_PORT $SFTP_PORT; do
    protocol=""
    case $port in
        2121) protocol="FTP" ;;
        2122) protocol="FTPS" ;;
        2222) protocol="SFTP" ;;
    esac
    
    if timeout 5 bash -c "echo > /dev/tcp/$PROD_HOST/$port" 2>/dev/null; then
        echo "✓ Port $port ($protocol) is OPEN"
    else
        echo "✗ Port $port ($protocol) is CLOSED or TIMEOUT"
    fi
done

echo ""
echo "2. DNS Resolution"
echo "----------------"
host $PROD_HOST 2>/dev/null | head -5 || echo "DNS lookup failed"

echo ""
echo "3. Testing FTP Connection (port $FTP_PORT)"
echo "-------------------------------------------"
if [ -n "$1" ] && [ -n "$2" ]; then
    curl -v --connect-timeout 10 --user "$1:$2" ftp://$PROD_HOST:$FTP_PORT/ 2>&1 | grep -E "220|331|230|530|PWD|CWD|250|Connection|timeout" | head -15
else
    echo "No credentials provided - testing connection only"
    curl -v --connect-timeout 10 ftp://$PROD_HOST:$FTP_PORT/ 2>&1 | grep -E "220|Connection|timeout|refused" | head -10
fi

echo ""
echo "4. Testing FTPS Connection (port $FTPS_PORT)"
echo "---------------------------------------------"
if [ -n "$1" ] && [ -n "$2" ]; then
    curl -v --connect-timeout 10 --ftp-ssl --insecure --user "$1:$2" ftp://$PROD_HOST:$FTPS_PORT/ 2>&1 | grep -E "220|AUTH|SSL|TLS|331|230|530|Connection|timeout" | head -15
else
    echo "No credentials provided - testing connection only"
    curl -v --connect-timeout 10 --ftp-ssl --insecure ftp://$PROD_HOST:$FTPS_PORT/ 2>&1 | grep -E "220|AUTH|Connection|timeout|refused" | head -10
fi

echo ""
echo "5. Testing SFTP Connection (port $SFTP_PORT)"
echo "----------------------------------------------"
if [ -n "$1" ] && [ -n "$2" ]; then
    if command -v sshpass >/dev/null 2>&1; then
        sshpass -p "$2" sftp -oStrictHostKeyChecking=no -oUserKnownHostsFile=/dev/null -oConnectTimeout=10 -P $SFTP_PORT "$1@$PROD_HOST" <<EOF 2>&1 | head -15
pwd
ls
quit
EOF
    else
        echo "sshpass not installed. Install with: brew install hudochenkov/sshpass/sshpass (macOS) or apt-get install sshpass (Linux)"
        echo "Manual test: sftp -P $SFTP_PORT $1@$PROD_HOST"
    fi
else
    echo "No credentials provided - testing connection only"
    timeout 5 ssh -oStrictHostKeyChecking=no -oUserKnownHostsFile=/dev/null -oConnectTimeout=5 -p $SFTP_PORT $PROD_HOST 2>&1 | head -10 || echo "Connection timeout or refused"
fi

echo ""
echo "=========================================="
echo "Test Complete"
echo "=========================================="
echo ""
echo "If ports are timing out, check:"
echo "  1. Firewall rules on production server"
echo "  2. Cloudflare/load balancer port forwarding"
echo "  3. Docker port mappings"
echo "  4. Security group rules (if using cloud provider)"

