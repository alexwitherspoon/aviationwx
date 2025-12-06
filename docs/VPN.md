# VPN Configuration Guide

VPN support enables secure access to private camera networks at remote airport sites via IPsec site-to-site VPN connections. **This feature is optional** - only airports with cameras on private networks need VPN configuration.

## When to Use VPN

Use VPN when:
- Cameras are on private networks (192.168.x.x, 10.x.x.x, 172.16-31.x.x)
- Remote sites have dynamic IPs or no open ports
- You need secure access to private camera feeds

**Most airports don't need VPN** - only use if cameras are on private networks.

## Overview

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
        "psk": "your-secure-psk-here"
      },
      "webcams": [
        {
          "name": "North Camera",
          "url": "rtsp://192.168.1.100:554/stream1",
          "type": "rtsp"
        }
      ]
    }
  }
}
```

### Configuration Fields

- **`enabled`**: Set to `true` to enable VPN for this airport
- **`type`**: Always `"ipsec"` (IPsec protocol)
- **`connection_name`**: Unique connection name (e.g., `"kspb_vpn"`)
- **`remote_subnet`**: CIDR notation for remote network (e.g., `"192.168.1.0/24"`)
- **`psk`**: Pre-shared key (keep secure, never commit to git)

## Remote Site Setup

### 1. Configure Site-to-Site IPsec VPN

On the remote site's router/gateway (e.g., UniFi), configure:

- **Peer IP**: Production server static IP
- **Pre-Shared Key**: Same as `psk` in `airports.json`
- **Remote Subnet**: (if needed)
- **IKE Version**: 2
- **Encryption**: AES-256-GCM
- **Hash**: SHA-256
- **DH Group**: 14

### 2. Camera Configuration

Use private IPs in camera URLs:

```json
{
  "webcams": [
    {
      "name": "Runway Camera",
      "url": "rtsp://192.168.1.100:554/stream",
      "type": "rtsp"
    }
  ]
}
```

The camera IP must be within the `remote_subnet` specified in VPN configuration.

## Monitoring

### Check VPN Status

```bash
# Check IPsec status
docker compose -f docker/docker-compose.prod.yml exec vpn-server ipsec status

# View VPN manager logs
docker compose -f docker/docker-compose.prod.yml logs -f vpn-manager
```

### Status Page

VPN connection status is displayed on the [Status Page](../status.php) showing:
- Connection state (up/down)
- Last connection time
- Connection name

## Troubleshooting

### Connection Won't Establish

1. **Check PSK matches** on both sides (production server and remote site)
2. **Verify firewall** allows UDP ports 500 and 4500
3. **Check IPsec logs**: `docker compose -f docker/docker-compose.prod.yml logs vpn-server`
4. **Verify remote site configuration** matches production server settings

### Connection Drops Frequently

1. **Check network stability** at remote site
2. **Review IPsec logs** for errors
3. **Verify NAT traversal settings** (should be enabled)
4. **Check bandwidth/latency** between sites

### Camera Not Accessible

1. **Verify VPN connection is up**: Check status page or `ipsec status`
2. **Check routing rules**: Verify camera IP is in `remote_subnet`
3. **Test connectivity**: `ping 192.168.1.100` (replace with camera IP)
4. **Check camera URL**: Ensure it uses private IP, not public IP

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

## Security

- **PSKs stored in airports.json**: Never commit to git, deploy separately
- **Strong encryption**: AES-256-GCM, SHA-256
- **Policy routing**: Only specific subnets route through VPN
- **Read-only mounts**: Containers only read airports.json, never write

See [Security Guide](SECURITY.md) for more information.

## Docker Services

### vpn-server
- strongSwan IPsec server
- Accepts multiple connections
- Network mode: host (required for IPsec)

### vpn-manager
- Manages VPN connections
- Generates configuration from airports.json
- Health monitoring
- Auto-reconnection with backoff

## Need Help?

- Check [Deployment Guide](DEPLOYMENT.md) for VPN server setup
- Review [Configuration Guide](CONFIGURATION.md) for webcam configuration
- Open an issue on GitHub for support

