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
git clone https://github.com/yourusername/aviationwx.org.git
cd aviationwx.org

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

