# VPN Feature - Final Recommendations

## ✅ Research Complete - Ready for Implementation

### 1. Policy Routing: **Use strongSwan's Automatic Routing** ✅

**Recommendation**: Use strongSwan's built-in automatic routing - no manual routing rules needed.

**How It Works:**
- strongSwan automatically installs routes into routing table 220
- Configure `rightsubnet` in ipsec.conf for each connection
- Traffic to that subnet is automatically routed through the VPN tunnel
- No manual `ip route` or `ip rule` commands needed

**Configuration Example:**
```conf
conn kspb_vpn
    rightsubnet=192.168.1.0/24  # All traffic to this subnet uses VPN automatically
```

**Benefits:**
- ✅ Highly automated - zero manual routing
- ✅ Minimal IP conflict risk (uses separate routing table 220)
- ✅ Simple configuration - just specify subnet
- ✅ Automatic route management (install/remove on connect/disconnect)

**Implementation:**
- VPN manager generates ipsec.conf with `rightsubnet` per connection
- strongSwan handles all routing automatically
- Web container makes normal requests - routing happens transparently

**IP Conflict Minimization:**
- Separate routing table (220) - isolated from main routing
- Subnet-based routing (not individual IPs)
- Wizard validates no overlapping subnets
- Each airport has its own subnet

### 2. Language Choice: **Python 3.x (Standard Library Only)** ✅

**Recommendation**: Use Python 3.x with standard library only.

**Rationale:**
- ✅ Maximum LLM support (most training data)
- ✅ Simple and maintainable
- ✅ Minimal dependencies (standard library sufficient)
- ✅ Excellent for system operations (subprocess, JSON, file I/O)
- ✅ Common in DevOps tools

**Implementation:**
- Use `python:3.11-slim` or `python:3.11-alpine` base image
- No external packages needed
- Standard library handles: JSON, subprocess, file I/O, logging

### 3. Network Mode: Host Testing ✅

**Plan**: Test `network_mode: host` locally first.

**Approach:**
- Test locally on laptop
- Verify no port conflicts (UDP 500, 4500)
- Ensure compatibility with existing services
- Only deploy to production when confident

### 4. Mock VPN Client Setup ✅

**Plan**: Start with mock VPN client for rapid iteration.

**Benefits:**
- Fast iteration without real remote site
- Test routing, configuration, status updates
- Verify strongSwan setup works
- Then move to real KSPB site

### 5. VPN Wizard ✅

**Plan**: Build wizard ready for KSPB manual testing.

**Features:**
- Generate validated JSON snippets
- Validate against existing airports.json
- Output remote site configuration
- Ready for manual KSPB site configuration

### 6. Documentation ✅

**Plan**: Create remote site setup guide before GitHub push.

**Timing:**
- Wait until implementation is working
- Create guide before pushing to GitHub
- Include UniFi-specific and generic IPsec instructions

## Implementation Strategy

### Phase 1: Local Testing (Current)

1. ✅ Research complete
2. ✅ Feature branch created: `feature/vpn-implementation`
3. ⏳ Test `network_mode: host` locally
4. ⏳ Set up mock VPN client
5. ⏳ Build VPN wizard
6. ⏳ Test automatic routing

### Phase 2: Core Infrastructure

1. VPN server container (strongSwan)
2. VPN manager service (Python)
3. Configuration generation
4. Status file system

### Phase 3: Integration

1. Web container integration
2. Real KSPB site testing
3. Policy routing verification

### Phase 4: Production Ready

1. Status page integration
2. Documentation
3. Remote site setup guide

## Key Technical Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Routing | strongSwan automatic | Highly automated, minimal conflicts |
| Language | Python 3.x (stdlib) | LLM-friendly, simple, minimal deps |
| Network Mode | host (test locally first) | Required for IPsec, test before production |
| Testing | Mock client first | Fast iteration, then real site |
| Documentation | Before GitHub push | Complete before public |

## Next Steps

1. **Test `network_mode: host` locally**
   - Verify no conflicts with existing services
   - Check UDP 500, 4500 availability

2. **Set up mock VPN client**
   - Create test strongSwan client container
   - Test connection establishment
   - Verify automatic routing works

3. **Build VPN wizard**
   - Python CLI script
   - JSON validation
   - Configuration generation
   - Ready for KSPB testing

4. **Test locally**
   - Verify all components work
   - Test routing automatically
   - Iterate quickly

5. **Real KSPB site**
   - Configure using wizard
   - Test real connection
   - Verify camera access

## Branch & Commit Strategy

- **Branch**: `feature/vpn-implementation` ✅ Created
- **Commits**: Clean, logical commits
- **Push**: Only after approval
- **Testing**: Local first, production when confident

## Summary

✅ **All research complete**  
✅ **Recommendations finalized**  
✅ **Feature branch created**  
✅ **Ready to begin implementation**  

**Key Insight**: strongSwan's automatic routing eliminates the need for manual routing rules, making the implementation much simpler and more automated than initially planned.

