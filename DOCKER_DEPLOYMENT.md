# Docker Deployment Guide

Complete guide for deploying AviationWX.org using Docker and Docker Compose.

## Quick Links

- **Fresh Ubuntu VPS Setup**: See [PRODUCTION_DEPLOYMENT.md](PRODUCTION_DEPLOYMENT.md) for step-by-step from scratch
- **Local Development**: See [LOCAL_SETUP.md](LOCAL_SETUP.md) for local setup
- **This Guide**: Detailed deployment process, GitHub Actions setup, and advanced configuration

## Overview

This guide covers:
1. Docker-based deployment architecture
2. GitHub Actions CI/CD setup
3. SSL certificate management
4. Production configuration
5. Maintenance and updates

## Quick Start

### 1. Create DigitalOcean Droplet

- **Image**: Ubuntu 22.04 LTS
- **Plan**: Basic - $6/month (1GB RAM, 1 vCPU)
- **Region**: Choose closest to you
- **Authentication**: SSH Key (recommended) or Password

### 2. Initial Server Setup

```bash
# SSH into your droplet
ssh root@YOUR_DROPLET_IP

# Update system
apt update && apt upgrade -y

# Install Docker & Docker Compose (Compose v2)
curl -fsSL https://get.docker.com -o get-docker.sh
sh get-docker.sh
apt install docker-compose-plugin -y

# Verify installation
docker --version
docker compose version

# Create application user (optional but recommended)
useradd -m -s /bin/bash aviationwx
usermod -aG docker aviationwx
```

### 3. Clone Application

```bash
# Switch to application user
su - aviationwx

# Clone your repository
git clone https://github.com/alexwitherspoon/aviationwx.git
cd aviationwx

# Note: airports.json is automatically deployed to /home/aviationwx/airports.json
# by the secrets repo GitHub Actions workflow - no manual setup needed

# Create SSL certificate directory
mkdir -p ssl

# No cache or log directory setup needed:
# - Cache: Automatically created in /tmp/aviationwx-cache (ephemeral)
# - Logs: All logs go to Docker stdout/stderr (automatic rotation)
```

### 4. Set Up SSL with Certbot

```bash
# Install Certbot
sudo apt install certbot python3-certbot-nginx -y

# Get SSL certificate
sudo certbot certonly --standalone -d aviationwx.org -d '*.aviationwx.org'
# This will create files in /etc/letsencrypt/live/aviationwx.org/

# Copy certificates to application directory
sudo cp /etc/letsencrypt/live/aviationwx.org/fullchain.pem ./ssl/
sudo cp /etc/letsencrypt/live/aviationwx.org/privkey.pem ./ssl/
sudo chown -R $USER:$USER ./ssl
```

### 5. Configure Nginx for SSL

```bash
# Edit nginx.conf to enable SSL
# Add SSL configuration (see nginx-ssl.conf example)
```

### 6. Start the Application

```bash
# Build and start
docker compose -f docker-compose.prod.yml up -d --build

# Check logs
docker compose logs -f

# Verify it's running
curl http://localhost:8080
```

### 6.1 Configure App Settings

- `airports.json` is automatically mounted from `/home/aviationwx/airports.json` (deployed by secrets repo)
- Control refresh cadences via environment variables (defaults are 60s):
  ```bash
  export WEBCAM_REFRESH_DEFAULT=60
  export WEATHER_REFRESH_DEFAULT=60
  ```
  You can also set per-airport values in `airports.json` with `webcam_refresh_seconds` and `weather_refresh_seconds`.

### 6.2 RTSP/RTSPS Snapshot Support

