# Quick Diagnostic Steps

Run these commands in order to diagnose the 454 error:

## Step 1: Run Comprehensive Diagnostic

```bash
# Copy script into container and run
docker compose -f docker/docker-compose.prod.yml exec web bash -c "cat > /tmp/diagnose.sh" < scripts/diagnose-ftp-sftp.sh
docker compose -f docker/docker-compose.prod.yml exec web chmod +x /tmp/diagnose.sh
docker compose -f docker/docker-compose.prod.yml exec web bash /tmp/diagnose.sh
```

This will show you:
- What push cameras are configured
- If users exist
- Directory structure and permissions
- Service status
- Recent logs

## Step 2: Force Fresh Sync

```bash
# Remove sync timestamp to force fresh sync
docker compose -f docker/docker-compose.prod.yml exec web rm -f /var/www/html/cache/push_webcams/last_sync.json

# Run sync
docker compose -f docker/docker-compose.prod.yml exec web php /var/www/html/scripts/sync-push-config.php

# Check for errors
docker compose -f docker/docker-compose.prod.yml logs web | grep -i "sync-push-config\|error" | tail -30
```

## Step 3: Check Specific Camera

Replace `kczk_0` with your actual `airportId_camIndex`:

```bash
# Get your camera info from config
docker compose -f docker/docker-compose.prod.yml exec web php -r "
require '/var/www/html/lib/config.php';
\$config = loadConfig(false);
foreach (\$config['airports'] ?? [] as \$aid => \$ap) {
    foreach (\$ap['webcams'] ?? [] as \$i => \$cam) {
        \$isPush = (isset(\$cam['type']) && \$cam['type'] === 'push') || isset(\$cam['push_config']);
        if (\$isPush && isset(\$cam['push_config'])) {
            echo \"Airport: \$aid, Camera: \$i, Protocol: \" . (\$cam['push_config']['protocol'] ?? 'sftp') . \"\n\";
            echo \"Directory should be: /var/www/html/uploads/webcams/\$aid\" . \"_\" . \$i . \"\n\";
        }
    }
}
"

# Check directory exists and permissions
docker compose -f docker/docker-compose.prod.yml exec web ls -la /var/www/html/uploads/webcams/kczk_0/
docker compose -f docker/docker-compose.prod.yml exec web ls -la /var/www/html/uploads/webcams/kczk_0/incoming/
```

## Step 4: Verify User Exists

For FTP/FTPS:
```bash
# Check virtual users
docker compose -f docker/docker-compose.prod.yml exec web cat /etc/vsftpd/virtual_users.txt

# Check user config
docker compose -f docker/docker-compose.prod.yml exec web cat /etc/vsftpd/users/YOUR_USERNAME
```

For SFTP:
```bash
# Check if user exists
docker compose -f docker/docker-compose.prod.yml exec web id YOUR_USERNAME

# Check user details
docker compose -f docker/docker-compose.prod.yml exec web getent passwd YOUR_USERNAME
```

## Step 5: Test Connection

```bash
# Test FTP (replace with your credentials)
docker compose -f docker/docker-compose.prod.yml exec web bash scripts/test-ftp-connection.sh YOUR_USERNAME YOUR_PASSWORD ftp 2121

# Test SFTP
docker compose -f docker/docker-compose.prod.yml exec web bash scripts/test-ftp-connection.sh YOUR_USERNAME YOUR_PASSWORD sftp 2222
```

## Common Fixes

### If directories don't exist:
```bash
# Force sync again
docker compose -f docker/docker-compose.prod.yml exec web rm -f /var/www/html/cache/push_webcams/last_sync.json
docker compose -f docker/docker-compose.prod.yml exec web php /var/www/html/scripts/sync-push-config.php
```

### If permissions are wrong (FTP/FTPS):
```bash
# Fix permissions (replace kczk_0 with your directory)
docker compose -f docker/docker-compose.prod.yml exec web chown root:root /var/www/html/uploads/webcams/kczk_0
docker compose -f docker/docker-compose.prod.yml exec web chmod 755 /var/www/html/uploads/webcams/kczk_0
docker compose -f docker/docker-compose.prod.yml exec web chown www-data:www-data /var/www/html/uploads/webcams/kczk_0/incoming
docker compose -f docker/docker-compose.prod.yml exec web chmod 755 /var/www/html/uploads/webcams/kczk_0/incoming
```

### If permissions are wrong (SFTP):
```bash
# Fix all parent directories (must be root:root)
docker compose -f docker/docker-compose.prod.yml exec web chown root:root /var/www/html/uploads
docker compose -f docker/docker-compose.prod.yml exec web chmod 755 /var/www/html/uploads
docker compose -f docker/docker-compose.prod.yml exec web chown root:root /var/www/html/uploads/webcams
docker compose -f docker/docker-compose.prod.yml exec web chmod 755 /var/www/html/uploads/webcams
docker compose -f docker/docker-compose.prod.yml exec web chown root:root /var/www/html/uploads/webcams/kczk_0
docker compose -f docker/docker-compose.prod.yml exec web chmod 755 /var/www/html/uploads/webcams/kczk_0

# Incoming should be user-owned
docker compose -f docker/docker-compose.prod.yml exec web chown YOUR_USERNAME:YOUR_USERNAME /var/www/html/uploads/webcams/kczk_0/incoming
docker compose -f docker/docker-compose.prod.yml exec web chmod 755 /var/www/html/uploads/webcams/kczk_0/incoming
```

