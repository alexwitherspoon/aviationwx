# VPN Feature - Additional Research Needed

## Research Status

### ✅ Completed
- strongSwan automatic routing capabilities
- Policy routing approach (use automatic routing)
- Language choice (Python 3.x)
- Network mode requirements (host mode for IPsec)

### ⚠️ Additional Research Needed

## 1. Docker Container Setup for strongSwan

**Question**: What's the best strongSwan Docker image and configuration for production?

**Research Needed:**
- Which strongSwan Docker image to use? (official, community, custom)
- Base image considerations (alpine vs debian)
- Required packages and dependencies
- Configuration file locations and structure
- How to handle strongSwan configuration reload
- Container health checks for strongSwan
- Logging configuration

**Why Important:**
- Need production-ready container setup
- Must integrate with existing docker-compose
- Need to understand configuration management

**Action**: Research strongSwan Docker images and best practices

---

## 2. Status File Update Mechanism

**Question**: Is cron with `docker exec` the best approach, or are there better alternatives?

**Current Plan**: Cron job runs `docker exec aviationwx-vpn-manager /usr/local/bin/update-vpn-status.sh`

**Research Needed:**
- Better alternatives to cron + docker exec?
- Should VPN manager update status file directly (internal to container)?
- How to handle container restarts gracefully?
- Should status file be updated on-demand or periodically?
- File locking considerations for concurrent access

**Alternatives to Consider:**
- VPN manager updates status file directly (no cron needed)
- Use Docker healthcheck to trigger updates
- Webhook/API endpoint for status updates
- Inotify-based file watching

**Why Important:**
- Cron + docker exec is fragile (container might be down)
- Need reliable status updates
- Should be simple and maintainable

**Action**: Research best practices for status file updates in Docker

---

## 3. Mock VPN Client Setup

**Question**: How to set up a mock VPN client for local testing?

**Research Needed:**
- How to create a test strongSwan client container
- Configuration for connecting to our VPN server
- How to simulate remote site network
- Testing routing with mock client
- How to verify automatic routing works

**Why Important:**
- Need to test locally before real sites
- Fast iteration without remote dependencies
- Verify routing works correctly

**Action**: Research strongSwan client setup and testing strategies

---

## 4. Container State Persistence

**Question**: How to handle VPN connection state across container restarts?

**Research Needed:**
- Do VPN connections persist across container restarts?
- How does strongSwan handle reconnection after restart?
- Should we save connection state to disk?
- How to handle airports.json changes during runtime?
- Graceful shutdown and reconnection

**Why Important:**
- Containers restart when airports.json updates
- Need connections to re-establish automatically
- Should be transparent to users

**Action**: Research strongSwan state management and reconnection

---

## 5. Integration with Existing Docker Compose

**Question**: How to properly integrate VPN services into existing docker-compose setup?

**Research Needed:**
- How to add services to existing docker-compose.yml
- Volume mount strategies (shared vs separate)
- Network configuration (host mode with other services)
- Dependency management (depends_on, healthchecks)
- Environment variable management
- Production vs development configurations

**Why Important:**
- Must work with existing setup
- Should not break existing services
- Need clean integration

**Action**: Review existing docker-compose structure and plan integration

---

## 6. Testing network_mode: host Locally

**Question**: How to test network_mode: host on macOS/Windows (Docker Desktop)?

**Research Needed:**
- Does Docker Desktop support network_mode: host? (Linux only)
- How to test on macOS/Windows?
- Alternative testing approaches
- Port conflict detection
- Firewall considerations

**Why Important:**
- Need to test locally before production
- Docker Desktop limitations on macOS/Windows
- Must verify no conflicts

**Action**: Research Docker Desktop limitations and testing strategies

---

## 7. VPN Manager Service Architecture

**Question**: How should the VPN manager service be structured?

**Research Needed:**
- Should it be a long-running service or periodic script?
- How to handle configuration changes (watch file, poll, or restart)?
- Error handling and recovery strategies
- Logging and monitoring integration
- Resource usage and limits

**Why Important:**
- Need reliable service architecture
- Must handle edge cases
- Should be maintainable

**Action**: Research service patterns for configuration management

---

## 8. Camera URL DNS Resolution

**Question**: How to handle hostname-based camera URLs that resolve to private IPs?

**Research Needed:**
- Should we resolve DNS before checking if VPN needed?
- How to handle DNS resolution in web container?
- Performance implications of DNS resolution
- Caching DNS results
- Edge cases (multiple IPs, IPv6, etc.)

**Why Important:**
- Camera URLs might use hostnames, not IPs
- Need to determine if VPN required
- Performance considerations

**Action**: Research DNS resolution strategies and performance

---

## 9. Resource Limits and Scaling

**Question**: What resource limits should we set for VPN containers?

**Research Needed:**
- Memory usage per VPN connection
- CPU usage for encryption/decryption
- Network bandwidth considerations
- File descriptor limits
- strongSwan default limits and tuning
- Container resource limits (memory, CPU)

**Why Important:**
- Need to support 100+ connections
- Must not exhaust system resources
- Should be predictable

**Action**: Research strongSwan resource usage and container limits

---

## 10. Error Handling and Recovery

**Question**: How to handle various failure scenarios gracefully?

**Research Needed:**
- What happens if strongSwan fails to start?
- How to handle invalid configuration?
- Recovery from network issues
- Handling remote site disconnections
- Logging and alerting strategies

**Why Important:**
- Must be resilient
- Should recover automatically
- Need good observability

**Action**: Research error handling patterns and recovery strategies

---

## Priority Ranking

### 🔴 High Priority (Before Implementation)
1. **Docker Container Setup** - Need to know how to build/configure containers
2. **Status File Update Mechanism** - Need reliable status updates
3. **Mock VPN Client Setup** - Need to test locally
4. **Integration with Docker Compose** - Must work with existing setup

### 🟡 Medium Priority (During Implementation)
5. **Container State Persistence** - Important but can iterate
6. **VPN Manager Architecture** - Can refine during implementation
7. **Testing network_mode: host** - Need to verify but can test as we go

### 🟢 Low Priority (Can Defer)
8. **Camera URL DNS Resolution** - Edge case, can handle later
9. **Resource Limits** - Can tune after initial implementation
10. **Error Handling** - Can improve iteratively

---

## Recommended Research Order

1. **Docker Container Setup** (30 min)
   - Find best strongSwan Docker image
   - Understand configuration structure
   - Plan container setup

2. **Status File Update Mechanism** (30 min)
   - Research alternatives to cron + docker exec
   - Decide on approach
   - Plan implementation

3. **Mock VPN Client Setup** (1 hour)
   - Research strongSwan client configuration
   - Plan test setup
   - Create test configuration

4. **Integration with Docker Compose** (30 min)
   - Review existing docker-compose files
   - Plan service additions
   - Verify compatibility

**Total Estimated Time**: 2-3 hours of research

---

## Next Steps

1. Research Docker container setup for strongSwan
2. Research status file update alternatives
3. Plan mock VPN client setup
4. Review docker-compose integration
5. Begin implementation with this knowledge