- ffmpeg is installed in the Docker image and used to capture a single high-quality frame from RTSP/RTSPS streams.
- **RTSPS (Secure RTSP over TLS) is fully supported** for encrypted camera feeds.
- Per-camera options in `airports.json`:
  
  **Standard RTSP:**
  ```json
  {
    "webcams": [
      {
        "name": "Runway Cam",
        "url": "rtsp://user:pass@camera-ip:554/stream",
        "type": "rtsp",
        "rtsp_transport": "tcp",
        "refresh_seconds": 30,
        "position": "north",
        "partner_name": "Partner Name",
        "partner_link": "https://partner.com"
      }
    ]
  }
  ```
  
  **RTSPS (Secure):**
  ```json
  {
    "webcams": [
      {
        "name": "Secure Runway Cam",
        "url": "rtsps://camera.example.com:7447/stream?enableSrtp",
        "type": "rtsp",
        "rtsp_transport": "tcp",
        "refresh_seconds": 60,
        "position": "south",
        "partner_name": "Partner Name",
        "partner_link": "https://partner.com"
      }
    ]
  }
  ```
  
- **Defaults**: transport `tcp` (recommended), timeout 10s, retries 2. (ffmpeg 5.0+ uses `-timeout` not `-stimeout`)
- **Important**: Always set `"type": "rtsp"` explicitly for RTSPS URLs.
- See [CONFIGURATION.md](CONFIGURATION.md) for complete webcam configuration examples.

---

## End-to-End Deployment on DigitalOcean (Detailed)

This section walks through a clean setup from an empty Droplet to automated deployments via GitHub Actions, with wildcard TLS and Cloudflare DNS.

### A. Prerequisites

- DigitalOcean account and a Ubuntu 22.04 Droplet IP ready
- Domain `aviationwx.org` managed in Cloudflare
- Two GitHub repositories:
  - App (public): `alexwitherspoon/aviationwx` - cloned to droplet
  - Secrets (private): `alexwitherspoon/aviationwx-secrets` - deploys `airports.json` directly to `/home/aviationwx/airports.json` via GitHub Actions

### B. One-time Droplet Bootstrap

1) SSH to the droplet as root and run the bootstrap script (edit variables first):
   - Copy the script from your notes or request it from the maintainer
   - It creates user `aviationwx`, installs Docker + compose, configures firewall, cron, and directories

2) Reconnect as the deploy user:
```bash
ssh aviationwx@YOUR_DROPLET_IP
```

### C. SSH Deploy Keys and App Repo Clone

1) Place the deploy key (already created in GitHub deploy keys) on the droplet at:
   - `~/.ssh/deploy_key_aviationwxorg`
   - Permissions: `chmod 600`

2) SSH config alias:
```bash
cat > ~/.ssh/config << 'EOF'
Host github-aviationwx
    HostName github.com
    User git
    IdentityFile ~/.ssh/deploy_key_aviationwxorg
    IdentitiesOnly yes
EOF
chmod 600 ~/.ssh/config
ssh-keyscan -H github.com >> ~/.ssh/known_hosts
chmod 644 ~/.ssh/known_hosts
```

3) Clone the app repo:
```bash
cd ~
git clone git@github-aviationwx:alexwitherspoon/aviationwx.git
```

4) **Note on airports.json**: The secrets repo (`aviationwx-secrets`) deploys `airports.json` directly to `/home/aviationwx/airports.json` via GitHub Actions. The file must be:
   - Owner: `aviationwx:aviationwx`
   - Permissions: `600`
   - Location: `/home/aviationwx/airports.json`

   The docker-compose configuration mounts this file into the container automatically.

### D. Cloudflare DNS

Add these A records in Cloudflare for the `aviationwx.org` zone:
- `@` → YOUR_DROPLET_IP
- `*` → YOUR_DROPLET_IP

You may start with DNS only (gray cloud) until TLS is working; then you can enable proxy (orange cloud) if desired.

### E. TLS with Certbot (Wildcard via DNS-01)

1) Install Certbot + plugin:
```bash
sudo apt update && sudo apt install -y certbot python3-certbot-dns-cloudflare
```

