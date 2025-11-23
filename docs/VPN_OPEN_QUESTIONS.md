# VPN Feature - Open Questions & Recommendations

This document addresses the open questions from the design document and provides recommendations.

## 1. PSK Management

### Question
**Preferred method? (Environment variables, Docker secrets, external vault?)**

A: I want to keep things very simplistic an store these secrets in the airport.json file. This file will be tightly gaurded and deployed seperately. to the docker host. If docker compose is run on the docker container, then it could extract secretes from the single airports.json file and use docker secrets to use those secrets. 

### Decision: **PSKs Stored in airports.json**

**Approach**: Store PSKs directly in `airports.json` file
- File is tightly guarded and deployed separately to Docker host
- Docker Compose can extract secrets from `airports.json` and use Docker secrets
- Simplifies configuration management
- Single source of truth

**Format in airports.json**:
```json
"vpn": {
  "enabled": true,
  "psk": "actual-psk-value-here"  // Stored directly in file
}
```

**Implementation**:
- PSKs stored directly in `airports.json` (not environment variables)
- VPN manager reads PSKs from config file
- Docker Compose can extract PSKs and create Docker secrets if needed
- File must be protected (not in git, proper file permissions)

**Security Considerations**:
- `airports.json` must never be committed to git
- Read-only mounts in Docker containers (sufficient protection)
- Deploy separately from codebase
- Rotate PSKs periodically (manual process)
- Use strong, unique PSKs per airport
- Containers only need read access - no write permissions required

### How to Handle PSK Rotation?
A: For not all PSK keys will require manual updating. Let's ensure it's easy to rotate, but there will not be an easy automated method. I would like to be able to generate configuration via a private utility and then copy and paste those secretes into the airports.json and also have a human user deploy them at the remote site. 

**Recommended Process**:
1. Generate new PSK
2. Update environment variable on production server
3. Update remote site configuration
4. Reload VPN configuration (hot-reload)
5. Old connection will fail, new connection will establish
6. Monitor for successful reconnection

**Manual Process** (No Automation):
- Generate new PSK (utility script)
- Human updates `airports.json` on production server
- Human updates remote site configuration
- Reload VPN configuration
- Monitor for successful reconnection

**Wizard Utility**:
- Interactive wizard to guide human through:
  - Creating new VPN configuration
  - Deploying VPN configuration
  - Reconfiguring existing VPN
  - Removing VPN configuration
- Generates configuration snippets
- Provides step-by-step instructions
- Validates configuration before deployment

## 2. Configuration Updates

### Question
**Hot-reload VPN config on `airports.json` changes? Or require container restart?**
A: No hot reload required, but if it's easy I'm ok with that. Whatever the method, ensure it's easy via documentation, or a wizard utility. 

### Decision: **Hot-Reload Optional, Container Restart Acceptable**

**Approach**: Hot-reload if easy to implement, otherwise container restart

**Implementation Options**:
1. **Hot-Reload** (if easy):
   - VPN manager watches `airports.json` for changes
   - On change: validate, generate config, reload IPsec
   - Document process clearly

2. **Container Restart** (fallback):
   - Update `airports.json`
   - Restart VPN containers
   - Document restart process

**Documentation**:
- Clear instructions for both methods
- Wizard utility can guide through process
- Document which method is used

**User Preference**: 
- Hot-reload not required
- Easy documentation or wizard utility preferred

## 3. Remote Site Configuration

### Question
**Will you provide setup instructions for remote sites? Or automate remote site configuration somehow?**
A: Instructions need to be available to follow to set up the remote site. 

### Recommendation: **Provide Setup Instructions + Optional Automation**

**Primary Approach**: Manual configuration with detailed instructions

**Why**:
- Remote sites may have different network equipment
- Not all sites will have UniFi (though most will)
- Manual configuration ensures proper setup
- Easier to troubleshoot

**Deliverables**:
1. **Setup Guide** (`docs/VPN_REMOTE_SITE_SETUP.md`):
   - Step-by-step instructions for UniFi gateways
   - Generic instructions for other equipment
   - Troubleshooting guide
   - Configuration checklist

2. **Configuration Template**:
   - Pre-filled template with production server details
   - Site-specific values to fill in
   - Validation checklist

3. **Optional: Configuration Script** (future):
   - Script that remote sites can run
   - Validates their configuration
   - Provides feedback
   - Doesn't auto-configure (security)

**Support**:
- Document common issues
- Provide test commands
- Remote site validation endpoint (optional)

## 4. IPv6 Support

### Question
**Do remote sites have IPv6? Should we support IPv6 VPN connections?**

### Decision: **IPv4-Only Initially**

**Approach**: Focus on IPv4 for now, IPv6 as future enhancement

**Initial Implementation**: IPv4 only
- Most remote sites likely IPv4-only
- Simpler implementation
- IPv4 works for all use cases

**Future Enhancement**: Add IPv6 support
- When remote sites have IPv6
- Dual-stack support
- Configuration option in `airports.json`

**Configuration**:
```json
"vpn": {
  "enabled": true,
  "ip_version": "ipv4",  // or "ipv6" or "dual"
  // ...
}
```

## 5. Bandwidth Management

### Question
**Any QoS requirements? Rate limiting per VPN connection?**
A: No QOS requirements since this will be a VPN for a single connection use case.

### Recommendation: **Monitor First, Add QoS if Needed**

**Initial Implementation**: No QoS/rate limiting
- Camera fetching is low bandwidth
- Monitor actual usage first
- Add QoS if issues arise

