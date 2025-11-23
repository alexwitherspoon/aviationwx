# VPN Feature Design Document

## Overview

This document outlines the design for adding VPN support to enable secure access to private camera URLs at remote airport sites. The feature allows remote sites with dynamic IPs and no open ports to connect to the production server via VPN, enabling the webcam image fetcher to access cameras on private networks.

## Requirements Summary

- **VPN Server**: Run in Docker container (strongSwan IPsec)
- **Protocol**: IPsec for widest compatibility with UniFi and standard networking equipment
- **Scale**: Support up to 100 remote sites
- **Architecture**: Remote sites connect TO production server (server-client topology)
- **Configuration**: Per-airport VPN configuration in `airports.json`
- **Routing**: Only route specific camera IPs/subnets through VPN (policy-based routing)
- **Reliability**: Long-lived persistent connections with auto-reconnect and backoff
- **Camera URLs**: Use private IPs when VPN is enabled (e.g., `rtsp://192.168.1.100:554/stream`)
- **Failure Handling**: Log VPN failures, serve cached images, auto-recover when connection restored

## Architecture

### High-Level Design

```
┌─────────────────────────────────────────────────────────┐
│              Production Server (Static IP)             │
│                                                         │
│  ┌──────────────────────────────────────────────────┐  │
│  │         Docker Compose Network                   │  │
│  │                                                   │  │
│  │  ┌──────────────┐      ┌──────────────────┐    │  │
│  │  │   Web App    │      │   VPN Server     │    │  │
│  │  │  Container   │◄─────┤  (strongSwan)    │    │  │
│  │  │              │      │                  │    │  │
│  │  │  - PHP       │      │  - IPsec Server  │    │  │
│  │  │  - ffmpeg    │      │  - 100 tunnels   │    │  │
│  │  │  - curl      │      │  - Auto-reconnect│    │  │
│  │  └──────────────┘      └──────────────────┘    │  │
│  │         │                        │               │  │
│  │         └────────┬───────────────┘               │  │
│  │                  │                               │  │
│  │         ┌────────▼────────┐                      │  │
│  │         │  VPN Manager   │                      │  │
│  │         │  Service       │                      │  │
│  │         │  - Health checks                      │  │
│  │         │  - Reconnection logic                 │  │
│  │         └─────────────────┘                      │  │
│  └──────────────────────────────────────────────────┘  │
│                                                         │
└──────────────────────┬──────────────────────────────────┘
                       │
                       │ IPsec VPN Tunnels
                       │
        ┌──────────────┼──────────────┐
        │              │              │
┌───────▼──────┐ ┌────▼──────┐ ┌─────▼──────┐
│ Remote Site 1│ │Remote Site│ │Remote Site│
│ (Dynamic IP) │ │    2      │ │    N      │
│              │ │           │ │           │
│ UniFi Gateway│ │UniFi/Other│ │UniFi/Other│
│   └──────────┘ │           │ │           │
│   Camera       │  Camera   │  Camera    │
│   192.168.1.100│ 10.0.1.50 │ 172.16.0.5 │
└───────────────┘ └──────────┘ └───────────┘
```

### Component Details

#### 1. VPN Server Container (strongSwan)

- **Purpose**: Accept IPsec connections from remote sites
- **Technology**: strongSwan (industry-standard IPsec implementation)
- **Capabilities**:
  - Accept multiple simultaneous site-to-site connections
  - Support dynamic IPs on remote side (NAT traversal)
  - Auto-reconnect support
  - Health monitoring

#### 2. VPN Manager Service

- **Purpose**: Manage VPN connections, health checks, reconnection logic
- **Location**: Can be part of VPN server container or separate service
- **Responsibilities**:
  - Read VPN configuration from `airports.json`
  - Monitor VPN tunnel health
  - Handle reconnection with exponential backoff
  - Log VPN status and failures
  - Update routing tables when tunnels come up/down

#### 3. Web App Container

- **Purpose**: Fetch webcam images
- **Modifications**:
  - Check if camera URL requires VPN (based on airport config)
  - Route requests through appropriate VPN interface
  - Handle VPN failures gracefully (serve cached images)

#### 4. Network Routing

- **Approach**: Policy-based routing
- **Implementation**: 
  - Each VPN tunnel gets a virtual interface (e.g., `ipsec0`, `ipsec1`)
  - Routing rules route specific destination IPs through appropriate VPN interface
  - Use `ip route` and `ip rule` for policy routing

## Configuration Schema

### airports.json Extension