2) Create Cloudflare API Token (scoped to the zone):
- Permissions: Zone → DNS → Edit; Zone → Zone → Read
- Resources: Include → Specific zone → `aviationwx.org`

3) Store token on droplet:
```bash
mkdir -p ~/.secrets
printf 'dns_cloudflare_api_token = %s\n' 'YOUR_CF_API_TOKEN' > ~/.secrets/cloudflare.ini
chmod 600 ~/.secrets/cloudflare.ini
```

4) Issue wildcard certs:
```bash
sudo certbot certonly \
  --dns-cloudflare \
  --dns-cloudflare-credentials ~/.secrets/cloudflare.ini \
  -d aviationwx.org -d '*.aviationwx.org' \
  --non-interactive --agree-tos -m you@example.com
```

5) Copy certs to app ssl directory:
```bash
mkdir -p ~/aviationwx/ssl
sudo cp /etc/letsencrypt/live/aviationwx.org/fullchain.pem ~/aviationwx/ssl/
sudo cp /etc/letsencrypt/live/aviationwx.org/privkey.pem   ~/aviationwx/ssl/
sudo chown -R aviationwx:aviationwx ~/aviationwx/ssl
```

6) Optional: auto-reload Nginx on renew (deploy hook):
```bash
sudo mkdir -p /usr/local/lib/aviationwx
sudo tee /usr/local/lib/aviationwx/renew-hook.sh >/dev/null <<'EOS'
#!/usr/bin/env bash
set -euo pipefail
APP_HOME="/home/aviationwx/aviationwx"
cp /etc/letsencrypt/live/aviationwx.org/fullchain.pem "$APP_HOME/ssl/fullchain.pem"
cp /etc/letsencrypt/live/aviationwx.org/privkey.pem   "$APP_HOME/ssl/privkey.pem"
chown -R aviationwx:aviationwx "$APP_HOME/ssl"
cd "$APP_HOME"
docker compose -f docker-compose.prod.yml exec -T nginx nginx -s reload || \
docker compose -f docker-compose.prod.yml up -d nginx
EOS
sudo chmod +x /usr/local/lib/aviationwx/renew-hook.sh
sudo bash -c 'echo deploy-hook = /usr/local/lib/aviationwx/renew-hook.sh >> /etc/letsencrypt/cli.ini'
sudo certbot renew --dry-run
```

### F. Start the Stack

```bash
cd ~/aviationwx
docker compose -f docker-compose.prod.yml up -d --build
```

### G. GitHub Actions Deploy

1) Create a CI SSH key for Actions (on your machine):
```bash
ssh-keygen -t ed25519 -f aviationwx_actions -C "gha@aviationwx"
```
2) Add the public key to the droplet user’s `~/.ssh/authorized_keys`.

3) In GitHub repo `alexwitherspoon/aviationwx` → Settings → Secrets and variables → Actions:
- `SSH_PRIVATE_KEY`: contents of `aviationwx_actions` (private key)
- `HOST`: droplet IP
- `USER`: `aviationwx`

4) Workflow behavior (`.github/workflows/deploy-docker.yml`):
- Deploys on push to `main` (not on PR creation)
- Can be run manually via workflow_dispatch
- Post-deploy health checks: waits for containers, checks via `docker compose exec` inside the `web` container, then (optionally) host port

### H. Cron Jobs (Automatic)

**Cron jobs run automatically inside the Docker container** - no host-side configuration needed!

The Docker image includes:
- **Webcam refresh**: Runs every minute via cron inside the container
- **Weather refresh**: Runs every minute via cron inside the container

Both jobs are configured in the `crontab` file that's built into the Docker image and start automatically when the container starts.

**Note**: If you previously had host-side cron jobs configured, you can remove them as they're no longer needed.

### I. Verification

```bash
docker compose -f ~/aviationwx/docker-compose.prod.yml ps
docker compose -f ~/aviationwx/docker-compose.prod.yml logs -f nginx web
```
Visit:
- `https://aviationwx.org/`
- `https://aviationwx.org/weather.php?airport=kspb`
- `https://kspb.aviationwx.org/`

