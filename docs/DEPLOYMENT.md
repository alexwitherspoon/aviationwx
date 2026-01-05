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
- ✅ **No log directory setup** - Logs written to `/var/log/aviationwx/` inside container with logrotate
- ✅ **No cache directory setup** - Cache automatically created in `/tmp/aviationwx-cache`
- ✅ **No cron job setup** - Cron jobs run automatically inside container
- ✅ **No manual airports.json setup** - Deployed automatically via GitHub Actions
- ✅ **No sudo required for application** - Only needed for initial Docker/SSL setup
- ✅ **No Docker cleanup setup** - Weekly cleanup cron auto-deployed via CD

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

**Note**: If using GitHub Actions for deployment, wildcard certificate generation is **automated**. The deployment workflow will automatically generate certificates if they don't exist (see [GitHub Actions CI/CD](#github-actions-cicd-optional) section). Manual setup is only needed if deploying without GitHub Actions.

### Automated Certificate Generation (GitHub Actions)

When using GitHub Actions for deployment:
- Certificates are **automatically generated** if they don't exist
- Requires `CLOUDFLARE_API_TOKEN` GitHub secret (see [GitHub Secrets](#1-configure-github-secrets))
- No manual certificate setup needed for first deployment
- Certificates are automatically renewed by certbot timer (systemd)

### Manual Certificate Setup (Without GitHub Actions)

If deploying manually or if automated generation fails, follow these steps:

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

# Check logs (view inside container)
docker compose -f docker/docker-compose.prod.yml exec web tail -f /var/log/aviationwx/app.log
```

### 4. Verify Deployment

```bash
# Test from server
# Note: Port 8080 is internal (Apache behind nginx proxy)
# External access should go through nginx on port 80/443
curl -I http://localhost:8080  # Internal Apache check
curl -I https://aviationwx.org  # External access via nginx

# Test airport subdomain
curl -I https://kspb.aviationwx.org  # Replace with your airport code

# Test health endpoint
curl https://aviationwx.org/health.php

# Test diagnostics
curl https://aviationwx.org/diagnostics.php
```

### 5. Scheduler Daemon (Automatic)

**The scheduler daemon is automatically started on container boot** - no host-side setup required!

The Docker container includes:
- **Scheduler daemon**: Starts automatically on container boot, handles all data refresh tasks
- **Scheduler health check**: Runs every minute via cron to ensure scheduler stays running
- **Push webcam processing**: Runs every minute via cron to process uploaded images

The scheduler supports:
- **Sub-minute refresh intervals**: Minimum 5 seconds, 1-second granularity
- **Configurable intervals**: Per-airport or global defaults via `airports.json`
- **Automatic config reload**: Configuration changes take effect without restart
- **Weather, webcam, and NOTAM updates**: All handled by the scheduler

**Verification**: To verify scheduler is running:

```bash
# Check scheduler process
docker compose -f docker/docker-compose.prod.yml exec web ps aux | grep scheduler

# Check scheduler lock file
docker compose -f docker/docker-compose.prod.yml exec web cat /tmp/scheduler.lock

# Check scheduler status via status page
curl https://aviationwx.org/status.php
```

## GitHub Actions CI/CD (Optional)

For automated deployments, set up GitHub Actions:

### 1. Configure GitHub Secrets

In your GitHub repository (Settings → Secrets and variables → Actions), add the following secrets:

#### Required Secrets

1. **`SSH_PRIVATE_KEY`** - Private SSH key for server access
   - **Purpose**: Authenticates GitHub Actions to your server for deployment
   - **How to create**:
     ```bash
     # On your local machine, generate SSH key pair
     ssh-keygen -t ed25519 -C "github-actions" -f ~/.ssh/github-actions
     
     # Copy private key content to GitHub Secrets (SSH_PRIVATE_KEY)
     cat ~/.ssh/github-actions
     
     # Add public key to server
     ssh-copy-id -i ~/.ssh/github-actions.pub aviationwx@YOUR_SERVER_IP
     ```
   - **Security**: Never commit this key to the repository. Store only in GitHub Secrets.

2. **`USER`** - Server username
   - **Value**: `aviationwx` (or your application user)
   - **Purpose**: Username for SSH connections during deployment

3. **`HOST`** - Server IP address or hostname
   - **Value**: Your server's IP address or hostname (e.g., `123.45.67.89` or `server.example.com`)
   - **Purpose**: Target server for deployment

4. **`CLOUDFLARE_API_TOKEN`** - Cloudflare API token for DNS management
   - **Purpose**: Automatically generates wildcard SSL certificates during deployment
   - **How to create**:
     1. Log in to [Cloudflare Dashboard](https://dash.cloudflare.com/)
     2. Go to "My Profile" → "API Tokens"
     3. Click "Create Token"
     4. Use "Edit zone DNS" template or create custom token with:
        - **Permissions**: 
          - `Zone` → `DNS` → `Edit`
          - `Zone` → `Zone` → `Read`
        - **Zone Resources**: 
          - `Include` → `Specific zone` → `aviationwx.org`
     5. Click "Continue to summary" → "Create Token"
     6. Copy the token immediately (it's only shown once)
     7. Paste into GitHub Secrets as `CLOUDFLARE_API_TOKEN`
   - **Security**: This token can modify DNS records. Keep it secure and rotate if compromised.
   - **Scope**: Should be scoped only to your domain zone

5. **`LETSENCRYPT_EMAIL`** - Email address for Let's Encrypt certificate generation
   - **Purpose**: Required email address for Let's Encrypt certificate generation and expiration notifications
   - **Value**: Your email address (e.g., `your-email@example.com`)
   - **How to set**: Add as a GitHub Secret with your preferred email address
   - **Note**: 
     - This email is used by Let's Encrypt for certificate expiration warnings
     - Let's Encrypt requires an email address when generating certificates
     - Use an email address you monitor regularly to receive renewal reminders
     - The email is not used for account management, only notifications

### How to Add Secrets to GitHub

1. Go to your repository on GitHub
2. Navigate to **Settings** → **Secrets and variables** → **Actions**
3. Click **"New repository secret"**
4. Enter the secret name (e.g., `CLOUDFLARE_API_TOKEN`)
5. Paste the secret value
6. Click **"Add secret"**
7. Repeat for all required secrets

### Secret Security Best Practices

- ✅ **DO**: Store all secrets in GitHub Secrets (encrypted at rest)
- ✅ **DO**: Use minimal permissions for Cloudflare API token (zone-scoped only)
- ✅ **DO**: Rotate secrets periodically or if compromised
- ✅ **DO**: Use different SSH keys for different purposes
- ❌ **DON'T**: Commit secrets to the repository (even in `.gitignore` files)
- ❌ **DON'T**: Share secrets in chat, email, or documentation
- ❌ **DON'T**: Use overly broad Cloudflare API tokens (use zone-scoped tokens)

### Verifying Secrets Are Set

After adding secrets, you can verify they're configured by checking the deployment workflow logs. The workflow will:
- Use `SSH_PRIVATE_KEY` to connect to the server
- Use `USER` and `HOST` to identify the deployment target
- Use `CLOUDFLARE_API_TOKEN` to generate certificates (if needed)
- Use `LETSENCRYPT_EMAIL` for certificate notifications (optional)

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

Logs are written to `/var/log/aviationwx/` inside the container. Logrotate handles rotation (1 rotated file, 100MB max per file).

```bash
# Application logs (PHP - JSONL format)
docker compose -f docker/docker-compose.prod.yml exec web tail -f /var/log/aviationwx/app.log

# Apache access logs
docker compose -f docker/docker-compose.prod.yml exec web tail -f /var/log/aviationwx/apache-access.log

# Apache error logs
docker compose -f docker/docker-compose.prod.yml exec web tail -f /var/log/aviationwx/apache-error.log

# Filter by log type (using jq)
docker compose -f docker/docker-compose.prod.yml exec web cat /var/log/aviationwx/app.log | jq 'select(.log_type == "app")'

# View only errors/warnings
docker compose -f docker/docker-compose.prod.yml exec web cat /var/log/aviationwx/app.log | jq 'select(.level == "error" or .level == "warning")'
```

See [Operations Guide](OPERATIONS.md) for detailed logging and monitoring information.

### Backup Configuration

```bash
# Backup airports.json (contains API keys)
cp /home/aviationwx/airports.json ~/airports.json.backup

# Backup SSL certificates (already backed up by Let's Encrypt, but useful to have local copy)
cp -r ~/aviationwx/ssl ~/ssl.backup
```

### Docker Cleanup

Docker images and build cache are cleaned up using a "belt and suspenders" approach:

#### Automatic Cleanup (CD Workflow - "Belt")
During each deployment, if disk usage exceeds 40%, the CD workflow runs `scripts/deploy-docker-cleanup.sh` which:
- Cleans build cache (keeps last 24 hours)
- Removes dangling images
- Removes unused images older than 7 days
- Removes unused volumes and networks

#### Weekly Cleanup (Host Cron - "Suspenders")
A weekly host-level cron job runs every Sunday at 2:00 AM UTC to aggressively clean ALL unused Docker resources:

**Files deployed by CD:**
- `/etc/cron.d/aviationwx-docker-cleanup` - Weekly cron schedule
- `/etc/logrotate.d/docker-cleanup-weekly` - Log rotation (4 week retention)

**Cleanup script:**
- `/home/aviationwx/aviationwx/scripts/docker-cleanup-weekly.sh`

**Log file:**
- `/var/log/docker-cleanup-weekly.log`

**Manual execution:**
```bash
# Run cleanup manually
sudo /home/aviationwx/aviationwx/scripts/docker-cleanup-weekly.sh

# View cleanup logs
cat /var/log/docker-cleanup-weekly.log

# Verify cron job is installed
cat /etc/cron.d/aviationwx-docker-cleanup

# Check next scheduled run
grep docker /etc/cron.d/* 2>/dev/null
```

**Note**: The weekly cleanup uses `docker system prune -af --volumes` which removes ALL unused resources, not just those older than a certain age. This ensures disk space is reclaimed even during periods of low deployment activity.

## Troubleshooting

### Containers Not Starting

```bash
# Check container status
docker compose -f docker/docker-compose.prod.yml ps

# Check container logs (startup issues)
docker compose -f docker/docker-compose.prod.yml logs web

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
ls -lh /tmp/aviationwx-cache/webcams/*/
```

### View Logs

```bash
# Application logs (PHP - JSONL format)
docker compose -f docker/docker-compose.prod.yml exec web tail -f /var/log/aviationwx/app.log

# Apache access logs
docker compose -f docker/docker-compose.prod.yml exec web tail -f /var/log/aviationwx/apache-access.log

# All logs
docker compose -f docker/docker-compose.prod.yml exec web tail -f /var/log/aviationwx/*.log
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

For local development, see [Local Development Setup](LOCAL_SETUP.md).