```json
{
  "airports": {
    "kspb": {
      "name": "Scappoose Industrial Airpark",
      "icao": "KSPB",
      // ... existing fields ...
      "vpn": {
        "enabled": true,
        "type": "ipsec",
        "connection_name": "kspb_vpn",
        "remote_subnet": "192.168.1.0/24",
        "psk": "actual-psk-value-here",  // Stored directly in airports.json
        "ike_version": "2",
        "encryption": "aes256gcm128",
        "dh_group": "14",
        "lifetime": "3600"
      },
      "webcams": [
        {
          "name": "North Camera",
          "url": "rtsp://192.168.1.100:554/stream1",  // Private IP via VPN
          "type": "rtsp",
          "position": "north"
        }
      ]
    },
    "kabc": {
      "name": "Another Airport",
      // ... existing fields ...
      // No VPN section = defaults to no VPN
      "webcams": [
        {
          "name": "Public Camera",
          "url": "https://public-camera.example.com/snapshot.jpg",  // Public URL
          "position": "south"
        }
      ]
    }
  }
}
```

### VPN Configuration Fields

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `enabled` | boolean | Yes | Whether VPN is required for this airport |
| `type` | string | Yes | VPN protocol (`ipsec`, `wireguard`, `openvpn`) |
| `connection_name` | string | Yes | Unique identifier for this VPN connection |
| `remote_subnet` | string | Yes | Remote network subnet (e.g., `192.168.1.0/24`) |
| `psk` | string | Conditional | Pre-shared key (stored directly in airports.json) |
| `ike_version` | string | No | IKE version (`1` or `2`, default: `2`) |
| `encryption` | string | No | Encryption algorithm (default: `aes256gcm128`) |
| `dh_group` | string | No | Diffie-Hellman group (default: `14`) |
| `lifetime` | string | No | Connection lifetime in seconds (default: `3600`) |

## Implementation Plan

### Phase 1: VPN Server Setup

1. **Create strongSwan Docker Container**
   - Base image: `philplckthun/strongswan` or build custom
   - Configure to accept multiple IPsec connections
   - Support NAT traversal for dynamic IPs
   - Expose necessary ports (UDP 500, 4500 for IPsec)

2. **Docker Compose Integration**
   - Add VPN server service
   - Configure network mode (bridge or host)
   - Set up volume mounts for configuration
   - Add health checks

3. **Configuration Management**
   - Generate strongSwan config from `airports.json`
   - Support environment variable substitution for PSKs
   - Hot-reload configuration on `airports.json` changes

### Phase 2: VPN Manager Service

1. **Connection Management**
   - Parse VPN config from `airports.json`
   - Generate strongSwan configuration files
   - Start/stop VPN connections based on config
   - Monitor connection status

2. **Health Monitoring**
   - Periodic health checks (ping remote gateway)
   - Detect connection failures
   - Log connection state changes

3. **Auto-Reconnection**
   - Exponential backoff on failures
   - Max retry limits
   - Connection state persistence

### Phase 3: Routing Integration

1. **Policy Routing Setup**
   - Create routing rules per VPN connection
   - Route specific destination IPs through VPN interfaces
   - Handle interface up/down events

2. **Web App Integration**
   - Detect VPN requirement from config
   - Verify VPN tunnel is up before fetching
   - Handle VPN failures gracefully
   - Log VPN-related errors

### Phase 4: Testing & Validation

1. **Development Testing**
   - Mock VPN server for local testing
   - Unit tests for configuration parsing
   - Integration tests for routing logic

2. **Production Testing**
   - Test with KSPB site (UniFi gateway)
   - Validate connection stability
   - Test reconnection logic
   - Performance testing

## Docker Networking Options Analysis

### Option 1: Single VPN Server Container (Recommended)

**Architecture**: One strongSwan container handles all VPN connections

**Decision**: Use this design option. A failure is tolerable if we can recover by restarting a process and resuming.

**Pros**:
- Simple architecture
- Efficient resource usage
- Centralized management
- Easy to scale (strongSwan handles 100+ connections easily)

**Cons**:
- All VPN traffic goes through one container
- Single point of failure (mitigated by health checks and auto-recovery)

**Implementation**:
```yaml
services:
  vpn-server:
    image: strongswan:latest
    container_name: aviationwx-vpn
    network_mode: host  # Or bridge with port mapping
    cap_add:
      - NET_ADMIN
      - NET_RAW
    volumes:
      - ./vpn-config:/etc/ipsec.d
      - ./vpn-secrets:/etc/ipsec.secrets
    restart: unless-stopped
```

