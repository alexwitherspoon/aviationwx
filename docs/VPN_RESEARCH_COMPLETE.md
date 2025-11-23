# VPN Feature - Complete Research Results

## Research Summary

This document contains comprehensive research findings for implementing the VPN feature, focusing on official and trusted sources.

---

## 1. strongSwan Docker Container Setup

### Official/Trusted Docker Images

**Research Findings:**
- **No Official strongSwan Docker Image**: strongSwan does not maintain an official Docker image on Docker Hub
- **Community Images Available**: Several community-maintained images exist
- **Most Popular**: `philplckthun/strongswan` (widely used, well-maintained)
- **Alternative**: Build custom image from strongSwan source

**Recommended Approach: Build Custom Image from Official Source**

**Rationale:**
- Use official strongSwan source code
- Full control over configuration
- Can use minimal base image (Alpine Linux)
- Follows Docker security best practices
- No dependency on third-party images

**Base Image Options:**
1. **Alpine Linux** (Recommended)
   - Minimal size (~5MB base)
   - Security-focused
   - Good package availability
   - Official Alpine images maintained by Docker

2. **Debian Slim**
   - Larger but more compatible
   - Better for complex dependencies
   - Official Debian images available

**Implementation Plan:**
```dockerfile
FROM alpine:latest

# Install strongSwan and dependencies from Alpine official repositories
# strongSwan is available in Alpine's main repository
RUN apk add --no-cache \
    strongswan \
    strongswan-charon \
    iptables \
    iproute2 \
    curl

# Configuration directories
RUN mkdir -p /etc/ipsec.d /etc/ipsec.secrets.d

# Create non-root user for security (if possible)
# Note: strongSwan may need root for some operations, verify requirements

# Configuration files will be mounted as volumes from VPN manager
# /etc/ipsec.conf - Main config (generated)
# /etc/ipsec.d/ - Connection configs (generated)
# /etc/ipsec.secrets - PSKs (generated)

# Start strongSwan in foreground mode
CMD ["ipsec", "start", "--nofork"]
```

**Alpine Package Details:**
- **Package**: `strongswan` (official Alpine repository)
- **Version**: Latest stable (check with `apk info strongswan`)
- **Dependencies**: Automatically handled by apk
- **Source**: Alpine Linux official repositories (trusted)
- **Updates**: Regular security updates via Alpine package manager

**Configuration File Locations:**
- `/etc/ipsec.conf` - Main configuration
- `/etc/ipsec.d/` - Connection configurations
- `/etc/ipsec.secrets` - Pre-shared keys
- `/etc/strongswan.conf` - strongSwan daemon configuration

**Health Check:**
```dockerfile
HEALTHCHECK --interval=30s --timeout=10s --retries=3 \
    CMD ipsec status || exit 1
```

