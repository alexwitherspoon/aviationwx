# VPN Feature - Quick Reference

## Overview

VPN feature enables secure access to private camera URLs at remote airport sites. Supports WireGuard, OpenVPN, and IPsec protocols.

## Supported Protocols

- **WireGuard** - Modern, fast, recommended for new setups
- **OpenVPN** - Widely compatible, good for older equipment  
- **IPsec** - Standard protocol, works with most enterprise gear

## Key Concepts

- **VPN Servers**: Separate Docker containers for each protocol
- **Remote Sites**: Connect TO production server (they're clients, we're server)
- **Per-Airport**: Each airport has its own VPN connection
- **Auto Key Generation**: Server generates keys automatically (WireGuard/OpenVPN)
- **Client Configs**: Generated configs ready to import at remote sites

## Configuration

### airports.json Example (WireGuard)

```json
{
  "airports": {
    "kspb": {
      "vpn": {
        "enabled": true,
        "type": "wireguard",
        "connection_name": "kspb_vpn",
        "remote_subnet": "192.168.1.0/24",
        "wireguard": {
          "server_port": 51820
        }
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

### Environment Variables

```bash
VPN_SERVER_IP=your.public.ip.address
VPN_SUBNET=10.0.0.0/16
```

## Docker Services

### vpn-wireguard
- WireGuard server
- Port: UDP 51820
- Network mode: host

### vpn-openvpn
- OpenVPN server
- Port: UDP 1194
- Network mode: host

### vpn-server
- strongSwan IPsec server
- Ports: UDP 500, 4500
- Network mode: host

### vpn-manager
- Manages all VPN protocols
- Generates server and client configs
- Health monitoring
- Auto key generation

## Quick Start

1. Add VPN config to `airports.json`
2. Restart containers: `docker-compose restart vpn-manager vpn-wireguard`
3. Generate client config: `./scripts/generate-client-config.sh kspb wireguard`
4. Import config at remote site
5. Update webcam URLs to use private IPs

## Monitoring

### Check VPN Status
```bash
# WireGuard
docker exec vpn-wireguard wg show

# OpenVPN
docker logs vpn-openvpn

# IPsec
docker exec vpn-server ipsec status

# Manager logs
docker logs -f vpn-manager
```

## Troubleshooting

### Connection Won't Establish
1. Check keys/PSK match on both sides
2. Verify firewall allows required ports:
   - WireGuard: UDP 51820
   - OpenVPN: UDP 1194
   - IPsec: UDP 500, 4500
3. Check logs: `docker logs vpn-wireguard` (or vpn-openvpn/vpn-server)
4. Verify `VPN_SERVER_IP` is correct

### Camera Not Accessible
1. Verify VPN connection is up
2. Verify camera IP is in `remote_subnet` range
3. Test: `docker exec vpn-manager ping -c 3 192.168.1.100`

## Files

- `docs/VPN_USAGE.md` - Complete usage guide
- `config/vpn-clients/` - Generated client configs (git-ignored)

