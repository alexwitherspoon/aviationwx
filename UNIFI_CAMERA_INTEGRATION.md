# UniFi Camera Integration Guide

This document discusses options for connecting remote UniFi cameras to the AviationWX droplet.

## Current Capabilities

✅ **RTSP Support**: AviationWX already supports RTSP/RTSPS streams via ffmpeg
✅ **Authentication**: Supports username/password in RTSP URLs
✅ **Secure Streams**: Full RTSPS (TLS) support
✅ **Network Configuration**: Per-camera timeout and transport settings

The main challenge is **network connectivity** between the remote UniFi installation and the DigitalOcean droplet.

---

## Option 1: WireGuard VPN (Recommended) ⭐

**Difficulty**: Medium  
**Cost**: Free (self-hosted)  
**Security**: Excellent  
**Setup Time**: 1-2 hours

### Overview
Create a site-to-site WireGuard VPN between the UniFi installation and the droplet. This is the most secure and flexible option.

### Pros
- ✅ High security (encrypted, modern VPN protocol)
- ✅ Low latency overhead
- ✅ Minimal bandwidth overhead
- ✅ Works behind NAT/firewalls
- ✅ Easy to manage
- ✅ Stable connections
- ✅ Supports multiple cameras through single tunnel

### Cons
- ⚠️ Requires network access at UniFi site to configure
- ⚠️ Requires static IP or dynamic DNS at UniFi site (or use NAT traversal)
- ⚠️ Initial setup requires some networking knowledge

### Setup Steps

1. **Install WireGuard on Droplet**:
   ```bash
   # On DigitalOcean droplet
   sudo apt update && sudo apt install wireguard wireguard-tools
   wg genkey | tee /etc/wireguard/private.key | wg pubkey > /etc/wireguard/public.key
   ```

2. **Install WireGuard at UniFi Site** (on UniFi Cloud Gateway, UDM, or a separate server):
   ```bash
   # Install WireGuard (on UDM/UDM-Pro, may need to use UniFi OS shell)
   # Or use a separate Linux server/VM at the UniFi site
   sudo apt install wireguard
   ```

3. **Configure Site-to-Site Tunnel**:
   - Configure peers on both sides
   - Set up routing for camera IP ranges
   - Use persistent keepalive for NAT traversal

4. **Firewall Rules**:
   - Allow WireGuard UDP port (51820 by default)
   - Allow RTSP traffic (port 554 or custom) over VPN
   - Restrict access to only necessary ports

5. **Configure AviationWX**:
   ```json
   {
     "webcams": [
       {
         "name": "UniFi Camera - Main View",
         "url": "rtsp://unifi-camera-ip:554/stream",
         "type": "rtsp",
         "rtsp_transport": "tcp",
         "refresh_seconds": 60,
         "position": "north",
         "partner_name": "Airport Name",
         "partner_link": "https://example.com"
       }
     ]
   }
   ```

### Estimated Complexity
- Network configuration: Medium
- Ongoing maintenance: Low
- Reliability: High

---

## Option 2: UniFi Cloud Connect / Teleport

**Difficulty**: Easy  
**Cost**: Free (built into UniFi OS)  
**Security**: Good (HTTPS-based)  
**Setup Time**: 30 minutes

### Overview
Use UniFi's built-in remote access feature (formerly Teleport, now Cloud Connect) to access the UniFi Protect system.

### Pros
- ✅ Very easy setup (built into UniFi OS)
- ✅ Works behind any firewall/NAT
- ✅ No port forwarding needed
- ✅ Uses UniFi's cloud infrastructure
- ✅ Mobile app integration built-in

### Cons
- ⚠️ **Cannot directly access RTSP streams** - Cloud Connect is web/API-based
- ⚠️ Would need to use UniFi Protect API to get snapshots (not RTSP)
- ⚠️ Requires different integration approach (HTTP snapshots instead of RTSP)
- ⚠️ Depends on UniFi cloud service

### Alternative Approach
Instead of RTSP, we could:
1. Use UniFi Protect API to fetch snapshot images
2. Configure cameras to provide HTTP snapshot URLs
3. Use UniFi's mobile/web interface as intermediary

**Would require code changes** to support UniFi Protect API authentication.

---