Diagnostics:
- `https://aviationwx.org/diagnostics.php` (shows HTTPS, cache dir status, ffmpeg, API checks)
- `https://aviationwx.org/clear-cache.php` (manually clears config cache)

### J. Troubleshooting Tips

- SSH alias resolution errors → run `git`/`ssh` as the `aviationwx` user where the SSH config is defined.
- 403 on TLS or domain mismatch → check cert files in `~/aviationwx/ssl` and Nginx logs (`docker compose logs nginx`).
- Missing webcam images → confirm cron is running inside container (`docker compose exec web ps aux | grep cron`) and `/tmp/aviationwx-cache/webcams/` is writable (cache is ephemeral, cleared on reboot).
- View logs → use `docker compose logs -f web` for application logs or `docker compose logs -f nginx` for Nginx logs (all logs captured by Docker automatically).

### 7. Configure DNS in Cloudflare

1. **Log into Cloudflare Dashboard**
2. **Select your `aviationwx.org` domain**
3. **Go to DNS → Records**
4. **Add two A records**:
   
   **Record 1** (Main domain):
   - **Type**: A
   - **Name**: `@` (or `aviationwx.org`)
   - **IPv4 address**: `YOUR_DROPLET_IP`
   - **Proxy status**: DNS only (gray cloud) or Proxied (orange cloud)
   - Click **Save**
   
   **Record 2** (Wildcard subdomain):
   - **Type**: A
   - **Name**: `*` (this handles ALL subdomains)
   - **IPv4 address**: `YOUR_DROPLET_IP`
   - **Proxy status**: DNS only (gray cloud) or Proxied (orange cloud)
   - Click **Save**

**That's it!** 🎉

With Cloudflare, DNS propagation is nearly instantaneous (1-5 minutes).

**Note**: Cloudflare proxy (orange cloud) adds CDN caching and DDoS protection. For this application, you can use either:
- **DNS only (gray cloud)**: Direct connection to your droplet - better for dynamic content
- **Proxied (orange cloud)**: Cloudflare CDN - better for static assets, adds caching layer

### 8. Set Up Automatic Updates (Optional)

Note: With GitHub Actions deployment, manual update scripts are typically not needed. The workflow automatically deploys on push to `main`.

If you want to manually pull updates locally:
```bash
cd ~/aviationwx
git pull
docker compose -f docker-compose.prod.yml up -d --build
docker system prune -f
```

## Deployment via GitHub Actions

See `.github/workflows/deploy-docker.yml` for automated deployment.

**Requirements**:
- Add `SSH_PRIVATE_KEY` secret to GitHub
- Add `HOST` secret (your droplet IP)

## Local Development

```bash
# Start local development server
docker compose up

# Access at http://localhost:8080
```

## Troubleshooting

### Check container status
```bash
docker ps
docker compose logs web
```

### Restart containers
```bash
docker compose restart
```

### View logs
```bash
docker compose logs -f web
```

### SSH into container
```bash
docker exec -it aviationwx bash
```

### Update code
```bash
cd ~/aviationwx
git pull
docker compose -f docker-compose.prod.yml up -d --build
```

## Cost Estimate

**DigitalOcean**:
- Droplet: $6/month (1GB RAM)
- **Total**: ~$6-12/month with domain

**Bluehost** (Current):
- Shared hosting: $15-25/month
- Limited control
- Subdomain management issues

**Savings**: ~$9-19/month

## Advantages of Docker Deployment

✅ Complete control over environment  
✅ Easy to scale up/down  
✅ Consistent local/production environments  
✅ Can install any tool (ffmpeg, custom extensions)  
✅ Modern deployment pipeline  
✅ Easy subdomain handling (just DNS)  
✅ Container logs and monitoring  
✅ Portable to any cloud provider  

