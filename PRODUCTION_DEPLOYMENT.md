# Production Deployment Guide - Ubuntu LTS VPS

This guide covers deploying AviationWX.org from scratch on a fresh Ubuntu LTS VPS.

## Prerequisites

- Ubuntu 22.04 LTS (or 20.04 LTS) VPS
- Root or sudo access
- Domain name with DNS control
- GitHub repository access

## Step-by-Step Deployment

### 1. Initial Server Setup

#### 1.1 Create and Access VPS

Create a new Ubuntu LTS droplet/VPS (minimum 1GB RAM, 1 vCPU). SSH into the server:

```bash
ssh root@YOUR_SERVER_IP
```

#### 1.2 Update System

```bash
# Update package list and upgrade system
apt update && apt upgrade -y

# Install essential tools
apt install -y curl wget git nano ufw
```

#### 1.3 Create Application User

```bash
# Create dedicated user for the application
useradd -m -s /bin/bash aviationwx

# Add user to docker group (will be created when Docker is installed)
# Or add to sudo group if needed for specific operations
usermod -aG sudo aviationwx
```

#### 1.4 Configure Firewall

```bash
# Allow SSH (current connection)
ufw allow OpenSSH

# Allow HTTP and HTTPS
ufw allow 80/tcp
ufw allow 443/tcp

# Enable firewall
ufw --force enable

# Verify status
ufw status
```

### 2. Install Docker & Docker Compose

#### 2.1 Install Docker

```bash
# Install Docker using official convenience script
curl -fsSL https://get.docker.com -o get-docker.sh
sh get-docker.sh

# Verify installation
docker --version
```

#### 2.2 Install Docker Compose (Plugin)

```bash
# Install Docker Compose plugin (Compose v2)
apt install -y docker-compose-plugin

# Verify installation
docker compose version
```

#### 2.3 Configure Docker for Application User

```bash
# Add aviationwx user to docker group
usermod -aG docker aviationwx

# Switch to application user to verify Docker access
su - aviationwx
docker ps  # Should work without sudo

# Switch back to root
exit
```

### 3. DNS Configuration

Before continuing, configure DNS for your domain:

1. **Add A Records** in your DNS provider:
   - `@` → Your VPS IP address (for `aviationwx.org`)
   - `*` → Your VPS IP address (for `*.aviationwx.org` wildcard subdomains)

2. **Wait for DNS propagation** (5-60 minutes typically)

3. **Verify DNS**:
   ```bash
   # Check DNS resolution
   dig aviationwx.org +short
   dig kspb.aviationwx.org +short  # Replace with your airport code
   ```

### 4. Set Up SSL Certificates

#### 4.1 Install Certbot

```bash
# Install Certbot and Cloudflare DNS plugin (for wildcard certs)
apt install -y certbot python3-certbot-dns-cloudflare

# Verify installation
certbot --version
```

#### 4.2 Configure Cloudflare DNS Plugin (For Wildcard Certificates)

**Option A: Cloudflare DNS Challenge (Recommended for Wildcard)**

1. **Create Cloudflare API Token**:
   - Log in to Cloudflare Dashboard
   - Go to "My Profile" → "API Tokens"
   - Create token with:
     - Permissions: `Zone → DNS → Edit` and `Zone → Zone → Read`
     - Resources: `Include → Specific zone → aviationwx.org`

2. **Store Token Securely**:
   ```bash
   # Switch to application user
   su - aviationwx
   
   # Create secrets directory
   mkdir -p ~/.secrets
   
   # Store token (replace YOUR_TOKEN with actual token)
   printf 'dns_cloudflare_api_token = %s\n' 'YOUR_TOKEN' > ~/.secrets/cloudflare.ini
   chmod 600 ~/.secrets/cloudflare.ini
   
   # Switch back to root
   exit
   ```

3. **Generate Wildcard Certificate**:
   ```bash
   # As root
   certbot certonly \
     --dns-cloudflare \
     --dns-cloudflare-credentials ~/.secrets/cloudflare.ini \
     -d aviationwx.org \
     -d '*.aviationwx.org' \
     --non-interactive \
     --agree-tos \
     -m your@email.com
   ```

**Option B: HTTP Challenge (Simpler, but no wildcard)**

```bash
# Stop any web server running on ports 80/443 first
# Then run:
certbot certonly --standalone \
  -d aviationwx.org \
  -d kspb.aviationwx.org \
  --non-interactive \
  --agree-tos \
  -m your@email.com
```

#### 4.3 Copy Certificates to Application Directory

