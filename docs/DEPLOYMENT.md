# Deployment Guide

Complete guide for deploying AviationWX.org to production. This guide covers everything from initial server setup to automated deployments.

## Table of Contents

1. [Prerequisites](#prerequisites)
2. [Initial Server Setup](#initial-server-setup)
3. [DNS Configuration](#dns-configuration)
4. [SSL Certificates](#ssl-certificates)
5. [Application Deployment](#application-deployment)
6. [GitHub Actions CI/CD](#github-actions-cicd-optional)
7. [Maintenance](#maintenance)
8. [Troubleshooting](#troubleshooting)

## Prerequisites

- **Ubuntu 22.04 LTS** (or 20.04 LTS) VPS
- **Root or sudo access** (only for initial setup: Docker installation, SSL certificates)
- **Domain name** with DNS control
- **GitHub repository access**
- **Private secrets repository** with GitHub Actions configured (for automated `airports.json` deployment)

## Minimal Host Customization

This deployment requires **minimal host customization**:
- ✅ **No log directory setup** - All logs go to Docker stdout/stderr
- ✅ **No cache directory setup** - Cache automatically created in `/tmp/aviationwx-cache`
- ✅ **No cron job setup** - Cron jobs run automatically inside container
- ✅ **No manual airports.json setup** - Deployed automatically via GitHub Actions
- ✅ **No sudo required for application** - Only needed for initial Docker/SSL setup

**One-time setup only**: After initial configuration, the application runs with minimal host dependencies.

## Initial Server Setup

### 1. Create and Access VPS

Create a new Ubuntu LTS droplet/VPS (minimum 1GB RAM, 1 vCPU). SSH into the server:

```bash
ssh root@YOUR_SERVER_IP
```

### 2. Update System

```bash
# Update package list and upgrade system
apt update && apt upgrade -y

# Install essential tools
apt install -y curl wget git nano ufw jq
```

### 3. Create Application User

```bash
# Create dedicated user for the application
useradd -m -s /bin/bash aviationwx

# Add user to sudo group (only needed for initial setup)
usermod -aG sudo aviationwx
```

### 4. Configure Firewall

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

### 5. Install Docker & Docker Compose

```bash
# Install Docker using official convenience script
curl -fsSL https://get.docker.com -o get-docker.sh
sh get-docker.sh

# Verify installation
docker --version

# Install Docker Compose plugin (Compose v2)
apt install -y docker-compose-plugin

# Verify installation
docker compose version

# Add aviationwx user to docker group
usermod -aG docker aviationwx

# Switch to application user to verify Docker access
su - aviationwx
docker ps  # Should work without sudo

# Switch back to root
exit
```

## DNS Configuration

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

### Cloudflare DNS (Recommended)

If using Cloudflare:

1. Log into Cloudflare Dashboard
2. Select your domain
3. Go to DNS → Records
4. Add two A records:
   - **Record 1**: `@` → YOUR_DROPLET_IP
   - **Record 2**: `*` → YOUR_DROPLET_IP
5. Choose proxy status:
   - **DNS only (gray cloud)**: Direct connection - better for dynamic content
   - **Proxied (orange cloud)**: Cloudflare CDN - adds caching and DDoS protection

## SSL Certificates

### Option A: Wildcard Certificate (Recommended)

For wildcard certificates (`*.aviationwx.org`), use DNS challenge:

#### 1. Install Certbot with Cloudflare Plugin

```bash
# Install Certbot and Cloudflare DNS plugin
apt install -y certbot python3-certbot-dns-cloudflare
```

#### 2. Create Cloudflare API Token

1. Log in to Cloudflare Dashboard
2. Go to "My Profile" → "API Tokens"
3. Create token with:
   - Permissions: `Zone → DNS → Edit` and `Zone → Zone → Read`
   - Resources: `Include → Specific zone → aviationwx.org`

#### 3. Store Token Securely

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

#### 4. Generate Wildcard Certificate

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

### Option B: HTTP Challenge (Simpler, but no wildcard)

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

### Copy Certificates to Application Directory

```bash
# Switch to application user
su - aviationwx

# Create application directory (if not already cloned)
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

### Set Up Automatic Certificate Renewal

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

#### Create Renewal Hook

Create a renewal hook to automatically copy renewed certificates and reload Nginx:

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

## Application Deployment

### 1. Clone Repository

```bash
# As aviationwx user
cd ~
git clone https://github.com/alexwitherspoon/aviationwx.git
cd aviationwx
```

### 2. Configure airports.json

**IMPORTANT**: `airports.json` is **NOT in the repository** - it only exists on the production host.

**Deployment**:
- `airports.json` is automatically deployed via GitHub Actions from a private secrets repository
- The file is deployed to `/home/aviationwx/airports.json` on the production host
- No manual setup required - the GitHub Actions workflow handles deployment
- Updates are automatically deployed when the secrets repository is updated

**CI vs CD Access**:
- **CI (GitHub Actions)**: ❌ Never has access - runs in GitHub's cloud, uses test fixtures
- **CD (Deployment)**: ✅ Has access - runs on production host where file exists

**Note**: If you need to manually create `airports.json` for initial setup:

```bash
# Copy from example (only if automated deployment not yet configured)
cp config/airports.json.example /home/aviationwx/airports.json
# Edit with your API keys and credentials
nano /home/aviationwx/airports.json
```

See [Configuration Guide](CONFIGURATION.md) for detailed configuration options.

### 3. Start Application

```bash
# As aviationwx user, in ~/aviationwx directory

# Build and start containers
docker compose -f docker/docker-compose.prod.yml up -d --build

# Verify containers are running
docker compose -f docker/docker-compose.prod.yml ps

# Check logs (all logs captured by Docker automatically)
docker compose -f docker/docker-compose.prod.yml logs -f
```

### 4. Verify Deployment

```bash
# Test from server
curl -I http://localhost:8080
curl -I https://aviationwx.org

# Test airport subdomain
curl -I https://kspb.aviationwx.org  # Replace with your airport code

# Test health endpoint
curl https://aviationwx.org/health.php

# Test diagnostics
curl https://aviationwx.org/diagnostics.php
```

### 5. Cron Jobs (Automatic)

**Cron jobs are automatically configured inside the Docker container** - no host-side setup required!

The Docker container includes:
- **Webcam refresh**: Runs every minute via cron inside the container
- **Weather refresh**: Runs every minute via cron inside the container

Both jobs run as the `www-data` user inside the container and are configured in the `crontab` file that's built into the Docker image.

**Verification**: To verify cron is running inside the container:

```bash
docker compose -f docker/docker-compose.prod.yml exec web ps aux | grep cron
```

## GitHub Actions CI/CD (Optional)

For automated deployments, set up GitHub Actions:

### 1. Configure GitHub Secrets

In your GitHub repository (Settings → Secrets and variables → Actions):

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

### 2. Push to Trigger Deployment

```bash
# Push to main branch
git push origin main

# GitHub Actions will automatically:
# - Run tests
# - Deploy to server via SSH
# - Set up directories and permissions
# - Start/restart containers
```

See `.github/workflows/deploy-docker.yml` for workflow details.

## Maintenance

### Update Application

**Manual Update**:
```bash
cd ~/aviationwx
git pull origin main
docker compose -f docker/docker-compose.prod.yml up -d --build
```

**Via GitHub Actions** (Automatic):
- Push to `main` branch triggers automatic deployment

### Monitor Logs

```bash
# All logs are captured by Docker and can be viewed with docker compose logs
# Docker automatically handles log rotation (10MB files, 10 files = 100MB total)

# Application logs (PHP application logs)
docker compose -f docker/docker-compose.prod.yml logs -f web

# Nginx logs (access and error logs)
docker compose -f docker/docker-compose.prod.yml logs -f nginx

# View all logs together
docker compose -f docker/docker-compose.prod.yml logs -f

# Filter logs by log type
docker compose -f docker/docker-compose.prod.yml logs -f web | grep '"log_type":"app"'

# View only errors/warnings
docker compose -f docker/docker-compose.prod.yml logs -f web 2>&1 | grep -E '"level":"(error|warning)"'
```

See [Operations Guide](OPERATIONS.md) for detailed logging and monitoring information.

### Backup Configuration

```bash
# Backup airports.json (contains API keys)
cp /home/aviationwx/airports.json ~/airports.json.backup

# Backup SSL certificates (already backed up by Let's Encrypt, but useful to have local copy)
cp -r ~/aviationwx/ssl ~/ssl.backup
```

## Troubleshooting

### Containers Not Starting

```bash
# Check logs
docker compose -f docker/docker-compose.prod.yml logs

# Check container status
docker compose -f docker/docker-compose.prod.yml ps

# Restart containers
docker compose -f docker/docker-compose.prod.yml restart
```

### SSL Certificate Issues

```bash
# Check certificate status
sudo certbot certificates

# Test renewal
sudo certbot renew --dry-run

# Verify certificate location
ls -lh /etc/letsencrypt/live/aviationwx.org/
```

### DNS Issues

```bash
# Test DNS resolution
dig aviationwx.org +short
dig kspb.aviationwx.org +short

# Check from different locations
curl -I https://aviationwx.org
```

### Missing Webcam Images

```bash
# Confirm cron is running inside container
docker compose -f docker/docker-compose.prod.yml exec web ps aux | grep cron

# Manually test webcam fetcher
docker compose -f docker/docker-compose.prod.yml exec -T web php scripts/fetch-webcam.php

# Check webcam images exist
# Cache is in /tmp/aviationwx-cache (ephemeral, cleared on reboot)
ls -lh /tmp/aviationwx-cache/webcams/
```

### View Logs

```bash
# Application logs
docker compose -f docker/docker-compose.prod.yml logs -f web

# Nginx logs
docker compose -f docker/docker-compose.prod.yml logs -f nginx

# All logs
docker compose -f docker/docker-compose.prod.yml logs -f
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
   # airports.json permissions are managed by GitHub Actions deployment
   # SSL private key permissions
   chmod 600 ~/aviationwx/ssl/privkey.pem
   ```

See [Security Guide](SECURITY.md) for detailed security information.

## Next Steps

- Configure monitoring and alerts
- Set up automated backups
- Add additional airports (see [Configuration Guide](CONFIGURATION.md))
- Customize webcam refresh intervals
- Set up VPN for remote sites (see [VPN Guide](VPN.md))

For local development, see [Local Development Setup](LOCAL_SETUP.md).

For VPN configuration, see [VPN Guide](VPN.md).
