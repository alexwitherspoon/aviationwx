# Docker Deployment to DigitalOcean

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

# Install Docker & Docker Compose
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

### 3. Clone and Configure Application

```bash
# Switch to application user
su - aviationwx

# Clone your repository
git clone https://github.com/alexwitherspoon/aviationwx.git
cd aviationwx

# Copy example config
cp airports.json.example airports.json
# Edit airports.json with your API keys

# Create SSL certificate directory
mkdir -p ssl
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

- Point the app at your host `airports.json`:
  ```bash
  export CONFIG_PATH=/home/aviationwx/aviationwx.org/airports.json
  ```
- Control refresh cadences (defaults are 60s):
  ```bash
  export WEBCAM_REFRESH_DEFAULT=60
  export WEATHER_REFRESH_DEFAULT=60
  ```
  You can also set per-airport values in `airports.json` with `webcam_refresh_seconds` and `weather_refresh_seconds`.

### 6.2 RTSP Snapshot Support

- ffmpeg is installed in the Docker image and used to capture a single high-quality frame from RTSP streams.
- Per-camera options in `airports.json`:
  ```json
  {
    "webcams": [
      {
        "name": "Runway Cam",
        "url": "rtsp://user:pass@camera-ip:554/stream",
        "rtsp_transport": "tcp",
        "refresh_seconds": 30
      }
    ]
  }
  ```
- Defaults: transport `tcp`, timeout 10s, retries 2.

---

## End-to-End Deployment on DigitalOcean (Detailed)

This section walks through a clean setup from an empty Droplet to automated deployments via GitHub Actions, with wildcard TLS and Cloudflare DNS.

### A. Prerequisites

- DigitalOcean account and a Ubuntu 22.04 Droplet IP ready
- Domain `aviationwx.org` managed in Cloudflare
- Two GitHub repositories:
  - App (public): `alexwitherspoon/aviationwx`
  - Secrets (private): `alexwitherspoon/aviationwx-secrets` (contains only `airports.json`)

### B. One-time Droplet Bootstrap

1) SSH to the droplet as root and run the bootstrap script (edit variables first):
   - Copy the script from your notes or request it from the maintainer
   - It creates user `aviationwx`, installs Docker + compose, configures firewall, cron, and directories

2) Reconnect as the deploy user:
```bash
ssh aviationwx@YOUR_DROPLET_IP
```

### C. SSH Deploy Keys and Clones

1) Place the two deploy keys (already created in GitHub deploy keys) on the droplet at:
   - `~/.ssh/deploy_key_aviationwxorg`
   - `~/.ssh/deploy_key_aviationwxorg_secrets`
   - Permissions: `chmod 600` on both

2) SSH config aliases:
```bash
cat > ~/.ssh/config << 'EOF'
Host github-aviationwx
    HostName github.com
    User git
    IdentityFile ~/.ssh/deploy_key_aviationwxorg
    IdentitiesOnly yes

Host github-airports
    HostName github.com
    User git
    IdentityFile ~/.ssh/deploy_key_aviationwxorg_secrets
    IdentitiesOnly yes
EOF
chmod 600 ~/.ssh/config
ssh-keyscan -H github.com >> ~/.ssh/known_hosts
chmod 644 ~/.ssh/known_hosts
```

3) Clone both repos:
```bash
cd ~
git clone git@github-aviationwx:alexwitherspoon/aviationwx.git
git clone git@github-airports:alexwitherspoon/aviationwx-secrets.git
```

4) Link secrets file:
```bash
ln -sf ~/aviationwx-secrets/airports.json ~/aviationwx/airports.json
chmod 600 ~/aviationwx/airports.json
```

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
- Deploys on push to `main`
- Deploys when a PR is merged into `main`
- Can be run manually via workflow_dispatch

### H. Cron for Webcam Refresh

Already installed by bootstrap (host-side cron):
```bash
*/1 * * * * curl -s http://127.0.0.1:8080/fetch-webcam-safe.php > /dev/null 2>&1
```

### I. Verification

```bash
docker compose -f ~/aviationwx/docker-compose.prod.yml ps
docker compose -f ~/aviationwx/docker-compose.prod.yml logs -f nginx web
```
Visit:
- `https://aviationwx.org/`
- `https://aviationwx.org/weather.php?airport=kspb`
- `https://kspb.aviationwx.org/`

### J. Troubleshooting Tips

- SSH alias resolution errors → run `git`/`ssh` as the `aviationwx` user where the SSH config is defined.
- 403 on TLS or domain mismatch → check cert files in `~/aviationwx/ssl` and Nginx logs.
- Missing webcam images → confirm cron is running and `cache/webcams/` is writable.

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

```bash
# Create update script
cat > update.sh << 'EOF'
#!/bin/bash
cd ~/aviationwx.org
git pull
docker compose -f docker-compose.prod.yml up -d --build
docker system prune -f
EOF

chmod +x update.sh

# Add to crontab (updates daily at 2 AM)
(crontab -l 2>/dev/null; echo "0 2 * * * /home/aviationwx/aviationwx.org/update.sh") | crontab -
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