### Option 2: Separate VPN Containers Per Airport

**Architecture**: One container per airport VPN connection

**Pros**:
- Complete isolation
- Easy to debug individual connections
- Can restart individual connections independently

**Cons**:
- Resource intensive (100 containers)
- Complex docker-compose.yml
- Harder to manage
- More overhead

**Verdict**: Not recommended for 100 sites

### Option 3: VPN Gateway Proxy

**Architecture**: VPN server + proxy service that routes requests

**Pros**:
- Clean separation of concerns
- Web app doesn't need VPN awareness
- Can add caching/retry logic in proxy

**Cons**:
- Additional complexity
- Extra network hop
- More moving parts

**Verdict**: Overkill for current requirements

## Security Considerations

1. **PSK Storage**
   - Store PSKs in environment variables (not in `airports.json`)
   - Use Docker secrets or external secret management
   - Rotate PSKs periodically

2. **Network Isolation**
   - Only route specific IPs through VPN (not all traffic)
   - Firewall rules to restrict VPN access
   - Monitor for unauthorized access attempts

3. **Connection Security**
   - Use strong encryption (AES-256)
   - Prefer IKEv2 over IKEv1
   - Use strong DH groups (14+)
   - Regular security updates

## Failure Handling

### VPN Connection Failure

1. **Detection**: Health check fails or tunnel interface down
2. **Logging**: Log error with airport ID, connection name, error details
3. **User Experience**: 
   - Serve cached webcam image (if available)
   - Show "Image temporarily unavailable" if no cache
   - Continue serving other airports normally
4. **Recovery**: 
   - Automatic reconnection with exponential backoff
   - Max retry interval: 5 minutes
   - Alert on persistent failures (> 1 hour)

### Camera Fetch Failure (VPN Up)

1. **Detection**: HTTP/RTSP fetch fails
2. **Logging**: Log as normal camera fetch failure
3. **User Experience**: Same as current behavior (serve cached image)

## Monitoring & Observability

1. **Metrics to Track**:
   - VPN connection count
   - VPN connection uptime per airport
   - VPN reconnection frequency
   - Camera fetch success rate via VPN
   - VPN tunnel bandwidth usage

2. **Logging**:
   - VPN connection state changes
   - Reconnection attempts
   - Configuration reload events
   - Routing rule changes

3. **Alerts**:
   - VPN connection down > 5 minutes
   - Multiple VPN connections down simultaneously
   - Configuration errors

## Development Mode

- VPN functionality enabled in dev environment
- Mock VPN server for testing (simulates VPN behavior)
- Can test routing logic without real VPN connections
- Disable actual remote connections in dev (use test config)

## Migration Path

1. **Phase 1**: Deploy VPN server, no airports using it yet
2. **Phase 2**: Test with KSPB site
3. **Phase 3**: Add 2-3 more test sites
4. **Phase 4**: Gradual rollout to production sites
5. **Phase 5**: Monitor and optimize

## Open Questions

1. **PSK Management**: 
   - Preferred method? (Environment variables, Docker secrets, external vault?)
   - How to handle PSK rotation?

2. **Configuration Updates**:
   - Hot-reload VPN config on `airports.json` changes?
   - Or require container restart?

3. **Remote Site Configuration**:
   - Will you provide setup instructions for remote sites?
   - Or automate remote site configuration somehow?

4. **IPv6 Support**:
   - Do remote sites have IPv6?
   - Should we support IPv6 VPN connections?

5. **Bandwidth Management**:
   - Any QoS requirements?
   - Rate limiting per VPN connection?

## Next Steps

1. **Research & Prototype**:
   - Set up test strongSwan container
   - Test IPsec connection with UniFi gateway
   - Validate policy routing approach

2. **Design Review**:
   - Review this document
   - Address open questions
   - Finalize configuration schema

3. **Implementation**:
   - Start with Phase 1 (VPN server)
   - Iterate based on testing results

## References

- [UniFi Site-to-Site IPsec VPN](https://help.ui.com/hc/en-us/articles/360002426234-UniFi-Gateway-Site-to-Site-IPsec-VPN)
- [strongSwan Documentation](https://www.strongswan.org/documentation.html)
- [Docker strongSwan Image](https://hub.docker.com/r/philplckthun/strongswan)
- [Policy Routing in Linux](https://www.kernel.org/doc/Documentation/networking/ip-sysctl.txt)

