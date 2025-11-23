# VPN Feature - Final Design

## ✅ All Decisions Finalized

This document summarizes the complete, finalized design for the VPN feature.

## Architecture

### Single VPN Server Container
- **Decision**: One strongSwan container handles all VPN connections
- **Rationale**: Simple, efficient, failure is tolerable (can recover by restarting)
- **Implementation**: strongSwan in Docker container with network_mode: host

### PSK Storage
- **Decision**: Store PSKs directly in `airports.json`
- **Access Method**: Direct read from file (mounted read-only in containers)
- **Security**: File tightly guarded, deployed separately, never in git

### Configuration Updates
- **Decision**: No hot-reload - container restart on `airports.json` update
- **Process**: When `airports.json` is updated via deployment, Docker Compose restarts containers
- **Rationale**: Simple, reliable, no complexity needed

## Configuration Schema

```json
{
  "airports": {
    "kspb": {
      "vpn": {
        "enabled": true,
        "type": "ipsec",
        "connection_name": "kspb_vpn",
        "remote_subnet": "192.168.1.0/24",
        "psk": "actual-psk-value-here",
        "ike_version": "2",
        "encryption": "aes256gcm128",
        "dh_group": "14"
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

## Components

### 1. VPN Server Container (strongSwan)
- Accepts IPsec connections from remote sites
- Handles 100+ simultaneous connections
- Network mode: host (required for IPsec)
- Auto-reconnect support

### 2. VPN Manager Service
- Reads VPN config from `airports.json` (mounted read-only)
- Generates strongSwan configuration files
- Monitors connection health
- Handles auto-reconnection with backoff
- Writes status file (JSON) for status page

### 3. Status File System
- **Location**: `/var/www/html/cache/vpn-status.json` (or similar)
- **Format**: JSON with connection states per airport
- **Update**: Via cron job (e.g., every 30-60 seconds)
- **Content**: Connection state, last connection time per airport

### 4. Status Page Integration
- **Display**: Separate component in airport status card
- **Shows**: Connection state (up/down/connecting) + last connection time
- **Source**: Reads from status file (not direct checks)
- **Only**: If VPN is enabled for that airport

### 5. Wizard Utility
- **Format**: CLI interactive script
- **Location**: `scripts/admin/vpn-wizard` or `scripts/vpn/wizard`
- **Functionality**:
  - Read existing `airports.json` file
  - Generate secure PSK
  - Generate `airports.json` configuration snippet for the site
  - **Validate** that the addition works with the rest of the config file (JSON validation, schema validation)
  - Generate remote site configuration instructions (UniFi-specific)
  - Provide deployment checklist
- **Output**: 
  - JSON snippet to add to `airports.json` (validated against existing config)
  - Remote site configuration instructions
  - Deployment checklist
- **Workflow**: 
  - Wizard outputs validated JSON snippet
  - User takes output and runs separate deploy process to push to production
  - Wizard does NOT modify airports.json directly (deployment is separate)

## Docker Compose Configuration

```yaml
services:
  vpn-server:
    image: strongswan:latest
    container_name: aviationwx-vpn
    network_mode: host
    cap_add:
      - NET_ADMIN
      - NET_RAW
    volumes:
      - ./vpn-config:/etc/ipsec.d:ro
      - ./vpn-secrets:/etc/ipsec.secrets:ro
      - ../config/airports.json:/var/www/html/config/airports.json:ro
    restart: unless-stopped

  vpn-manager:
    build:
      context: ..
      dockerfile: docker/Dockerfile.vpn-manager
    container_name: aviationwx-vpn-manager
    network_mode: host
    cap_add:
      - NET_ADMIN
      - NET_RAW
    volumes:
      - ../config/airports.json:/var/www/html/config/airports.json:ro
      - ./vpn-config:/etc/ipsec.d
      - ./vpn-secrets:/etc/ipsec.secrets
      - ../cache:/var/www/html/cache
    environment:
      - CONFIG_PATH=/var/www/html/config/airports.json
      - STATUS_FILE=/var/www/html/cache/vpn-status.json
    restart: unless-stopped
    depends_on:
      - vpn-server
```

## Status File Format

```json
{
  "timestamp": 1706284800,
  "connections": {
    "kspb_vpn": {
      "airport_id": "kspb",
      "connection_name": "kspb_vpn",
      "status": "up",
      "last_connected": 1706284800,
      "last_disconnected": 0,
      "uptime_seconds": 3600,
      "health_check": "pass"
    },
    "kabc_vpn": {
      "airport_id": "kabc",
      "connection_name": "kabc_vpn",
      "status": "down",
      "last_connected": 1706281000,
      "last_disconnected": 1706284500,
      "uptime_seconds": 0,
      "health_check": "fail"
    }
  }
}
```

## Status Page Integration

### PHP Function Addition

```php
/**
 * Check VPN status for airport
 */
