# VPN Feature - Quick Reference

## Overview

VPN feature enables secure access to private camera URLs at remote airport sites via IPsec site-to-site VPN connections.

## Key Concepts

- **VPN Server**: Runs in Docker container (strongSwan), accepts connections from remote sites
- **Remote Sites**: Connect TO production server (they're clients, we're server)
- **Per-Airport**: Each airport has its own VPN connection (never shared)
- **Policy Routing**: Only specific camera IPs route through VPN
- **Auto-Recovery**: Persistent connections with auto-reconnect

## Configuration

### airports.json Example

```json
{
  "airports": {
    "kspb": {
      "vpn": {
        "enabled": true,
        "type": "ipsec",
        "connection_name": "kspb_vpn",
        "remote_subnet": "192.168.1.0/24",
        "psk": "${VPN_PSK_KSPB}"
      },
      "webcams": [
        {
          "name": "North Camera",
          "url": "rtsp://192.168.1.100:554/stream1",  // Private IP via VPN
          "type": "rtsp"
        }
      ]
    }
  }
}
```

### Environment Variables

```bash
# One PSK per airport with VPN
VPN_PSK_KSPB=your-secure-psk-here
VPN_PSK_KABC=another-secure-psk-here
```

## Architecture

```
Production Server (Static IP)
├── VPN Server Container (strongSwan)
│   └── Accepts connections from remote sites
├── VPN Manager Service
│   ├── Reads airports.json
│   ├── Generates strongSwan config
│   ├── Monitors connections
│   └── Handles reconnection
└── Web App Container
    ├── Checks VPN requirement
    ├── Routes camera requests through VPN
    └── Handles VPN failures gracefully
```

## Remote Site Setup

1. **Enable Dynamic DNS** (if dynamic IP)
2. **Configure Site-to-Site IPsec VPN**:
   - Peer IP: Production server static IP
   - Pre-Shared Key: (shared with production)
   - Remote Subnet: (if needed)
   - IKE Version: 2
   - Encryption: AES-256-GCM
   - Hash: SHA-256
   - DH Group: 14

## Docker Services

### vpn-server
- strongSwan IPsec server
- Accepts multiple connections
- Network mode: host (for IPsec)

### vpn-manager
- Manages VPN connections
- Generates configuration
- Health monitoring
- Auto-reconnection

## Monitoring

### Check VPN Status
```bash
docker exec aviationwx-vpn ipsec status
docker logs -f aviationwx-vpn-manager
```

### Health Checks
- Interface status (every 30s)
- IPsec connection state (every 60s)
- Ping remote gateway (every 5min)

## Troubleshooting

### Connection Won't Establish
1. Check PSK matches on both sides
2. Verify firewall allows UDP 500, 4500
3. Check IPsec logs: `docker logs aviationwx-vpn`
4. Verify remote site configuration

### Connection Drops Frequently
1. Check network stability
2. Review IPsec logs for errors
3. Verify NAT traversal settings
4. Check bandwidth/latency

### Camera Not Accessible
1. Verify VPN connection is up
2. Check routing rules
3. Verify camera IP is in remote_subnet
4. Test ping to camera IP

## Files

- `docs/VPN_DESIGN.md` - Full design document
- `docs/VPN_IMPLEMENTATION_EXAMPLES.md` - Code examples
- `docs/VPN_OPEN_QUESTIONS.md` - Decisions and recommendations
- `docs/VPN_REMOTE_SITE_SETUP.md` - Remote site guide (to be created)

## Status

**Current State**: Design phase
**Next Steps**: Prototype and testing with KSPB site