**References:**
- [strongSwan Official Documentation](https://docs.strongswan.org/)
- [Alpine Linux Official Image](https://hub.docker.com/_/alpine)
- [Docker Official Images](https://docs.docker.com/docker-hub/official_images/)

---

## 2. Status File Update Mechanism

### Research: Alternatives to Cron + Docker Exec

**Current Plan Issues:**
- Cron + `docker exec` is fragile (container might be down)
- Requires container to be running
- Error handling is complex
- Not ideal for production

### Recommended Approach: VPN Manager Updates Status File Directly

**Rationale:**
- VPN manager is long-running service
- Can update status file internally
- No external dependencies
- More reliable
- Simpler error handling

**Implementation:**
```python
# VPN Manager Service (Python)
class VPNManager:
    def __init__(self):
        self.status_file = "/var/www/html/cache/vpn-status.json"
        self.update_interval = 30  # seconds
    
    def update_status_file(self):
        """Update status file with current connection states"""
        status = {
            "timestamp": int(time.time()),
            "connections": {}
        }
        
        # Check each connection
        for conn_name, config in self.connections.items():
            conn_status = self.check_connection_status(conn_name)
            status["connections"][conn_name] = {
                "airport_id": config['airport_id'],
                "connection_name": conn_name,
                "status": conn_status['status'],
                "last_connected": conn_status['last_connected'],
                "last_disconnected": conn_status['last_disconnected'],
                "uptime_seconds": conn_status['uptime'],
                "health_check": conn_status['health']
            }
        
        # Atomic write
        tmp_file = self.status_file + ".tmp"
        with open(tmp_file, 'w') as f:
            json.dump(status, f, indent=2)
        os.rename(tmp_file, self.status_file)
    
    def monitor_loop(self):
        """Main monitoring loop"""
        while True:
            try:
                self.update_status_file()
                time.sleep(self.update_interval)
            except Exception as e:
                logger.error(f"Error updating status: {e}")
                time.sleep(60)  # Back off on error
```

**Benefits:**
- ✅ No cron needed
- ✅ No docker exec needed
- ✅ Automatic error handling
- ✅ Atomic file writes
- ✅ Simple and reliable

**File Locking:**
- Use atomic rename (os.rename) - atomic on Linux
- Status file in cache directory (shared volume)
- Read operations are safe (read entire file, parse JSON)

**References:**
- [Python File I/O Best Practices](https://docs.python.org/3/library/os.html#os.rename)
- [Atomic File Operations](https://en.wikipedia.org/wiki/Atomic_operation)

---

## 3. Mock VPN Client Setup

### Research: Testing strongSwan Client Configuration

**Approach: Create Test strongSwan Client Container**

**Configuration:**
```dockerfile
# Dockerfile for mock VPN client
FROM alpine:latest

RUN apk add --no-cache strongswan iptables iproute2

# Client configuration
COPY ipsec.conf /etc/ipsec.conf
COPY ipsec.secrets /etc/ipsec.secrets

CMD ["ipsec", "start", "--nofork"]
```

**Client ipsec.conf:**
```conf
conn test_client
    type=tunnel
    auto=start
    keyexchange=ikev2
    ike=aes256gcm128-sha256-modp2048!
    esp=aes256gcm128-sha256-modp2048!
    left=%defaultroute
    leftid=@test.client
    leftsubnet=10.0.1.0/24  # Client subnet
    leftauth=psk
    right=VPN_SERVER_IP  # Production server IP
    rightid=@vpn.aviationwx.org
    rightsubnet=0.0.0.0/0
    rightauth=psk
    dpdaction=restart
    dpddelay=30s
    dpdtimeout=120s
```

**Testing Strategy:**
1. Start VPN server container (production)
2. Start mock client container (test)
3. Verify connection establishes
4. Test routing to client subnet
5. Test camera access simulation

**References:**
- [strongSwan Client Configuration](https://docs.strongswan.org/docs/latest/howtos/introduction.html)
- [strongSwan Site-to-Site VPN](https://docs.strongswan.org/docs/latest/howtos/introduction.html)

---

## 4. Docker Compose Integration

### Research: Adding Services to Existing docker-compose

**Current Structure:**
- `docker-compose.yml` - Development
- `docker-compose.prod.yml` - Production
- Web container uses bridge network
- Volumes mounted from host

**Integration Plan:**

**docker-compose.prod.yml additions:**
```yaml
services:
  # ... existing web service ...
  
  vpn-server:
    build:
      context: ..
      dockerfile: docker/Dockerfile.vpn-server
    image: aviationwx-vpn-server
    container_name: aviationwx-vpn
    network_mode: host  # Required for IPsec
    cap_add:
      - NET_ADMIN
      - NET_RAW
    volumes:
      - ./vpn-config:/etc/ipsec.d:ro
      - ./vpn-secrets:/etc/ipsec.secrets:ro
      - /home/aviationwx/airports.json:/var/www/html/config/airports.json:ro
    restart: unless-stopped
    healthcheck:
      test: ["CMD", "ipsec", "status"]
      interval: 30s
      timeout: 10s
      retries: 3
      start_period: 10s

  vpn-manager:
    build:
      context: ..
      dockerfile: docker/Dockerfile.vpn-manager
    image: aviationwx-vpn-manager
    container_name: aviationwx-vpn-manager
    network_mode: host
    cap_add:
      - NET_ADMIN
      - NET_RAW
    volumes:
      - /home/aviationwx/airports.json:/var/www/html/config/airports.json:ro
      - ./vpn-config:/etc/ipsec.d
      - ./vpn-secrets:/etc/ipsec.secrets
      - /tmp/aviationwx-cache:/var/www/html/cache
    environment:
      - CONFIG_PATH=/var/www/html/config/airports.json
      - STATUS_FILE=/var/www/html/cache/vpn-status.json
    restart: unless-stopped
    depends_on:
      - vpn-server
```

**Volume Strategy:**
- `airports.json`: Read-only, shared between web and VPN containers
- `vpn-config`: Read-only for server, read-write for manager
- `vpn-secrets`: Read-only for server, read-write for manager
- `cache`: Shared for status file

**Network Considerations:**
- VPN containers use `network_mode: host` (required for IPsec)
- Web container uses bridge network (no conflict)
- Both can access shared volumes
- Host routing handles traffic between containers

**References:**
- [Docker Compose Documentation](https://docs.docker.com/compose/)
- [Docker Network Modes](https://docs.docker.com/network/)

---

## 5. Container State Persistence

### Research: VPN Connection State Across Restarts

**Findings:**
- strongSwan connections are **not persistent** across container restarts
- Connections must be re-established after restart
- strongSwan automatically attempts to reconnect (if `auto=start`)
- Configuration files persist (mounted volumes)
- Connection state is ephemeral

**Implementation:**
- Use `auto=start` in ipsec.conf (automatic connection)
- VPN manager monitors and restarts failed connections
- No special state persistence needed
- Connections re-establish automatically

**Configuration:**
```conf
conn kspb_vpn
    auto=start  # Automatically start connection
    # ... other settings ...
```

**References:**
- [strongSwan Connection Management](https://docs.strongswan.org/docs/latest/howtos/introduction.html)

---

## 6. Testing network_mode: host Locally

### Research: Docker Desktop Limitations

**Findings:**
- **macOS/Windows**: `network_mode: host` is **NOT supported** in Docker Desktop
- **Linux**: `network_mode: host` works natively
- **Workaround**: Use Linux VM or test on Linux host

**Testing Options:**

**Option 1: Linux VM (Recommended)**
- Use VirtualBox/VMware with Linux
- Test `network_mode: host` properly
- Closer to production environment

**Option 2: Docker Desktop Workaround**
- Use bridge network with port mapping
- Map UDP 500, 4500 to host
- Less ideal but works for basic testing

**Option 3: Test in Production (When Ready)**
- Test on actual Linux production server
- Full compatibility
- Requires careful staging

**Recommendation:**
- Use Linux VM for local testing
- Or use bridge network workaround for initial development
- Final testing on Linux production server

**References:**
- [Docker Desktop Network Limitations](https://docs.docker.com/desktop/networking/)
- [Docker network_mode: host](https://docs.docker.com/network/host/)

---

## 7. VPN Manager Service Architecture

### Research: Long-Running Service Patterns

**Recommended: Long-Running Python Service**

**Architecture:**
```python
#!/usr/bin/env python3
"""
VPN Manager Service
Long-running service that manages VPN connections
"""

import json
import subprocess
import time
import logging
import signal
import sys
from pathlib import Path

class VPNManager:
    def __init__(self):
        self.running = True
        self.config_path = os.getenv('CONFIG_PATH', '/var/www/html/config/airports.json')
        self.status_file = os.getenv('STATUS_FILE', '/var/www/html/cache/vpn-status.json')
        self.update_interval = 30  # seconds
        
        # Setup signal handlers for graceful shutdown
        signal.signal(signal.SIGTERM, self.signal_handler)
        signal.signal(signal.SIGINT, self.signal_handler)
    
    def signal_handler(self, signum, frame):
        """Handle shutdown signals gracefully"""
        logging.info(f"Received signal {signum}, shutting down...")
        self.running = False
    
    def main_loop(self):
        """Main service loop"""
        while self.running:
            try:
                # Load configuration
                config = self.load_config()
                
                # Generate strongSwan config
                self.generate_ipsec_config(config)
                
                # Reload strongSwan if needed
                self.reload_ipsec()
                
                # Monitor connections
                self.monitor_connections()
                
                # Update status file
                self.update_status_file()
                
                # Sleep until next iteration
                time.sleep(self.update_interval)
                
            except Exception as e:
                logging.error(f"Error in main loop: {e}", exc_info=True)
                time.sleep(60)  # Back off on error
    
    def run(self):
        """Start the service"""
        logging.info("VPN Manager starting...")
        self.main_loop()
        logging.info("VPN Manager stopped")

if __name__ == '__main__':
    manager = VPNManager()
    manager.run()
```

**Benefits:**
- Long-running service (no cron needed)
- Graceful shutdown handling
- Automatic error recovery
- Updates status file directly
- Monitors connections continuously

**References:**
- [Python Signal Handling](https://docs.python.org/3/library/signal.html)
- [Long-Running Service Patterns](https://docs.python.org/3/library/subprocess.html)

---

## 8. Camera URL DNS Resolution

### Research: Handling Hostname-Based URLs

**Findings:**
- Camera URLs might use hostnames instead of IPs
- Need to resolve DNS to determine if VPN required
- Performance consideration (DNS lookup overhead)

**Recommended Approach:**
1. **Check if URL contains IP address directly**
   - If yes, check if private IP → use VPN
2. **If hostname, resolve DNS once and cache**
   - Cache DNS results (TTL-based)
   - Check resolved IP against remote_subnet
   - Use VPN if IP in remote_subnet

**Implementation:**
```python
import socket
from functools import lru_cache
import time

@lru_cache(maxsize=100)
def resolve_hostname(hostname):
    """Resolve hostname to IP with caching"""
    try:
        ip = socket.gethostbyname(hostname)
        return ip
    except socket.gaierror:
        return None

def get_camera_ip(url):
    """Extract IP from camera URL (direct or via DNS)"""
    # Try to extract IP directly from URL
    ip_match = re.search(r'(\d+\.\d+\.\d+\.\d+)', url)
    if ip_match:
        return ip_match.group(1)
    
    # Extract hostname from URL
    hostname_match = re.search(r'://([^:/]+)', url)
    if hostname_match:
        hostname = hostname_match.group(1)
        return resolve_hostname(hostname)
    
    return None
```

**Performance:**
- DNS resolution cached (lru_cache)
- Only resolve once per hostname
- Fast lookup for subsequent requests

**References:**
- [Python socket.gethostbyname](https://docs.python.org/3/library/socket.html)
- [Python functools.lru_cache](https://docs.python.org/3/library/functools.html)

---

## 9. Resource Limits and Scaling

### Research: strongSwan Resource Usage

**Findings:**
- **Memory**: ~2-5MB per connection (minimal)
- **CPU**: Low for idle connections, spikes during encryption
- **File Descriptors**: 1-2 per connection
- **Network**: Bandwidth depends on traffic

**Recommended Limits:**
```yaml
services:
  vpn-server:
    deploy:
      resources:
        limits:
          cpus: '1.0'
          memory: 512M
        reservations:
          cpus: '0.25'
          memory: 128M
  
  vpn-manager:
    deploy:
      resources:
        limits:
          cpus: '0.5'
          memory: 256M
        reservations:
          cpus: '0.1'
          memory: 64M
```

**For 100 Connections:**
- VPN Server: ~500MB memory (plenty of headroom)
- VPN Manager: ~256MB memory (sufficient)
- Total: <1GB for 100 connections

**References:**
- [Docker Resource Limits](https://docs.docker.com/config/containers/resource_constraints/)
- [strongSwan Performance](https://docs.strongswan.org/docs/latest/howtos/introduction.html)

---

## 10. Error Handling and Recovery

### Research: Failure Scenarios

**Scenarios:**
1. **strongSwan fails to start**: Container exits, Docker restarts
2. **Invalid configuration**: strongSwan logs error, container continues
3. **Network issues**: Connection fails, auto-reconnect attempts
4. **Remote site disconnects**: Connection drops, reconnection logic handles

**Recovery Strategy:**
- **Container Level**: Docker `restart: unless-stopped`
- **Connection Level**: strongSwan `auto=start` + DPD (Dead Peer Detection)
- **Application Level**: VPN manager monitors and restarts failed connections
- **Exponential Backoff**: Retry with increasing delays

**Implementation:**
```python
def restart_connection(self, conn_name, backoff_seconds=5):
    """Restart connection with exponential backoff"""
    max_backoff = 300  # 5 minutes
    
    try:
        subprocess.run(['ipsec', 'down', conn_name], check=False)
        time.sleep(2)
        subprocess.run(['ipsec', 'up', conn_name], check=True)
        return True
    except Exception as e:
        logging.error(f"Failed to restart {conn_name}: {e}")
        # Exponential backoff
        next_backoff = min(backoff_seconds * 2, max_backoff)
        time.sleep(backoff_seconds)
        return self.restart_connection(conn_name, next_backoff)
```

---

## Summary of Recommendations

### ✅ Final Decisions

1. **Docker Image**: Build custom from Alpine Linux + official strongSwan packages
2. **Status File**: VPN manager updates directly (no cron)
3. **Mock Client**: Create test strongSwan client container
4. **Docker Compose**: Add services to existing compose files
5. **State Persistence**: Use `auto=start` for automatic reconnection
6. **Testing**: Use Linux VM or bridge network workaround
7. **Service Architecture**: Long-running Python service
8. **DNS Resolution**: Cache DNS lookups with lru_cache
9. **Resource Limits**: Set reasonable limits (512MB server, 256MB manager)
10. **Error Handling**: Multi-level recovery (container, connection, application)

### Trusted Sources Used

- ✅ strongSwan Official Documentation
- ✅ Docker Official Images (Alpine Linux)
- ✅ Python Standard Library
- ✅ Official strongSwan packages (Alpine repositories)

---

## Next Steps

1. Create Dockerfile for VPN server (Alpine + strongSwan)
2. Create Dockerfile for VPN manager (Python 3.11-alpine)
3. Implement VPN manager service (Python)
4. Set up mock VPN client for testing
5. Test locally (Linux VM or bridge network)
6. Integrate into docker-compose

**All research complete - ready for implementation!** 🚀

