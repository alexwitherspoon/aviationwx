# FTP/SFTP Diagnostic Guide

This guide helps diagnose 454 errors and connection issues with push webcams.

## Quick Diagnostic Script

Run the comprehensive diagnostic script:

```bash
# On production
docker compose -f docker/docker-compose.prod.yml exec web bash /var/www/html/scripts/diagnose-ftp-sftp.sh

# Or copy script into container first
docker compose -f docker/docker-compose.prod.yml exec web bash -c "cat > /tmp/diagnose.sh" < scripts/diagnose-ftp-sftp.sh
docker compose -f docker/docker-compose.prod.yml exec web bash /tmp/diagnose.sh
```

## Step-by-Step Manual Diagnosis

### Step 1: Verify Configuration

Check if push cameras are configured:

```bash
docker compose -f docker/docker-compose.prod.yml exec web php -r "
require '/var/www/html/lib/config.php';
\$config = loadConfig(false);
foreach (\$config['airports'] ?? [] as \$aid => \$ap) {
    foreach (\$ap['webcams'] ?? [] as \$i => \$cam) {
        \$isPush = (isset(\$cam['type']) && \$cam['type'] === 'push') || isset(\$cam['push_config']);
        if (\$isPush && isset(\$cam['push_config'])) {
            \$p = \$cam['push_config']['protocol'] ?? 'none';
            \$u = \$cam['push_config']['username'] ?? 'none';
            echo \"Found: \$aid cam \$i - Protocol: \$p - Username: \$u\n\";
        }
    }
}
"
```

### Step 2: Force Sync

Force a fresh sync to ensure users and directories are created:

```bash
# Remove sync timestamp
docker compose -f docker/docker-compose.prod.yml exec web rm -f /var/www/html/cache/push_webcams/last_sync.json

# Run sync
docker compose -f docker/docker-compose.prod.yml exec web php /var/www/html/scripts/sync-push-config.php

# Check logs
docker compose -f docker/docker-compose.prod.yml logs web | grep -i "sync-push-config" | tail -20
```

### Step 3: Verify FTP Users (for FTP/FTPS)

```bash
# Check virtual users file
docker compose -f docker/docker-compose.prod.yml exec web cat /etc/vsftpd/virtual_users.txt

# Check user config files
docker compose -f docker/docker-compose.prod.yml exec web ls -la /etc/vsftpd/users/

# Check specific user config
docker compose -f docker/docker-compose.prod.yml exec web cat /etc/vsftpd/users/YOUR_USERNAME
```

### Step 4: Verify SFTP Users (for SFTP)

```bash
# Check if user exists
docker compose -f docker/docker-compose.prod.yml exec web id YOUR_USERNAME

# Check user's home directory
docker compose -f docker/docker-compose.prod.yml exec web getent passwd YOUR_USERNAME

# Check user is in webcam_users group
docker compose -f docker/docker-compose.prod.yml exec web groups YOUR_USERNAME
```

### Step 5: Verify Directory Structure

```bash
# Get airport and camera index from config, then check:
# Replace kczk_0 with your actual airport_camIndex

# Check base directories
docker compose -f docker/docker-compose.prod.yml exec web ls -la /var/www/html/uploads/
docker compose -f docker/docker-compose.prod.yml exec web ls -la /var/www/html/uploads/webcams/

# Check camera directory
docker compose -f docker/docker-compose.prod.yml exec web ls -la /var/www/html/uploads/webcams/kczk_0/

# Check incoming directory
docker compose -f docker/docker-compose.prod.yml exec web ls -la /var/www/html/uploads/webcams/kczk_0/incoming/
```

**Expected permissions:**
- `/var/www/html/uploads` → `root:root` 755
- `/var/www/html/uploads/webcams` → `root:root` 755
- `/var/www/html/uploads/webcams/kczk_0` → `root:root` 755
- `/var/www/html/uploads/webcams/kczk_0/incoming` → 
  - For FTP/FTPS: `www-data:www-data` 755
  - For SFTP: `username:username` 755

### Step 6: Verify Services

```bash
# Check vsftpd is running
docker compose -f docker/docker-compose.prod.yml exec web pgrep -a vsftpd

# Check sshd is running
docker compose -f docker/docker-compose.prod.yml exec web pgrep -a sshd

# Check ports are listening
docker compose -f docker/docker-compose.prod.yml exec web netstat -tlnp | grep -E "2121|2122|2222"
```