function checkVpnStatus($airportId, $airport) {
    $vpn = $airport['vpn'] ?? null;
    
    if (!$vpn || !($vpn['enabled'] ?? false)) {
        return null; // No VPN, don't show status
    }
    
    $statusFile = __DIR__ . '/../cache/vpn-status.json';
    if (!file_exists($statusFile)) {
        return [
            'name' => 'VPN Connection',
            'status' => 'down',
            'message' => 'VPN status unavailable',
            'lastChanged' => 0
        ];
    }
    
    $statusData = @json_decode(file_get_contents($statusFile), true);
    $connectionName = $vpn['connection_name'] ?? "{$airportId}_vpn";
    $connStatus = $statusData['connections'][$connectionName] ?? null;
    
    if (!$connStatus) {
        return [
            'name' => 'VPN Connection',
            'status' => 'down',
            'message' => 'VPN connection not found',
            'lastChanged' => 0
        ];
    }
    
    $status = $connStatus['status'] === 'up' ? 'operational' : 'down';
    $lastConnected = $connStatus['last_connected'] ?? 0;
    $message = $status === 'operational' 
        ? 'VPN connected' 
        : 'VPN disconnected';
    
    if ($lastConnected > 0) {
        $message .= ' (last connected: ' . formatRelativeTime($lastConnected) . ')';
    }
    
    return [
        'name' => 'VPN Connection',
        'status' => $status,
        'message' => $message,
        'lastChanged' => $lastConnected
    ];
}
```

## Cron Job for Status Updates

```bash
# /etc/cron.d/aviationwx-vpn-status
# Update VPN status file every 30 seconds
*/30 * * * * docker exec aviationwx-vpn-manager /usr/local/bin/update-vpn-status.sh
```

## Wizard Utility Structure

```
scripts/
  admin/
    vpn-wizard          # Main wizard script
    vpn-psk-generator   # PSK generation utility
  vpn/
    wizard/              # Alternative location
      wizard.sh
      templates/         # Configuration templates
        unifi-config.txt
        generic-config.txt
```

### Wizard Workflow

1. **Read Existing Config**: Load `airports.json` to see current state
2. **Interactive Prompts**:
   - Airport ID
   - Remote subnet
   - Optional: Custom connection name, encryption settings
3. **Generate PSK**: Create secure random PSK
4. **Generate JSON Snippet**: Create VPN configuration block
5. **Validate**: 
   - Merge snippet with existing config (in memory)
   - Validate JSON syntax
   - Validate schema (required fields, format)
   - Check for conflicts (duplicate connection names, etc.)
6. **Output**:
   - Validated JSON snippet (ready to add to airports.json)
   - Remote site configuration instructions (with pre-filled PSK, server IP, etc.)
   - Deployment checklist
7. **No File Modification**: Wizard does NOT write to airports.json (deployment is separate)

### Example Wizard Output

```json
// Add this to airports.json under the "kspb" airport entry:
{
  "vpn": {
    "enabled": true,
    "type": "ipsec",
    "connection_name": "kspb_vpn",
    "remote_subnet": "192.168.1.0/24",
    "psk": "generated-psk-value-here",
    "ike_version": "2",
    "encryption": "aes256gcm128",
    "dh_group": "14"
  }
}

// Validation: ✓ JSON valid, ✓ Schema valid, ✓ No conflicts
```

Plus remote site configuration instructions with the PSK and server details pre-filled.

## Implementation Phases

### Phase 1: Core Functionality (MVP)
- ✅ VPN server container (strongSwan)
- ✅ Basic VPN manager service
- ✅ Configuration parsing from `airports.json`
- ✅ Direct PSK read from file
- ✅ Single test connection (KSPB)
- ✅ Basic health monitoring
- ✅ Status file generation

### Phase 2: Reliability
- ✅ Auto-reconnection logic
- ✅ Health checks
- ✅ Policy routing
- ✅ PHP integration
- ✅ Error handling
- ✅ Status file cron updates

### Phase 3: Production Ready
- ✅ Comprehensive logging
- ✅ Monitoring/metrics
- ✅ Status page integration
- ✅ Documentation
- ✅ Multiple site support
- ✅ Wizard utility

### Phase 4: Enhancements
- IPv6 support (future)
- Advanced monitoring
- Automated testing
- Performance optimization

## Key Features Summary

✅ IPsec site-to-site VPN (widest compatibility)  
✅ Per-airport VPN configuration  
✅ PSKs stored in airports.json (direct read)  
✅ Auto-reconnection with backoff  
✅ Policy routing (only camera IPs through VPN)  
✅ Graceful failure handling (serve cached images)  
✅ Status page integration (connection state + last connection time)  
✅ Status file system (cron-based updates)  
✅ Support for 100+ sites  
✅ Works with dynamic IPs (remote sites connect to us)  
✅ All functionality in Docker containers  
✅ No hot-reload needed (container restart)  
✅ CLI wizard utility for configuration management  

## Security

- PSKs in airports.json (never in git)
- Read-only file mounts in containers for airports.json (sufficient protection)
- Deploy separately from codebase
- Strong encryption (AES-256-GCM)
- IKEv2 protocol
- Policy routing (only specific IPs through VPN)
- Containers only need read access - no write permissions required

## Next Steps

1. **Prototype Development**
   - Set up test strongSwan container
   - Test IPsec connection with UniFi gateway
   - Validate configuration generation
   - Test policy routing

2. **Implementation**
   - Start with Phase 1 (MVP)
   - Test with KSPB site
   - Iterate based on testing
   - Add features incrementally

3. **Documentation**
   - Remote site setup guide
   - Configuration reference
   - Troubleshooting guide
   - Wizard utility documentation

**Design is complete and ready for implementation!** 🚀