## Option 3: SSH Tunnel / Port Forwarding

**Difficulty**: Medium  
**Cost**: Free  
**Security**: Good (SSH encrypted)  
**Setup Time**: 1 hour

### Overview
Create an SSH tunnel from the droplet to a server at the UniFi site, forwarding RTSP traffic.

### Pros
- ✅ Simple if you have SSH access at UniFi site
- ✅ Encrypted connection
- ✅ Can work with existing SSH infrastructure
- ✅ No additional VPN software needed

### Cons
- ⚠️ Requires persistent SSH connection (connection drops = no cameras)
- ⚠️ Need to manage SSH connection reliability
- ⚠️ Higher latency than VPN
- ⚠️ Less flexible for multiple cameras
- ⚠️ SSH port must be accessible (22 or custom)

### Setup Steps

1. **Create SSH Tunnel** (using autossh for reliability):
   ```bash
   # On droplet, create persistent tunnel
   autossh -M 20000 -N -L 1554:unifi-camera-ip:554 user@unifi-site-gateway
   ```

2. **Configure AviationWX to use localhost tunnel**:
   ```json
   {
     "url": "rtsp://127.0.0.1:1554/stream",
     "type": "rtsp"
   }
   ```

3. **Run in Docker container**:
   - Add autossh to Docker image
   - Start tunnel before Apache
   - Monitor connection health

**Note**: This would require modifying the Docker setup to include SSH tunneling.

---

## Option 4: Public IP with Port Forwarding

**Difficulty**: Easy (if static IP available)  
**Cost**: Depends on ISP (may need business plan)  
**Security**: Moderate (requires firewall rules)  
**Setup Time**: 30 minutes

### Overview
If the UniFi site has a static public IP, forward RTSP port through the firewall/router.

### Pros
- ✅ Simplest network setup
- ✅ No additional software
- ✅ Direct connection (lowest latency)
- ✅ Works immediately

### Cons
- ⚠️ Requires static public IP (may cost extra from ISP)
- ⚠️ Exposes RTSP port to internet (security concern)
- ⚠️ Requires strong firewall rules
- ⚠️ Camera authentication becomes critical
- ⚠️ May not be possible with consumer ISPs

### Setup Steps

1. **Configure Router/Firewall**:
   - Forward external port (e.g., 8554) to camera RTSP port (554)
   - Add firewall rules to only allow droplet IP
   - Enable strong authentication on camera

2. **Configure AviationWX**:
   ```json
   {
     "url": "rtsp://username:password@public-ip:8554/stream",
     "type": "rtsp",
     "rtsp_transport": "tcp"
   }
   ```

3. **Security Considerations**:
   - Use RTSPS (secure RTSP) if camera supports it
   - Restrict firewall to only droplet IP
   - Use strong passwords
   - Consider VPN anyway for better security

---

## Option 5: Tailscale / ZeroTier

**Difficulty**: Very Easy  
**Cost**: Free tier available (paid for more devices)  
**Security**: Excellent  
**Setup Time**: 15 minutes

### Overview
Use a modern mesh VPN service to connect the sites.

### Pros
- ✅ Extremely easy setup
- ✅ Works behind any NAT/firewall
- ✅ No configuration on routers needed
- ✅ Mobile app for management
- ✅ Built-in security
- ✅ Free tier usually sufficient

### Cons
- ⚠️ Dependency on third-party service
- ⚠️ May have bandwidth limits on free tier
- ⚠️ Additional network hop (slight latency increase)
- ⚠️ Need to install client on UniFi gateway/server

### Setup Steps

1. **Install Tailscale on Both Sides**:
   ```bash
   # On droplet
   curl -fsSL https://tailscale.com/install.sh | sh
   sudo tailscale up
   
   # At UniFi site (on gateway or separate server)
   # Same installation
   ```

2. **Connect Both Devices**:
   - Authenticate through Tailscale admin console
   - Devices appear on private network (100.x.x.x)
   - Automatic NAT traversal

3. **Configure AviationWX**:
   ```json
   {
     "url": "rtsp://tailscale-ip:554/stream",
     "type": "rtsp"
   }
   ```

**Very simple, but depends on third-party service.**

---

## Option 6: UniFi Protect API (HTTP Snapshots)

