# VPN Feature - Implementation Plan

## Research Complete ✅

### strongSwan Routing: **Use Automatic Routing**
- ✅ strongSwan handles routing automatically via routing table 220
- ✅ No manual routing rules needed
- ✅ Configure `rightsubnet` in ipsec.conf per connection
- ✅ Traffic automatically routed when connection establishes
- ✅ Minimal IP conflict risk (separate routing table)

### Language Choice: **Python 3.x (Standard Library Only)**
- ✅ Maximum LLM support
- ✅ Simple and maintainable
- ✅ Minimal dependencies
- ✅ Perfect for system operations

## Implementation Phases

### Phase 1: Local Testing & Mock Setup (Current)

**Goals:**
- Test `network_mode: host` locally
- Set up mock VPN client for testing
- Verify strongSwan automatic routing works
- Build VPN wizard for KSPB configuration

**Tasks:**
1. ✅ Research strongSwan routing (DONE)
2. ✅ Choose implementation language (DONE - Python)
3. ⏳ Create feature branch (IN PROGRESS)
4. ⏳ Test `network_mode: host` locally
5. ⏳ Set up mock VPN client
6. ⏳ Build VPN wizard utility
7. ⏳ Test locally before production

### Phase 2: Core VPN Infrastructure

**Tasks:**
1. Create VPN server Docker container (strongSwan)
2. Create VPN manager service (Python)
3. Configuration parsing from airports.json
4. Generate strongSwan config files
5. Basic connection monitoring
6. Status file generation

### Phase 3: Integration & Testing

**Tasks:**
1. Integrate with web container
2. Test with real KSPB site
3. Policy routing verification
4. Error handling
5. Auto-reconnection logic

### Phase 4: Production Ready

**Tasks:**
1. Status page integration
2. Comprehensive logging
3. Documentation
4. Remote site setup guide (before GitHub push)

## Branch Strategy

- **Branch**: `feature/vpn-implementation`
- **Commits**: Clean, logical commits
- **Push**: Only after approval
- **Testing**: Local first, then production when confident

## Next Immediate Steps

1. ✅ Research complete
2. ⏳ Create feature branch
3. ⏳ Test network_mode: host locally
4. ⏳ Set up mock VPN client
5. ⏳ Build VPN wizard

## Key Decisions Made

✅ Use strongSwan automatic routing (no manual rules)  
✅ Python 3.x for VPN manager (standard library only)  
✅ Test locally first, production when confident  
✅ Mock VPN client for iteration  
✅ Wizard ready for KSPB manual testing  
✅ Documentation before GitHub push  

