# Production Host Cleanup and Recheckout Guide

This guide walks you through forcing a complete cleanup and recheckout of files on the production host.

## Step-by-Step Commands

### 1. SSH into the production host
```bash
ssh aviationwx@aviationwx.org
# Or use your SSH alias/configuration
```

### 2. Stop all containers
```bash
cd ~/aviationwx
docker compose -f docker/docker-compose.prod.yml down
```

### 3. Clean up old directory structure (if it exists)
```bash
cd ~/aviationwx
# Remove problematic docker/docker directory if it exists
if [ -d docker/docker ]; then
  echo "Removing old docker/docker directory..."
  rm -rf docker/docker
fi
# Remove any other nested docker directories
find docker -type d -name docker -mindepth 2 -exec rm -rf {} + 2>/dev/null || true
```

### 4. Backup important files (optional but recommended)
```bash
cd ~/aviationwx
# Backup airports.json (contains API keys)
cp airports.json airports.json.backup.$(date +%Y%m%d_%H%M%S) || true
# Backup SSL certificates
cp -r ssl ssl.backup.$(date +%Y%m%d_%H%M%S) || true
```

### 5. Clean up repository files (keep .git and cache)
```bash
cd ~/aviationwx
# Remove all files except .git and cache directories
find . -mindepth 1 -maxdepth 1 ! -name '.git' ! -name 'cache' ! -name '*.backup*' -exec rm -rf {} +
```

### 6. Force fresh checkout from main branch
```bash
cd ~/aviationwx
# Reset to latest main branch (discards any local changes)
git fetch origin
git reset --hard origin/main
git clean -fd
```

### 7. Verify files are present
```bash
cd ~/aviationwx
# Check that key files exist
ls -la docker/nginx.conf
ls -la docker/docker-compose.prod.yml
ls -la ssl/ 2>/dev/null || echo "SSL directory may not exist yet"
```

### 8. Restore important files if needed
```bash
cd ~/aviationwx
# Restore airports.json if you backed it up
# cp airports.json.backup.* airports.json
# Restore SSL certificates if you backed them up
# cp -r ssl.backup.* ssl
```

### 9. Ensure cache directory exists
```bash
mkdir -p /tmp/aviationwx-cache/webcams
chmod -R 777 /tmp/aviationwx-cache || true
```

### 10. Rebuild and start containers
```bash
cd ~/aviationwx
# Set GIT_SHA if needed
export GIT_SHA=$(git rev-parse --short HEAD)
# Build and start containers
docker compose -f docker/docker-compose.prod.yml up -d --build
```

### 11. Verify containers are running
```bash
cd ~/aviationwx
docker compose -f docker/docker-compose.prod.yml ps
```

### 12. Check logs for any errors
```bash
cd ~/aviationwx
# Check nginx logs
docker compose -f docker/docker-compose.prod.yml logs nginx | tail -50
# Check web container logs
docker compose -f docker/docker-compose.prod.yml logs web | tail -50
```

### 13. Test nginx configuration
```bash
cd ~/aviationwx
docker compose -f docker/docker-compose.prod.yml exec nginx nginx -t
```

## Quick One-Liner (Use with Caution)

If you want to do everything in one go (make sure you've backed up important files first):

```bash
cd ~/aviationwx && \
docker compose -f docker/docker-compose.prod.yml down && \
rm -rf docker/docker && \
find . -mindepth 1 -maxdepth 1 ! -name '.git' ! -name 'cache' ! -name '*.backup*' -exec rm -rf {} + && \
git fetch origin && \
git reset --hard origin/main && \
git clean -fd && \
mkdir -p /tmp/aviationwx-cache/webcams && \
export GIT_SHA=$(git rev-parse --short HEAD) && \
docker compose -f docker/docker-compose.prod.yml up -d --build && \
docker compose -f docker/docker-compose.prod.yml ps
```

## Troubleshooting

### If containers fail to start:
```bash
# Check logs
docker compose -f docker/docker-compose.prod.yml logs

# Check if files exist
ls -la docker/nginx.conf
ls -la docker/docker-compose.prod.yml

# Verify paths in docker-compose
cat docker/docker-compose.prod.yml | grep -A 2 volumes
```

### If nginx container keeps restarting:
```bash
# Check nginx logs
docker compose -f docker/docker-compose.prod.yml logs nginx

# Test nginx config
docker compose -f docker/docker-compose.prod.yml exec nginx nginx -t

# Verify nginx.conf exists and is readable
ls -la docker/nginx.conf
cat docker/nginx.conf | head -20
```

### If SSL certificates are missing:
```bash
# Check if certificates exist
ls -la ssl/

# If missing, copy from Let's Encrypt (requires sudo)
sudo cp /etc/letsencrypt/live/aviationwx.org/fullchain.pem ssl/
sudo cp /etc/letsencrypt/live/aviationwx.org/privkey.pem ssl/
sudo chown -R aviationwx:aviationwx ssl/
sudo chmod 644 ssl/fullchain.pem
sudo chmod 600 ssl/privkey.pem
```

