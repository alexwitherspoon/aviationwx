# Production Deployment Guide - Ubuntu LTS VPS

This guide covers deploying AviationWX.org from scratch on a fresh Ubuntu LTS VPS.

## Prerequisites

- Ubuntu 22.04 LTS (or 20.04 LTS) VPS
- Root or sudo access (only for initial setup: Docker installation, SSL certificates)
- Domain name with DNS control
- GitHub repository access
- Private secrets repository with GitHub Actions configured (for automated `airports.json` deployment)

## Minimal Host Customization

This deployment requires **minimal host customization**:
- ✅ **No log directory setup** - All logs go to Docker stdout/stderr
- ✅ **No cache directory setup** - Cache automatically created in `/tmp/aviationwx-cache`
- ✅ **No cron job setup** - Cron jobs run automatically inside container
- ✅ **No manual airports.json setup** - Deployed automatically via GitHub Actions
- ✅ **No sudo required for application** - Only needed for initial Docker/SSL setup

**One-time setup only**: After initial configuration, the application runs with minimal host dependencies.

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
apt install -y curl wget git nano ufw jq
```

#### 1.3 Create Application User

```bash
# Create dedicated user for the application
useradd -m -s /bin/bash aviationwx

# Add user to docker group (will be created when Docker is installed)
# Note: sudo access only needed for initial setup (Docker installation, SSL certificates)
# Application runs without sudo - minimal host customization required
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

#### 5.1 Clone Repository

```bash
# As aviationwx user
cd ~
git clone https://github.com/alexwitherspoon/aviationwx.git
cd aviationwx
```

#### 5.2 airports.json (Automated Deployment)

**`airports.json` is automatically deployed via GitHub Actions** from a private secrets repository.

- The file is deployed to `/home/aviationwx/airports.json` automatically
- No manual setup required - the GitHub Actions workflow handles deployment
- Updates are automatically deployed when the secrets repository is updated

**Note**: If you need to manually create `airports.json` for initial setup, you can:
```bash
# Copy from example (only if automated deployment not yet configured)
cp airports.json.example /home/aviationwx/airports.json
# Edit with your API keys and credentials
nano /home/aviationwx/airports.json
```

See [CONFIGURATION.md](docs/CONFIGURATION.md) for detailed configuration options.

#### 5.3 Create SSL Directory

```bash
# Create SSL certificate directory
mkdir -p ssl
```

### 6. Cron Jobs (Automatic)

**Cron jobs are automatically configured inside the Docker container** - no host-side setup required!

The Docker container includes:
- **Webcam refresh**: Runs every minute via cron inside the container
- **Weather refresh**: Runs every minute via cron inside the container

Both jobs run as the `www-data` user inside the container and are configured in the `crontab` file that's built into the Docker image.

**Benefits**:
- No host-side cron configuration needed
- Minimal host customization required
- Cron jobs automatically start when the container starts
- All jobs run in the same container environment

**Verification**: To verify cron is running inside the container:
```bash
docker compose -f docker-compose.prod.yml exec web ps aux | grep cron
```

**Note**: The cron jobs ensure:
- Weather data stays fresh even when no users are visiting
- Daily tracking (min/max temperature, peak gust) initializes promptly after midnight
- Prevents stale data issues after overnight periods with no traffic
- Webcam images are refreshed regularly

### 7. Deploy Application

#### 7.1 Initial Setup (One-Time)

**No manual cache or log directory setup required!**

The application automatically handles:
- **Cache directory**: Created in `/tmp/aviationwx-cache` (ephemeral, cleared on reboot)
- **Logging**: All logs go to Docker stdout/stderr (automatic rotation)
- **Cron jobs**: Run automatically inside container

#### 7.2 Start Application

```bash
# As aviationwx user, in ~/aviationwx directory

# Build and start containers
docker compose -f docker-compose.prod.yml up -d --build

# Verify containers are running
docker compose -f docker-compose.prod.yml ps

# Check logs (all logs captured by Docker automatically)
docker compose -f docker-compose.prod.yml logs -f
```

#### 7.3 Verify Deployment

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

See [DOCKER_DEPLOYMENT.md](docs/DOCKER_DEPLOYMENT.md) for detailed GitHub Actions setup.

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
# Check cron is running inside container
docker compose -f docker-compose.prod.yml exec web ps aux | grep cron

# Manually test webcam fetcher
cd ~/aviationwx
docker compose -f docker-compose.prod.yml exec -T web php fetch-webcam-safe.php

# Check webcam images exist
# Cache is in /tmp/aviationwx-cache (ephemeral, cleared on reboot)
ls -lh /tmp/aviationwx-cache/webcams/
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
# All logs are captured by Docker and can be viewed with docker compose logs
# Docker automatically handles log rotation (10MB files, 10 files = 100MB total)

# Application logs (PHP application logs)
docker compose -f docker-compose.prod.yml logs -f web

# Nginx logs (access and error logs)
docker compose -f docker-compose.prod.yml logs -f nginx

# View all logs together
docker compose -f docker-compose.prod.yml logs -f

# Filter logs by log type (log entries include 'log_type' field)
docker compose -f docker-compose.prod.yml logs -f web | grep '"log_type":"user"'
docker compose -f docker-compose.prod.yml logs -f web | grep '"log_type":"app"'

# View only errors/warnings (these go to stderr)
docker compose -f docker-compose.prod.yml logs -f web 2>&1 | grep -E '"level":"(error|warning)"'
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
   # airports.json permissions are managed by GitHub Actions deployment
   # SSL private key permissions
   chmod 600 ~/aviationwx/ssl/privkey.pem
   ```

## Next Steps

- Configure monitoring and alerts
- Set up automated backups
- Add additional airports
- Customize webcam refresh intervals
- Logging handled automatically by Docker (stdout/stderr, 10MB files, 10 files = 100MB total)

For local development, see [LOCAL_SETUP.md](docs/LOCAL_SETUP.md).

