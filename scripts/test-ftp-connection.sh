#!/bin/bash
# Test FTP/SFTP connection with a specific user
# Usage: test-ftp-connection.sh <username> <password> <protocol> [port]

set -e

USERNAME="$1"
PASSWORD="$2"
PROTOCOL="${3:-ftp}"
PORT="${4:-}"

if [ -z "$USERNAME" ] || [ -z "$PASSWORD" ]; then
    echo "Usage: $0 <username> <password> <protocol> [port]"
    echo "Protocol: ftp, ftps, or sftp"
    exit 1
fi

# Set default ports
case "$PROTOCOL" in
    ftp)
        PORT="${PORT:-2121}"
        ;;
    ftps)
        PORT="${PORT:-2122}"
        ;;
    sftp)
        PORT="${PORT:-2222}"
        ;;
    *)
        echo "Invalid protocol: $PROTOCOL"
        exit 1
        ;;
esac

echo "Testing $PROTOCOL connection..."
echo "Username: $USERNAME"
echo "Port: $PORT"
echo ""

if [ "$PROTOCOL" = "sftp" ]; then
    # Test SFTP
    echo "Testing SFTP connection..."
    docker compose -f docker/docker-compose.prod.yml exec web bash -c "
        echo 'Testing SFTP connection to localhost:$PORT...'
        timeout 10 sftp -oStrictHostKeyChecking=no -oUserKnownHostsFile=/dev/null -P $PORT $USERNAME@localhost <<EOF
$PASSWORD
pwd
ls
quit
EOF
    " 2>&1
else
    # Test FTP/FTPS
    echo "Testing FTP connection..."
    docker compose -f docker/docker-compose.prod.yml exec web bash -c "
        echo 'Testing FTP connection to localhost:$PORT...'
        {
            echo 'USER $USERNAME'
            sleep 1
            echo 'PASS $PASSWORD'
            sleep 1
            echo 'PWD'
            sleep 1
            echo 'CWD incoming'
            sleep 1
            echo 'QUIT'
        } | timeout 10 nc localhost $PORT 2>&1
    "
fi

echo ""
echo "Connection test complete"