**Difficulty**: Medium (requires code changes)  
**Cost**: Free  
**Security**: Good (HTTPS/OAuth)  
**Setup Time**: 2-3 hours (coding)

### Overview
Instead of RTSP, integrate with UniFi Protect API to fetch snapshot images directly.

### Pros
- ✅ Works with Cloud Connect
- ✅ No VPN needed
- ✅ Official API
- ✅ Can get camera metadata

### Cons
- ⚠️ **Requires code changes** to AviationWX
- ⚠️ Need to handle OAuth/API authentication
- ⚠️ API rate limits
- ⚠️ Dependent on UniFi cloud/controller availability
- ⚠️ Slightly more complex than RTSP

### What Would Be Needed

1. **Add UniFi Protect API Client**:
   - Handle OAuth authentication
   - Fetch camera snapshots via API
   - Handle API rate limiting

2. **New Webcam Source Type**:
   ```json
   {
     "name": "UniFi Camera",
     "type": "unifi_protect",
     "unifi_controller_url": "https://your-controller.ui.com",
     "camera_id": "camera-uuid",
     "api_key": "unifi-api-key",
     "refresh_seconds": 60
   }
   ```

This would require significant code changes but could work with Cloud Connect.

---

## Recommendation Matrix

| Option | Difficulty | Security | Reliability | Cost | Best For |
|--------|-----------|----------|-------------|------|----------|
| **WireGuard VPN** | Medium | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐⭐ | Free | Long-term, multiple cameras |
| **Tailscale** | ⭐ Very Easy | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐ | Free/Paid | Quick setup, minimal config |
| **SSH Tunnel** | Medium | ⭐⭐⭐⭐ | ⭐⭐⭐ | Free | Existing SSH infrastructure |
| **Cloud Connect + API** | Medium-Hard | ⭐⭐⭐⭐ | ⭐⭐⭐ | Free | Can't do VPN, want cloud access |
| **Public IP** | Easy | ⭐⭐ | ⭐⭐⭐⭐⭐ | ISP Cost | Static IP available |
| **UniFi Cloud Connect (RTSP)** | N/A | ⭐⭐⭐ | ⭐⭐⭐ | Free | Not directly supported |

---

## My Recommendation

### For Most Cases: **Tailscale**
- Easiest to set up
- Excellent security
- Works behind any firewall
- Free tier is usually sufficient
- 15-minute setup vs hours for WireGuard

### For Maximum Control: **WireGuard VPN**
- Self-hosted (no third-party dependency)
- Maximum performance
- Full control over routing
- Best for multiple sites/cameras long-term

### Quick Test: **SSH Tunnel**
- Good for testing if SSH access exists
- Can validate camera connectivity quickly
- Upgrade to WireGuard/Tailscale later

---

## Implementation Considerations

### For Any VPN/Tunnel Solution:

1. **Docker Network Configuration**:
   - VPN client runs on host or in separate container
   - Ensure web container can access VPN network
   - May need `network_mode: host` or custom Docker network

2. **Camera Discovery**:
   - Once VPN is up, cameras accessible via private IP
   - May need to add DNS entries or use IP addresses
   - Test connectivity from droplet first

3. **Firewall Rules**:
   - Allow VPN traffic (UDP port for WireGuard/Tailscale)
   - Allow RTSP traffic (port 554 or custom) over VPN only
   - Restrict camera access to droplet IP

4. **Monitoring**:
   - Set up alerts if VPN tunnel drops
   - Monitor camera connectivity
   - Log connection failures

5. **Multiple Cameras**:
   - Single VPN tunnel can handle all cameras
   - Just add multiple webcam entries in `airports.json`
   - All cameras accessible via VPN network

---

## Next Steps

1. **Choose your option** based on your constraints
2. **Test connectivity** - can you reach cameras from droplet?
3. **Configure VPN/Tunnel** (if needed)
4. **Add camera entries** to `airports.json`
5. **Test RTSP connection** from droplet
6. **Verify snapshots** are being captured

Would you like me to:
- Create setup scripts for WireGuard or Tailscale?
- Add UniFi Protect API integration code?
- Create Docker configuration for SSH tunneling?
- Help test connectivity once you choose an option?