```bash
# Switch to application user
su - aviationwx

# Create application directory
git clone https://github.com/alexwitherspoon/aviationwx.git
cd aviationwx

# Create SSL directory
mkdir -p ssl

# Copy certificates (requires sudo)
sudo cp /etc/letsencrypt/live/aviationwx.org/fullchain.pem ssl/
sudo cp /etc/letsencrypt/live/aviationwx.org/privkey.pem ssl/

# Set correct ownership and permissions
sudo chown -R aviationwx:aviationwx ssl/
chmod 644 ssl/fullchain.pem
chmod 600 ssl/privkey.pem

# Verify certificates are in place
ls -lh ssl/
```

### 5. Configure Application

#### 5.1 Create Configuration Files

```bash
# Still as aviationwx user, in ~/aviationwx directory

# Copy example environment file (if exists)
cp env.example .env 2>/dev/null || true

# Create airports.json from example
cp airports.json.example airports.json
```

#### 5.2 Edit airports.json

```bash
# Edit with your API keys and credentials
nano airports.json
```

**Minimum required configuration**:
- Add at least one airport with weather source API keys
- Configure webcams (optional)
- Set timezone for each airport (optional, defaults to `America/Los_Angeles`)

See [CONFIGURATION.md](CONFIGURATION.md) for detailed configuration options.

#### 5.3 Generate Docker Configuration

If using generated configs:

```bash
# Generate nginx config from .env (if needed)
make config  # If Makefile exists

# Or manually create configs
```

### 6. Set Up Cron Jobs

#### 6.1 Webcam Refresh Cron Job

```bash
# As aviationwx user, set up cron to refresh webcam images every minute
crontab -e

# Add this line:
* * * * * cd ~/aviationwx && docker compose -f docker-compose.prod.yml exec -T web php fetch-webcam-safe.php > /dev/null 2>&1

# Or if using host-based execution:
* * * * * cd ~/aviationwx && php fetch-webcam-safe.php > /dev/null 2>&1
```

**Note**: The cron job runs `fetch-webcam-safe.php` to update webcam images every minute.

#### 6.2 Weather Refresh Cron Job

```bash
# As aviationwx user, set up cron to refresh weather data periodically
crontab -e

# Add this line (every 1 minute):
* * * * * cd ~/aviationwx && docker compose -f docker-compose.prod.yml exec -T web php fetch-weather-safe.php > /dev/null 2>&1

# Or if using host-based execution:
* * * * * cd ~/aviationwx && php fetch-weather-safe.php > /dev/null 2>&1
```

**Note**: The cron job runs `fetch-weather-safe.php` to refresh weather cache every 1 minute. This ensures:
- Weather data stays fresh even when no users are visiting
- Daily tracking (min/max temperature, peak gust) initializes promptly after midnight
- Prevents stale data issues after overnight periods with no traffic

**Environment Variable**: If your weather endpoint requires a specific URL, set `WEATHER_REFRESH_URL`:
```bash
# In crontab or environment
WEATHER_REFRESH_URL=http://localhost:8080
```

### 7. Deploy Application

#### 7.1 Start Application

```bash
# As aviationwx user, in ~/aviationwx directory

# Build and start containers
docker compose -f docker-compose.prod.yml up -d --build

# Verify containers are running
docker compose -f docker-compose.prod.yml ps

# Check logs
docker compose -f docker-compose.prod.yml logs -f
```

#### 7.2 Verify Deployment

```bash
# Test from server
curl -I http://localhost:8080
curl -I https://aviationwx.org

# Test airport subdomain
curl -I https://kspb.aviationwx.org  # Replace with your airport code
```

### 8. Set Up Automatic Certificate Renewal

Certbot certificates expire after 90 days. Set up automatic renewal:

```bash
# As root, test renewal
certbot renew --dry-run

# Set up automatic renewal (Certbot creates systemd timer automatically)
# Verify it's enabled:
systemctl status certbot.timer

# If not enabled, enable it:
systemctl enable certbot.timer
systemctl start certbot.timer
```

**Update certificates in application directory after renewal** (add to renewal hook):

```bash
# Create renewal hook script
sudo nano /etc/letsencrypt/renewal-hooks/deploy/update-app-certs.sh
```

Add this content:
```bash
#!/bin/bash
# Copy renewed certificates to application directory
cp /etc/letsencrypt/live/aviationwx.org/fullchain.pem /home/aviationwx/aviationwx/ssl/
cp /etc/letsencrypt/live/aviationwx.org/privkey.pem /home/aviationwx/aviationwx/ssl/
chown -R aviationwx:aviationwx /home/aviationwx/aviationwx/ssl/
chmod 644 /home/aviationwx/aviationwx/ssl/fullchain.pem
chmod 600 /home/aviationwx/aviationwx/ssl/privkey.pem

# Restart Nginx container to pick up new certificates
docker compose -f /home/aviationwx/aviationwx/docker-compose.prod.yml restart nginx
```

