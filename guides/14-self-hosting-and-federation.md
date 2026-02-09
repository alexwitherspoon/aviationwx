# 14 - Self-Hosting & Federation (Advanced)

## Goal
Enable advanced users to self-host their own complete AviationWX installation while optionally participating in the larger AviationWX.org pilot network through federation for discovery. Self-hosting provides complete control over your data and infrastructure while federation allows pilots to discover your airport through the shared network for better visibility and safety.

**This is an advanced option.** Most airports should use [Guide 12 - Submit an Airport](12-submit-an-airport-to-aviationwx.md) to join the shared network directly.

**Who this is for:**
- Technical users comfortable with Docker, Git, and server administration
- Airports requiring complete control over their data and infrastructure
- Organizations with specific privacy, security, or compliance requirements
- Users wanting to experiment with the platform before committing

**Need help?** Email `contact@aviationwx.org`

---

## What is Self-Hosting?

Self-hosting means running your own complete copy of AviationWX on your own server/infrastructure:
- ✅ **Full control** - You own the hardware, data, and code
- ✅ **Privacy** - Data never leaves your server
- ✅ **Independence** - Works even if aviationwx.org is offline
- ✅ **Customization** - Modify the code for your needs

**Infrastructure Requirements:**
- ✅ **Server** - Linux server (physical or cloud VM)
- ✅ **Public IP** - Static public IP address (for external access)
- ✅ **Domain Name** - DNS name pointing to your server (e.g., weather.myairport.com)
- ✅ **SSL Certificate** - HTTPS required for production (Let's Encrypt is free)
- ✅ **Bandwidth** - Sufficient for serving images and API requests
- ✅ **Security** - Firewall, updates, monitoring for public-facing services

**Technical Skills Required:**
- Linux server administration
- Docker & Docker Compose
- DNS configuration
- SSL/TLS certificate management
- Basic security hardening
- Log monitoring and troubleshooting

**Time Commitment:**
- Initial setup: 4-8 hours
- Ongoing maintenance: 1-4 hours/month
- Security updates: As needed (often automated)

---

## What is Federation?

Federation allows your self-hosted installation to optionally share data with the main AviationWX.org network:

```
Your Self-Hosted Install          AviationWX.org Network
┌─────────────────────┐          ┌───────────────────────┐
│ weather.myairport.  │          │  airports.aviation    │
│         com         │◄────────►│      wx.org           │
│                     │          │                       │
│ - Your cameras      │ Optional │ - Aggregates data     │
│ - Your weather      │  Share   │ - Pilot discovery     │
│ - Your dashboard    │   API    │ - Consistent UI       │
│ - Full control      │          │ - Network effects     │
└─────────────────────┘          └───────────────────────┘
```

**Federation Benefits:**
- ✅ **Discovery** - Pilots find your airport on aviationwx.org
- ✅ **Redundancy** - If main site is down, yours still works
- ✅ **Recognition** - Listed as a federated contributor
- ✅ **No lock-in** - Stop sharing anytime, no impact on your install

**You control:**
- ✅ What data to share (weather, webcams, or both)
- ✅ Rate limits for API access
- ✅ When to enable/disable federation
- ✅ Who can access your API (via keys)

---

## Decision Tree: Should You Self-Host?

### ✅ Good Reasons to Self-Host:

1. **You need complete data control**
   - Government/military airport with security requirements
   - Privacy regulations require data stay on-premises
   - Compliance mandates (ITAR, etc.)

2. **You're technically capable and want independence**
   - Comfortable with Docker, Git, server admin
   - Want to customize the platform
   - Prefer controlling your own infrastructure

3. **You want to experiment first**
   - Test the platform before committing cameras/weather
   - Evaluate features with demo data
   - Learn how it works under the hood

### ❌ Better to Use Shared Network (Guide 12):

1. **You just want a working dashboard**
   - No server administration experience
   - Don't want to manage infrastructure
   - Prefer "it just works" reliability

2. **Budget-conscious**
   - Running a server costs $5-50/month
   - Shared network is free for airports

3. **You want maximum visibility**
   - Shared network gets more pilot traffic
   - Better SEO and discoverability
   - Part of established brand

> **Most airports should use the shared network.** Self-hosting is powerful but requires ongoing technical maintenance.

---

## Option 1: Self-Hosting Only (No Federation)

**Use case:** Complete independence, no connection to aviationwx.org

### Step 1: Server Setup

**Minimum requirements:**
- **CPU:** 2 cores (4+ recommended for high traffic)
- **RAM:** 2GB minimum (4GB+ for multiple airports or high traffic)
- **Storage:** 20GB minimum (more if storing webcam history)
- **OS:** Ubuntu 22.04 LTS (or similar Linux distribution)
- **Network:** Static public IP address
- **Bandwidth:** 
  - Download: 10 Mbps minimum (for fetching from cameras/weather)
  - Upload: 25+ Mbps (for serving images to pilots)
  - Monthly transfer: 100GB minimum (varies with traffic)

**Infrastructure considerations:**

**Option A: Cloud VPS (Recommended for beginners)**
- **Pros:** Public IP included, high bandwidth, managed infrastructure
- **Cons:** Monthly cost, external dependency
- **Providers:** DigitalOcean, Linode, AWS, Hetzner
- **Static IP:** Automatically included
- **DNS:** Easy to configure (just point A record to IP)

**Option B: Home Server / Datacenter**
- **Pros:** One-time hardware cost, complete control
- **Cons:** Need static IP from ISP, port forwarding, higher complexity
- **Requirements:**
  - Static public IP (contact ISP, often $5-15/month extra)
  - Router with port forwarding (80/443)
  - UPS recommended (prevent corruption during power loss)
  - Reliable internet connection

**Option C: Raspberry Pi 5 (Supported, but with caveats)**

The platform was designed to run on Raspberry Pi 5, making it a viable low-cost option:

**Pros:**
- **Low cost:** ~$80 for 8GB model + accessories
- **Low power:** ~5-15W (vs 50-200W for servers)
- **Quiet operation:** Fanless or minimal fan
- **Compact:** Fits anywhere
- **Good for:** Single airport, low-moderate traffic

**Cons:**
- **Limited resources:** 8GB RAM maximum
- **SD card concerns:** Use high-quality SD or NVMe for reliability
- **ARM architecture:** Pre-built Docker images work, but less tested
- **Thermal throttling:** Can occur under heavy load (use case/heatsink)
- **Not for high traffic:** 100-500 pilots/day max

**Recommended Pi 5 specs:**
- **Model:** Raspberry Pi 5 8GB (4GB insufficient for production)
- **Storage:** NVMe SSD via M.2 HAT (preferred) OR high-endurance SD card
- **Cooling:** Active cooling case or fan
- **Power:** Official 27W USB-C power supply
- **Ethernet:** Use wired connection (not Wi-Fi) for reliability

**Setup notes:**
```bash
# Use Raspberry Pi OS (64-bit)
# Docker installation works the same
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh get-docker.sh

# May need to increase swap for image processing
sudo dphys-swapfile swapoff
sudo nano /etc/dphys-swapfile
# Set CONF_SWAPSIZE=2048
sudo dphys-swapfile setup
sudo dphys-swapfile swapon
```

**When to use Pi 5:**
- ✅ Single airport installation
- ✅ Budget-conscious deployment
- ✅ Low-moderate traffic (under 500 pilots/day)
- ✅ Power efficiency matters (remote sites, solar)
- ✅ Testing/development before committing to larger hardware

**When NOT to use Pi 5:**
- ❌ Multiple airports
- ❌ High traffic expectations (1000+ pilots/day)
- ❌ Storing extensive webcam history
- ❌ Mission-critical with no redundancy
- ❌ You need guaranteed performance under all conditions

> **Bottom line:** Pi 5 works well for single-airport installations with reasonable traffic. Use quality storage, ensure good cooling, and monitor performance. For high-traffic or multi-airport deployments, use a VPS or dedicated server.

⚠️ **Security Note:** Running a public-facing server requires ongoing security:
- Keep system updated (`unattended-upgrades` recommended)
- Configure firewall (ufw or iptables)
- Monitor logs for suspicious activity
- Use fail2ban to prevent brute force attacks
- Regular backups

Install Docker:
```bash
# Install Docker (Ubuntu example)
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh get-docker.sh
sudo usermod -aG docker $USER

# Verify
docker --version
docker compose version

# Enable automatic security updates (Ubuntu)
sudo apt install unattended-upgrades
sudo dpkg-reconfigure --priority=low unattended-upgrades
```

Configure firewall:
```bash
# Install and configure UFW (Uncomplicated Firewall)
sudo apt install ufw

# Allow SSH (adjust port if you changed it)
sudo ufw allow 22/tcp

# Allow HTTP/HTTPS
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp

# Enable firewall
sudo ufw enable
sudo ufw status
```

### Step 2: DNS & Domain Setup

**You need a domain name** for production use. Internal-only testing can use IP addresses, but production requires:
- ✅ Valid domain name (e.g., weather.myairport.com)
- ✅ DNS A record pointing to your server's public IP
- ✅ SSL certificate (HTTPS required for API and security)

**Option A: Subdomain from Airport Domain (Recommended)**
If your airport has a domain (e.g., myairport.com):
```
Create subdomain: weather.myairport.com
DNS A record: weather.myairport.com -> YOUR_SERVER_IP
```

**Option B: Dedicated Domain**
Register a new domain ($10-15/year):
```
Examples:
- k0s9weather.com
- weather-k0s9.org
- jeffersoncountyairportwx.com
```

**DNS Configuration:**
```
Type: A
Name: @ (or weather if using subdomain)
Value: YOUR_SERVER_PUBLIC_IP
TTL: 3600 (1 hour)

Optional AAAA record for IPv6:
Type: AAAA
Name: @ (or weather)
Value: YOUR_SERVER_IPv6 (if available)
TTL: 3600
```

**Verify DNS propagation:**
```bash
# Check DNS resolution
nslookup weather.myairport.com

# Or use dig
dig weather.myairport.com A

# Should return your server's public IP
```

DNS propagation typically takes 5-60 minutes, but can take up to 24 hours globally.

### Step 3: Clone Repository

```bash
git clone https://github.com/alexwitherspoon/aviationwx.org.git
cd aviationwx.org
```

### Step 4: Configure Your Airport

Create your configuration:
```bash
cp config/airports.json.example config/airports.json
nano config/airports.json  # or vim, etc.
```

**Single-Airport Mode Auto-Activates** when you have exactly 1 enabled airport:
- Homepage redirects to your airport dashboard
- Navigation simplified (no airport search, no browse map)
- Cleaner UI focused on your airport
- API and status pages still accessible

Example single-airport config:
```json
{
  "config": {
    "base_domain": "weather.myairport.com",
    "default_timezone": "America/Los_Angeles"
  },
  "airports": {
    "k0s9": {
      "name": "Jefferson County International",
      "enabled": true,
      "icao": "K0S9",
      "lat": 48.0538,
      "lon": -122.8106,
      "elevation_ft": 108,
      "timezone": "America/Los_Angeles",
      
      "weather_source": {
        "type": "tempest",
        "station_id": "YOUR_STATION_ID",
        "api_key": "YOUR_TEMPEST_API_KEY"
      },
      
      "webcams": [
        {
          "name": "Runway 27",
          "url": "rtsp://camera.local/stream1",
          "type": "rtsp",
          "refresh_seconds": 60
        }
      ]
    }
  }
}
```

See [Guide 12](12-submit-an-airport-to-aviationwx.md) for details on weather source and camera configuration.

### Step 5: SSL Certificate Setup

**HTTPS is required** for production, especially for federation. Use Let's Encrypt for free SSL certificates.

**Install Certbot:**
```bash
sudo apt update
sudo apt install certbot
```

**Generate SSL certificate:**
```bash
# Make sure ports 80/443 are open and DNS is working
sudo certbot certonly --standalone -d weather.myairport.com

# Follow prompts, provide email for renewal notifications
# Certificates stored in: /etc/letsencrypt/live/weather.myairport.com/
```

**Update Docker configuration:**

Create `docker/docker-compose.override.yml`:
```yaml
version: '3.8'

services:
  web:
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - /etc/letsencrypt:/etc/letsencrypt:ro
    environment:
      - VIRTUAL_HOST=weather.myairport.com
      - LETSENCRYPT_HOST=weather.myairport.com
```

**Configure nginx for SSL:**

Update `docker/nginx.conf` to include SSL configuration:
```nginx
server {
    listen 80;
    server_name weather.myairport.com;
    
    # Redirect HTTP to HTTPS
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name weather.myairport.com;
    
    # SSL certificates
    ssl_certificate /etc/letsencrypt/live/weather.myairport.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/weather.myairport.com/privkey.pem;
    
    # SSL security settings
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers on;
    ssl_ciphers ECDHE-RSA-AES256-GCM-SHA512:DHE-RSA-AES256-GCM-SHA512;
    
    # Your existing nginx config continues here...
    root /var/www/html;
    index index.php;
    
    # ... rest of config
}
```

**Auto-renewal:**
```bash
# Test renewal
sudo certbot renew --dry-run

# Certbot auto-renews via systemd timer
systemctl status certbot.timer
```

### Step 6: Start the Platform

```bash
# Development mode (with logs)
make dev

# Or production mode
make up
```

Access your dashboard:
- **HTTPS URL:** `https://weather.myairport.com` (production)
- **Single airport:** Auto-redirects to your airport dashboard
- **API docs:** `https://weather.myairport.com/api/docs`
- **Status:** `https://weather.myairport.com/status.php`

⚠️ **Security Checklist Before Going Public:**
- [ ] Firewall configured (only ports 22, 80, 443 open)
- [ ] SSL certificate installed and working
- [ ] All default passwords changed
- [ ] Automatic security updates enabled
- [ ] Fail2ban installed (optional but recommended)
- [ ] Server logs monitoring set up
- [ ] Backup strategy in place

### Step 7: Traffic & Performance Optimization

**Monitor traffic and performance:**
```bash
# Check current connections
netstat -an | grep :443 | wc -l

# Monitor bandwidth usage
sudo apt install vnstat
vnstat -l  # Live traffic

# Check Docker container resources
docker stats

# View access logs
docker compose logs web | grep "GET /api"
```

**Traffic estimation:**
- **Low traffic** (10-50 pilots/day): 1-5GB/month, 2GB RAM sufficient
- **Medium traffic** (100-500 pilots/day): 10-50GB/month, 4GB RAM recommended
- **High traffic** (1000+ pilots/day): 100-500GB/month, 8GB+ RAM, consider CDN

**Optimization for high traffic:**

1. **Enable CDN (CloudFlare free tier):**
   - Caches images globally
   - Reduces bandwidth costs
   - DDoS protection included
   - SSL included

2. **Optimize image sizes:**
   ```json
   {
     "webcam_variant_heights": [360, 720, 1080],
     "webcam_generate_webp": true  // 30% smaller than JPEG
   }
   ```

3. **Add Redis caching (optional):**
   ```yaml
   services:
     redis:
       image: redis:7-alpine
       restart: unless-stopped
   ```

4. **Rate limiting:**
   - Already built-in for API endpoints
   - Adjust in `config/airports.json` if needed

### Step 8: Production Setup & Hardening

**Additional security measures:**

```bash
# Install fail2ban (prevent brute force)
sudo apt install fail2ban
sudo systemctl enable fail2ban
sudo systemctl start fail2ban

# Configure SSH key-only authentication
sudo nano /etc/ssh/sshd_config
# Set: PasswordAuthentication no
# Set: PermitRootLogin no
sudo systemctl restart sshd

# Set up log monitoring
sudo apt install logwatch
sudo logwatch --detail Med --range today --mailto your@email.com
```

**Backup strategy:**
```bash
# Backup config and data
mkdir -p /backup
cp -r /path/to/aviationwx.org/config /backup/
cp -r /path/to/aviationwx.org/cache /backup/

# Automated daily backups (cron)
0 2 * * * rsync -av /path/to/aviationwx.org/config /backup/
```

**Monitoring recommendations:**
- **Uptime monitoring:** UptimeRobot (free), Pingdom, or StatusCake
- **Server monitoring:** Netdata, Prometheus + Grafana
- **Log aggregation:** Loki, or cloud provider's log service
- **Alerts:** Set up email/SMS for downtime or errors

See `docs/DEPLOYMENT.md` for additional production deployment details.

---

## Option 2: Self-Hosting + Federation

**Use case:** Control your own infrastructure but share data with aviationwx.org for pilot discovery

### Prerequisites
- Self-hosting working (Option 1 above) ✅
- **Domain name pointing to your server** ✅
- **SSL certificate installed and working** ✅
- **Public API enabled** (see below)
- **Server accessible from internet** (ports 80/443 open)
- **Sufficient bandwidth** for API requests (see traffic estimates above)

### Step 1: Enable Public API

Update your `config/airports.json`:
```json
{
  "config": {
    "public_api": {
      "enabled": true,
      "version": "1",
      "rate_limits": {
        "partner": {
          "requests_per_minute": 120,
          "requests_per_hour": 5000,
          "requests_per_day": 50000
        }
      }
    }
  }
}
```

Restart the platform:
```bash
make restart
```

### Step 2: Create Federation API Key

Add a partner API key for aviationwx.org:
```json
{
  "config": {
    "public_api": {
      "partner_keys": {
        "ak_live_federated_k0s9_UNIQUE123": {
          "name": "AviationWX.org Main Platform",
          "contact": "federated@aviationwx.org",
          "enabled": true,
          "tier": "partner",
          "created": "2026-01-09",
          "notes": "Federation with main platform"
        }
      }
    }
  }
}
```

**Generate a unique key:** Use a secure random generator, format: `ak_live_federated_{airport}_{random}`

### Step 3: Test Your API

Verify your API is accessible:
```bash
# Test weather endpoint
curl https://weather.myairport.com/api/v1/weather/k0s9

# Test with API key
curl -H "X-API-Key: ak_live_federated_k0s9_UNIQUE123" \
     https://weather.myairport.com/api/v1/weather/k0s9

# Test webcam endpoint
curl -H "X-API-Key: ak_live_federated_k0s9_UNIQUE123" \
     https://weather.myairport.com/api/v1/webcams/k0s9/0/latest \
     > test.jpg
```

### Step 4: Request Federation

Email `contact@aviationwx.org` with:
- **Subject:** "Federation Request - [Your Airport]"
- **Airport identifier:** (ICAO/FAA/IATA)
- **Your API base URL:** `https://weather.myairport.com`
- **Federation API key:** (the key you generated above)
- **What you want to share:** Weather only, webcams only, or both
- **Local steward contact:** (for technical issues)

Include confirmation that:
- ✅ Your API is publicly accessible with SSL (HTTPS)
- ✅ DNS resolves correctly to your server
- ✅ Firewall allows incoming traffic on ports 80/443
- ✅ You understand federated data will appear on aviationwx.org
- ✅ You can revoke the API key anytime to stop sharing
- ✅ You'll maintain reasonable uptime (>95% recommended)
- ✅ You have sufficient bandwidth for API requests
- ✅ Your server has security hardening in place

### Step 5: What Happens Next

After review, the main platform will add your airport as a federated source:

**On aviationwx.org:**
```json
{
  "airports": {
    "k0s9": {
      "name": "Jefferson County International",
      "federated": true,
      "federated_source": "https://weather.myairport.com",
      
      "weather_source": {
        "type": "aviationwx_api",
        "base_url": "https://weather.myairport.com",
        "api_key": "ak_live_federated_k0s9_UNIQUE123"
      },
      
      "webcams": [{
        "name": "Runway 27",
        "type": "aviationwx_api",
        "base_url": "https://weather.myairport.com",
        "api_key": "ak_live_federated_k0s9_UNIQUE123",
        "camera_index": 0
      }]
    }
  }
}
```

Your airport will appear on:
- `https://airports.aviationwx.org` (map view)
- `https://k0s9.aviationwx.org` (dashboard page)

**But your self-hosted install remains independent:**
- `https://weather.myairport.com` still works
- You control when to share data
- Revoke API key anytime to stop federation

---

## Federation Architecture Details

### How It Works

1. **Your server processes data locally:**
   - Fetches from your cameras/weather stations
   - Processes images, calculates derived fields
   - Caches locally for fast access
   - Exposes via public API

2. **Main platform fetches periodically:**
   - Calls your API endpoints (respecting rate limits)
   - Validates data age and quality
   - Falls back to other sources if your server is offline
   - Uses circuit breaker to prevent repeated failures

3. **Data flow is one-way:**
   - Main platform pulls from you
   - No writes to your system
   - No persistent connections
   - API key authenticates requests

### Security & Privacy

**Infrastructure Security:**
- **Firewall:** Only ports 22 (SSH), 80 (HTTP), 443 (HTTPS) open
- **SSL/TLS:** HTTPS required for all production traffic
- **Updates:** Automatic security updates enabled
- **Fail2ban:** Prevents brute force attacks (recommended)
- **SSH:** Key-only authentication, no password login
- **Monitoring:** Log monitoring and alerts configured

**Authentication:**
- Partner API keys identify and authorize requests
- Rate limiting prevents abuse
- Keys can be revoked instantly
- No shared secrets exposed in URLs

**Data Validation:**
- Main platform validates all responses
- Rejects stale data (>10 min old for weather)
- Sanity checks on values
- Fails gracefully (shows "---" not bad data)

**Circuit Breaker:**
- If your API fails repeatedly, requests pause
- Prevents cascade failures
- Auto-resumes when service recovers

**What's Shared:**
- Only what you explicitly expose via API
- Weather observations (if you enable weather endpoint)
- Processed webcam images (if you enable webcam endpoint)
- No raw data, credentials, or config files

**What's NOT Shared:**
- Camera stream URLs or credentials
- Weather station API keys
- Server logs or metrics
- User preferences or tracking data

---

## Maintenance & Operations

### Monitoring Your Install

Check health:
```bash
# System status
curl http://localhost:8080/status.php

# Weather data freshness
curl http://localhost:8080/api/v1/weather/k0s9

# Recent logs
docker compose logs --tail=100
```

### Common Issues

**Problem:** Webcams not updating
```bash
# Check fetch logs
docker compose logs web | grep webcam

# Manual fetch test
docker compose exec web php scripts/fetch-webcam.php k0s9 0
```

**Problem:** Weather data stale
```bash
# Check weather logs
docker compose logs web | grep weather

# Verify API key
curl "https://api.tempest.earth/...?token=YOUR_KEY"
```

**Problem:** High memory usage
```bash
# Restart containers
make restart

# Check image processing limits
# Edit config/airports.json: webcam_variant_heights
```

### Updates

```bash
# Pull latest code
git pull origin main

# Rebuild containers
make restart

# Check for breaking changes
cat CHANGELOG.md  # If we add one
```

### Stopping Federation

To stop sharing data with main platform:

**Option A - Disable API key:**
```json
{
  "partner_keys": {
    "ak_live_federated_k0s9_UNIQUE123": {
      "enabled": false  // Changed from true
    }
  }
}
```

**Option B - Remove key entirely:**
Delete the key from config, then email `contact@aviationwx.org` to remove from main platform.

**Option C - Disable API:**
```json
{
  "public_api": {
    "enabled": false
  }
}
```

Your local install continues working normally.

---

## Cost Estimates

### Self-Hosting Costs

**Monthly recurring:**
- **VPS/Cloud Server:** $5-50/month
  - DigitalOcean Droplet: $12/month (2GB RAM, 50GB transfer)
  - Linode Nanode: $5/month (1GB RAM, 1TB transfer, tight but works)
  - AWS t3.small: ~$15/month (2GB RAM, pay for bandwidth)
  - Hetzner CX21: €5.8/month (~$6.50, 3GB RAM, 20TB transfer)
  - Vultr: $6/month (1GB RAM, 1TB transfer)
  
- **Raspberry Pi 5 (one-time hardware, ongoing ISP):**
  - Pi 5 8GB: $80
  - Case with cooling: $15-25
  - NVMe HAT + SSD: $40-60 (or quality SD card: $20-30)
  - Power supply: $12
  - **Total hardware:** ~$150-180 one-time
  - **ISP static IP:** $5-15/month (if not included)
  - **Power:** ~$1-2/month (very low)
  
- **Domain:** $1-2/month ($12-24/year)
  - .com domains: ~$12-15/year
  - .org domains: ~$12-15/year
  - Subdomain from existing: Free
  
- **Bandwidth:** 
  - Usually included in VPS (50GB-1TB+)
  - Overages: $0.01-0.10 per GB
  - CloudFlare CDN: Free (unlimited bandwidth)

- **Static IP:** Included with most VPS
  - Home ISP static IP: $5-15/month extra (if available)

**One-time:**
- Setup time: 4-8 hours (first time)
- SSL certificate: Free (Let's Encrypt)
- Initial hardware (if self-hosting at home): $200-1000

**Total: ~$10-60/month** depending on:
- Server choice (VPS vs Pi 5 + ISP)
- Traffic volume (CDN helps)
- Domain (new vs subdomain)
- Location (home vs cloud)

**Pi 5 total cost:** ~$150-180 hardware + $5-15/month ISP static IP + $1-2/month domain = **~$8-20/month** after initial hardware investment. Most cost-effective for long-term use (breaks even vs VPS in 3-4 months).

### Shared Network (Guide 12)

**Monthly recurring:**
- **Cost:** $0 (free for airports)
- **Maintenance:** None (handled by platform)

**One-time:**
- Submit airport info: 30-60 minutes
- Equipment already set up per previous guides

---

## Getting Help

**Self-hosting questions:**
- Check `docs/LOCAL_SETUP.md` for development setup
- Check `docs/DEPLOYMENT.md` for production deployment
- GitHub Issues: https://github.com/alexwitherspoon/aviationwx.org/issues

**Federation questions:**
- Email: `contact@aviationwx.org`
- Include your airport ID and base URL
- Describe what's not working (with logs if possible)

**Contributing:**
- Found a bug? Open a GitHub issue
- Want a feature? Propose it on GitHub
- Fixed something? Submit a pull request

---

## Summary

**Self-Hosting:**
- ✅ Full control over data and infrastructure
- ✅ Works independently of main network
- ✅ Privacy and compliance control
- ⚠️ Requires technical skills (Linux, Docker, DNS, SSL)
- ⚠️ Server costs ($10-60/month typically)
- ⚠️ Ongoing maintenance responsibility
- ⚠️ Must handle security, backups, monitoring
- ⚠️ Need public IP, domain, bandwidth

**Federation:**
- ✅ Best of both worlds: control + discovery
- ✅ Participate in network without vendor lock-in
- ✅ Pilots find your airport on main platform
- ✅ Redundancy (both sites work independently)
- ⚠️ Requires public API and SSL setup
- ⚠️ Must maintain >95% uptime for good experience
- ⚠️ Additional security considerations (public-facing)

**Most airports:** Use [Guide 12](12-submit-an-airport-to-aviationwx.md) to join shared network directly.

**Advanced users:** Self-host for complete control, federate for pilot discovery.

**Infrastructure requirements matter:** If you don't have experience with public web hosting, DNS, SSL certificates, and server security, the shared network is a better choice.

**Need help deciding?** Email `contact@aviationwx.org` and we'll help you pick the best path for your airport.