**Monitoring**:
- Track bandwidth per VPN connection
- Alert on high usage
- Log bandwidth statistics

**Future QoS Options**:
- Traffic shaping per VPN connection
- Priority queuing
- Rate limiting per connection
- Configurable in `airports.json` if needed

**Configuration Example** (future):
```json
"vpn": {
  "enabled": true,
  "qos": {
    "max_bandwidth_mbps": 10,
    "priority": "normal"
  }
}
```

## Additional Recommendations

### 6. Connection Naming Convention

**Recommendation**: Use airport ID in connection name
- Format: `{airport_id}_vpn`
- Example: `kspb_vpn`, `kabc_vpn`
- Makes debugging easier
- Matches airport configuration

### 7. Logging Strategy

**Recommendation**: Structured logging with airport context

**Log Levels**:
- **INFO**: Connection established, configuration reloaded
- **WARNING**: Connection down, health check failed
- **ERROR**: Configuration errors, connection failures

**Log Fields**:
- Airport ID
- Connection name
- Timestamp
- Event type
- Details

**Example**:
```json
{
  "timestamp": "2025-01-26T12:00:00Z",
  "level": "warning",
  "airport": "kspb",
  "connection": "kspb_vpn",
  "event": "connection_down",
  "message": "VPN connection lost, attempting reconnect"
}
```

### 8. Health Check Strategy

**Recommendation**: Multi-level health checks

**Level 1: Interface Check** (every 30 seconds)
- Check if VPN interface exists and is up
- Fast, low overhead

**Level 2: IPsec Status** (every 60 seconds)
- Check `ipsec status` for connection state
- Medium overhead

**Level 3: Ping Test** (every 5 minutes)
- Ping remote gateway IP
- Validates actual connectivity
- Higher overhead, less frequent

**Failure Handling**:
- Level 1 failure → immediate reconnect attempt
- Level 2 failure → restart connection
- Level 3 failure → log warning, but don't restart (may be temporary)

### VPN Status on Status Page

**Requirement**: Display VPN status per airport on status page (if VPN enabled)

**Implementation**:
- Add VPN status component to `checkAirportHealth()` function
- Check VPN connection state via VPN manager API or status file
- Display status similar to other components (operational/degraded/down)
- Show connection uptime, last connected time
- Only show if VPN is enabled for that airport

**Status Display**:
- Green: VPN connected and healthy
- Yellow: VPN connected but health check failing
- Red: VPN disconnected or down
- Show last connection time
- Show connection duration (if connected) 

### 9. Configuration Validation

**Recommendation**: Validate before applying

**Validation Checks**:
1. Required fields present
2. PSK environment variable exists
3. Remote subnet format valid (CIDR)
4. Connection name unique
5. Encryption/hash algorithms valid
6. No conflicting subnets

**On Validation Failure**:
- Log detailed error
- Don't apply configuration
- Continue with existing config
- Alert administrator

### 10. Development/Testing Strategy

**Recommendation**: Mock VPN for development

**Development Mode**:
- Mock VPN server that simulates connections
- No real VPN connections
- Test routing logic
- Test configuration parsing

**Testing Mode**:
- Can connect to real VPN (KSPB site)
- Controlled test environment
- Validation before production

**Production Mode**:
- Full VPN functionality
- All monitoring enabled
- Production PSKs

**Configuration**:
```bash
VPN_MODE=development  # or "testing" or "production"
```

## Implementation Priority

### Phase 1: Core Functionality (MVP)
1. VPN server container (strongSwan)
2. Basic VPN manager service
3. Configuration parsing from `airports.json`
4. Single test connection (KSPB)
5. Basic health monitoring

### Phase 2: Reliability
1. Auto-reconnection logic
2. Health checks
3. Policy routing
4. PHP integration
5. Error handling

### Phase 3: Production Ready
1. Hot-reload configuration
2. Comprehensive logging
3. Monitoring/metrics
4. Documentation
5. Multiple site support

### Phase 4: Enhancements
1. IPv6 support
2. QoS/bandwidth management
3. Advanced monitoring
4. Automated testing
5. Performance optimization

## Decision Summary

| Question | Decision | Rationale |
|----------|----------|-----------|
| PSK Storage | Direct in airports.json | Simple, single source of truth, tightly guarded file |
| PSK Rotation | Manual with wizard utility | Human-guided process, no automation |
| Config Updates | Hot-reload (if easy) or restart | Documented process, wizard utility guidance |
| Remote Config | Manual with instructions | Step-by-step guide for remote sites |
| IPv6 | IPv4-only initially | Most sites IPv4, simpler implementation |
| QoS | Not required | Single connection use case, low bandwidth |
| Connection Naming | `{airport_id}_vpn` | Clear, matches config |
| Health Checks | Multi-level | Balance between accuracy and overhead |
| Status Page | VPN status per airport | Show VPN status if enabled |
| Dev Mode | Mock VPN server | Test without real connections |

## Next Steps

1. **Review and Approve Design**
   - Review VPN_DESIGN.md
   - Review this document
   - Approve recommendations

2. **Prototype Development**
   - Set up test strongSwan container
   - Test IPsec connection with UniFi gateway
   - Validate configuration generation
   - Test policy routing

3. **Implementation**
   - Start with Phase 1 (MVP)
   - Iterate based on testing
   - Add features incrementally

4. **Documentation**
   - Remote site setup guide
   - Configuration reference
   - Troubleshooting guide
   - API documentation (if needed)