Make it executable:
```bash
sudo chmod +x /etc/letsencrypt/renewal-hooks/deploy/update-app-certs.sh
```

### 9. GitHub Actions Deployment (Optional but Recommended)

For automated deployments, set up GitHub Actions:

#### 9.1 Configure GitHub Secrets

In your GitHub repository (Settings → Secrets):

1. **SSH_PRIVATE_KEY**: Private SSH key for server access
   ```bash
   # On your local machine, generate SSH key pair
   ssh-keygen -t ed25519 -C "github-actions"
   
   # Copy private key to GitHub Secrets (SSH_PRIVATE_KEY)
   cat ~/.ssh/id_ed25519
   
   # Add public key to server
   ssh-copy-id -i ~/.ssh/id_ed25519.pub aviationwx@YOUR_SERVER_IP
   ```

2. **USER**: Server username (`aviationwx`)
3. **HOST**: Server IP address or hostname

#### 9.2 Push to Trigger Deployment

```bash
# Push to main branch
git push origin main

# GitHub Actions will automatically:
# - Run tests
# - Deploy to server via SSH
# - Set up directories and permissions
# - Start/restart containers
```

See [DOCKER_DEPLOYMENT.md](DOCKER_DEPLOYMENT.md) for detailed GitHub Actions setup.

### 10. Post-Deployment Verification

#### 10.1 Test Homepage

Visit in browser:
- https://aviationwx.org

#### 10.2 Test Airport Page

Visit airport subdomain:
- https://kspb.aviationwx.org (replace with your airport code)

#### 10.3 Check Services

```bash
# Check container status
docker compose -f docker-compose.prod.yml ps

# Check logs
docker compose -f docker-compose.prod.yml logs web
docker compose -f docker-compose.prod.yml logs nginx

# Test health endpoint
curl https://aviationwx.org/health.php

# Test diagnostics (if accessible)
curl https://aviationwx.org/diagnostics.php
```

#### 10.4 Verify Webcam Refresh

```bash
# Check cron job is running
crontab -l

# Manually test webcam fetcher
cd ~/aviationwx
docker compose -f docker-compose.prod.yml exec -T web php fetch-webcam-safe.php

# Check webcam images exist
ls -lh cache/webcams/
```

## Ongoing Maintenance

### Update Application

**Manual Update**:
```bash
cd ~/aviationwx
git pull origin main
docker compose -f docker-compose.prod.yml up -d --build
```

**Via GitHub Actions** (Automatic):
- Push to `main` branch triggers automatic deployment

### Monitor Logs

```bash
# Application logs
docker compose -f docker-compose.prod.yml logs -f web

# Nginx logs
docker compose -f docker-compose.prod.yml logs -f nginx

# User activity logs (user requests, responses, rate limiting)
tail -f /var/log/aviationwx/user.log

# Application/system logs (background operations, API errors, circuit breakers)
tail -f /var/log/aviationwx/app.log
```

### Backup Configuration

```bash
# Backup airports.json (contains API keys)
cp ~/aviationwx/airports.json ~/airports.json.backup

# Backup SSL certificates (already backed up by Let's Encrypt, but useful to have local copy)
cp -r ~/aviationwx/ssl ~/ssl.backup
```

### Troubleshooting

**Containers not starting**:
```bash
# Check logs
docker compose -f docker-compose.prod.yml logs

# Check container status
docker compose -f docker-compose.prod.yml ps

# Restart containers
docker compose -f docker-compose.prod.yml restart
```

**SSL certificate issues**:
```bash
# Check certificate status
sudo certbot certificates

# Test renewal
sudo certbot renew --dry-run

# Verify certificate location
ls -lh /etc/letsencrypt/live/aviationwx.org/
```

**DNS issues**:
```bash
# Test DNS resolution
dig aviationwx.org +short
dig kspb.aviationwx.org +short

# Check from different locations
curl -I https://aviationwx.org
```

## Security Best Practices

1. **Keep system updated**:
   ```bash
   apt update && apt upgrade -y
   ```

2. **Use SSH keys instead of passwords**
3. **Keep Docker and containers updated**
4. **Rotate API keys regularly**
5. **Monitor logs for suspicious activity**
6. **Use strong passwords for API keys and webcam credentials**
7. **Restrict file permissions**:
   ```bash
   chmod 600 ~/aviationwx/airports.json
   chmod 600 ~/aviationwx/ssl/privkey.pem
   ```

## Next Steps

- Configure monitoring and alerts
- Set up automated backups
- Add additional airports
- Customize webcam refresh intervals
- Configure log rotation (already set up via deployment script)

For local development, see [LOCAL_SETUP.md](LOCAL_SETUP.md).