### Step 7: Check Logs

```bash
# vsftpd logs
docker compose -f docker/docker-compose.prod.yml exec web tail -50 /var/log/vsftpd.log

# SSH/SFTP logs
docker compose -f docker/docker-compose.prod.yml exec web tail -50 /var/log/auth.log | grep -i "sftp\|ssh"

# Application logs
docker compose -f docker/docker-compose.prod.yml logs web | grep -i "sync-push-config\|ftp\|sftp" | tail -50
```

### Step 8: Test Connection

```bash
# Test FTP (replace with your credentials)
docker compose -f docker/docker-compose.prod.yml exec web bash scripts/test-ftp-connection.sh YOUR_USERNAME YOUR_PASSWORD ftp 2121

# Test SFTP
docker compose -f docker/docker-compose.prod.yml exec web bash scripts/test-ftp-connection.sh YOUR_USERNAME YOUR_PASSWORD sftp 2222
```

## Common Issues and Fixes

### Issue: 454 Error on FTP/FTPS

**Possible causes:**
1. Directory doesn't exist
2. Wrong directory permissions
3. User not in virtual users database
4. vsftpd not running

**Fix:**
```bash
# 1. Force sync
docker compose -f docker/docker-compose.prod.yml exec web rm -f /var/www/html/cache/push_webcams/last_sync.json
docker compose -f docker/docker-compose.prod.yml exec web php /var/www/html/scripts/sync-push-config.php

# 2. Fix directory permissions (replace kczk_0 with your airport_camIndex)
docker compose -f docker/docker-compose.prod.yml exec web chown root:root /var/www/html/uploads/webcams/kczk_0
docker compose -f docker/docker-compose.prod.yml exec web chmod 755 /var/www/html/uploads/webcams/kczk_0
docker compose -f docker/docker-compose.prod.yml exec web chown www-data:www-data /var/www/html/uploads/webcams/kczk_0/incoming
docker compose -f docker/docker-compose.prod.yml exec web chmod 755 /var/www/html/uploads/webcams/kczk_0/incoming

# 3. Restart vsftpd
docker compose -f docker/docker-compose.prod.yml exec web service vsftpd restart
```

### Issue: Connection Reset on SFTP

**Possible causes:**
1. Chroot directory not owned by root
2. Parent directories not root-owned
3. User not in webcam_users group

**Fix:**
```bash
# Fix all parent directories (must be root:root)
docker compose -f docker/docker-compose.prod.yml exec web chown root:root /var/www/html/uploads
docker compose -f docker/docker-compose.prod.yml exec web chmod 755 /var/www/html/uploads
docker compose -f docker/docker-compose.prod.yml exec web chown root:root /var/www/html/uploads/webcams
docker compose -f docker/docker-compose.prod.yml exec web chmod 755 /var/www/html/uploads/webcams
docker compose -f docker/docker-compose.prod.yml exec web chown root:root /var/www/html/uploads/webcams/kczk_0
docker compose -f docker/docker-compose.prod.yml exec web chmod 755 /var/www/html/uploads/webcams/kczk_0

# Incoming directory should be user-owned
docker compose -f docker/docker-compose.prod.yml exec web chown YOUR_USERNAME:YOUR_USERNAME /var/www/html/uploads/webcams/kczk_0/incoming
docker compose -f docker/docker-compose.prod.yml exec web chmod 755 /var/www/html/uploads/webcams/kczk_0/incoming
```

## Local Testing

### Setup Local Environment

```bash
# Create local cache directory
mkdir -p cache-local

# Start local container
docker compose -f docker/docker-compose.local.yml up -d

# Check logs
docker compose -f docker/docker-compose.local.yml logs -f web
```

### Test with Local Config

1. Edit `config/airports.json.example` to add a test push camera
2. Copy to `config/airports.json` (or mount the example file)
3. Run sync script inside container
4. Test connections

```bash
# Run sync
docker compose -f docker/docker-compose.local.yml exec web php /var/www/html/scripts/sync-push-config.php

# Test connection
docker compose -f docker/docker-compose.local.yml exec web bash scripts/test-ftp-connection.sh TEST_USER TEST_PASS ftp 2121
```

