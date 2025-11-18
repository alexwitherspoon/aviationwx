#!/bin/bash
set -e

# Start cron daemon in background
echo "Starting cron daemon..."
cron

# Start vsftpd
echo "Starting vsftpd..."
service vsftpd start || {
    echo "Error: vsftpd failed to start"
    exit 1
}

# Start sshd (if not already running)
echo "Starting sshd..."
service ssh start || {
    echo "Error: sshd failed to start"
    exit 1
}

# Verify services are running
if ! pgrep -x vsftpd > /dev/null; then
    echo "Error: vsftpd is not running"
    exit 1
fi

if ! pgrep -x sshd > /dev/null; then
    echo "Error: sshd is not running"
    exit 1
fi

# Verify ports are listening (give services a moment to bind)
sleep 2
if ! netstat -tuln 2>/dev/null | grep -q ':2121\|:2122\|:2222'; then
    echo "Warning: FTP/SFTP ports may not be listening yet"
fi

echo "All services started successfully"

# Start service watchdog in background
echo "Starting service watchdog..."
/usr/local/bin/service-watchdog.sh &
WATCHDOG_PID=$!

# Trap signals to clean up watchdog on exit
trap "kill $WATCHDOG_PID 2>/dev/null || true" EXIT

# Start fail2ban
echo "Starting fail2ban..."
# Ensure log files exist
touch /var/log/vsftpd.log /var/log/auth.log
chmod 644 /var/log/vsftpd.log /var/log/auth.log

# Start fail2ban server in background
# Use systemd service if available, otherwise start directly
if command -v systemctl >/dev/null 2>&1 && systemctl is-system-running >/dev/null 2>&1; then
    systemctl start fail2ban || fail2ban-server -x &
else
    # Start fail2ban server directly in background
    fail2ban-server -x &
fi
FAIL2BAN_PID=$!

# Wait a moment for fail2ban to start
sleep 3

# Verify fail2ban is running
if pgrep -x fail2ban-server > /dev/null || pgrep -f "fail2ban-server" > /dev/null; then
    echo "✓ fail2ban started successfully"
    # Wait a bit more for jails to initialize
    sleep 2
    # Show active jails
    fail2ban-client status 2>/dev/null | grep -A 10 "Jail list" || echo "  (jails initializing...)"
else
    echo "⚠️  Warning: fail2ban may not have started properly"
    echo "This is non-fatal - services will continue without fail2ban protection"
fi

# Trap signals to clean up fail2ban on exit
trap "kill $WATCHDOG_PID $FAIL2BAN_PID 2>/dev/null || true" EXIT

# Execute Apache entrypoint (starts Apache in foreground)
# Use docker-php-entrypoint if available, otherwise call apache2-foreground directly
if command -v docker-php-entrypoint >/dev/null 2>&1; then
    exec docker-php-entrypoint apache2-foreground
else
    exec apache2-foreground
fi

