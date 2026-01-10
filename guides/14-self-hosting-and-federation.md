# 14 - Self-Hosting & Federation (Advanced)

## Goal
Enable advanced users to self-host their own AviationWX installation while optionally participating in the larger AviationWX.org network through federation.

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

**Requirements:**
- Linux server (physical or cloud VM)
- Docker & Docker Compose installed
- Basic Linux/server administration skills
- Domain name (recommended)

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

Minimum requirements:
- 2 CPU cores
- 2GB RAM
- 20GB storage
- Ubuntu 22.04 LTS or similar
- Docker & Docker Compose installed

```bash
# Install Docker (Ubuntu example)
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh get-docker.sh
sudo usermod -aG docker $USER

# Verify
docker --version
docker compose version
```

### Step 2: Clone Repository

```bash
git clone https://github.com/alexwitherspoon/aviationwx.org.git
cd aviationwx.org
```

### Step 3: Configure Your Airport

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

### Step 4: Start the Platform

```bash
# Development mode (with logs)
make dev

# Or production mode
make up
```

Access your dashboard:
- **Single airport:** `http://localhost:8080` (auto-redirects to your airport)
- **API docs:** `http://localhost:8080/api/docs`
- **Status:** `http://localhost:8080/status.php`

### Step 5: Production Setup (Optional)

For production with SSL/domain:
1. Point your domain to your server
2. Set up nginx as reverse proxy with Let's Encrypt SSL
3. Update `base_domain` in config to your domain
4. Use `docker-compose.prod.yml` for production settings

See `docs/DEPLOYMENT.md` for production setup details.

---

## Option 2: Self-Hosting + Federation

**Use case:** Control your own infrastructure but share data with aviationwx.org for pilot discovery

### Prerequisites
- Self-hosting working (Option 1 above)
- Domain name pointing to your server
- SSL certificate (Let's Encrypt recommended)
- Public API enabled

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
- ✅ Your API is publicly accessible with SSL
- ✅ You understand federated data will appear on aviationwx.org
- ✅ You can revoke the API key anytime to stop sharing
- ✅ You'll maintain reasonable uptime (>95% recommended)

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

**Authentication:**
- Partner API keys identify and authorize requests
- Rate limiting prevents abuse
- Keys can be revoked instantly

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
  - DigitalOcean Droplet: $6/month (2GB RAM)
  - Linode Nanode: $5/month (1GB RAM, tight but works)
  - AWS t3.small: ~$15/month (2GB RAM, more robust)
  - Hetzner CX11: €4.5/month (~$5, EU-based)
  
- **Domain:** $1-2/month ($12-24/year)
- **Bandwidth:** Usually included (20-50GB transfer minimum)

**One-time:**
- Setup time: 2-8 hours (first time)
- SSL certificate: Free (Let's Encrypt)

**Total: ~$10-60/month** depending on server choice and traffic.

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
- ⚠️ Requires technical skills and server costs
- ⚠️ Ongoing maintenance responsibility

**Federation:**
- ✅ Best of both worlds: control + discovery
- ✅ Participate in network without vendor lock-in
- ✅ Pilots find your airport on main platform
- ⚠️ Requires public API and SSL setup

**Most airports:** Use [Guide 12](12-submit-an-airport-to-aviationwx.md) to join shared network directly.

**Advanced users:** Self-host for complete control, federate for pilot discovery.

**Need help deciding?** Email `contact@aviationwx.org` and we'll help you pick the best path for your airport.
