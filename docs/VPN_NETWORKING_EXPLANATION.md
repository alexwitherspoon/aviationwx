# VPN Networking Issue Explanation: "unable to resolve %any"

## The Problem

When testing VPN connections locally, we see the error:
```
[IKE] unable to resolve %any, initiate aborted
```

This error occurs during connection initiation and has been misunderstood as a Docker networking issue. However, it's actually a **strongSwan configuration issue**.

## Root Cause Analysis

### Understanding strongSwan Connection Roles

In IPsec VPN connections, there are two roles:

1. **Initiator** - The side that starts the connection (client)
2. **Responder** - The side that waits for connections (server)

### The Configuration Problem

**Server Configuration (Before Fix):**
```conf
conn test_vpn
    auto=start        # ❌ WRONG - tries to initiate
    right=%any        # Accepts from any IP
```

**Why This Fails:**
- `auto=start` tells strongSwan to **immediately try to initiate** the connection
- `right=%any` means "connect to any IP address"
- You **cannot initiate a connection TO "any IP"** - you need a specific address
- Result: `unable to resolve %any, initiate aborted`

### The Fix

**Server Configuration (After Fix):**
```conf
conn test_vpn
    auto=add          # ✅ CORRECT - wait for incoming connections
    right=%any        # Accepts from any IP (correct for responder)
```

**Client Configuration:**
```conf
conn test_vpn
    auto=start        # ✅ CORRECT - initiate connection
    right=127.0.0.1   # ✅ CORRECT - specific server IP
    leftid=@kspb.remote  # ✅ Must match server's rightid
```

## strongSwan `auto` Options Explained

| Option | Meaning | Use Case |
|--------|---------|----------|
| `auto=start` | Immediately try to initiate connection | **Client/Initiator** |
| `auto=add` | Add config, wait for incoming connection | **Server/Responder** |
| `auto=route` | Start when traffic matches policy | Advanced use cases |
| `auto=ignore` | Don't start automatically | Manual control |

## Identity Matching

For a connection to work, identities must match:

- **Server's `rightid`** must equal **Client's `leftid`**
- **Server's `leftid`** must equal **Client's `rightid`**

Example:
```
Server: rightid=@kspb.remote
Client: leftid=@kspb.remote  ✅ Match!
```

## Network Mode: Host Considerations

With `network_mode: host`:

### How It Works
- Both containers share the **host's network namespace**
- They can communicate via `127.0.0.1` (localhost)
- Both see the same network interfaces
- Both can bind to the same ports (causes conflicts if both try)

### Why It's Needed for IPsec
- IPsec requires direct kernel access for:
  - XFRM policies (encryption policies)
  - Routing table modifications
  - Network interface management
- Bridge networking doesn't provide this access

### macOS Docker Desktop Limitations
- `network_mode: host` works differently on macOS
- Docker Desktop runs in a VM
- Host networking is actually VM networking
- Some kernel features may not work identically
- This can cause connection issues even with correct config

## The Real Issue: Configuration, Not Networking

The "unable to resolve %any" error is **NOT** a networking problem. It's a configuration problem:

1. ✅ **Network connectivity works** - containers can ping each other
2. ✅ **Ports are accessible** - server is listening on 500/4500
3. ✅ **Host networking is functional** - both see same network
4. ❌ **Configuration was wrong** - server had `auto=start` instead of `auto=add`

## Current Status

After fixing the configuration:

- ✅ Server uses `auto=add` (waits for connections)
- ✅ Client uses `auto=start` (initiates connection)
- ✅ Identities match (`@kspb.remote`)
- ✅ Server listening on correct ports
- ⚠️ Connection still not establishing (likely macOS Docker Desktop limitation)

## Why Connection Still Fails on macOS

Even with correct configuration, the connection may still fail on macOS due to:

1. **Docker Desktop VM networking** - Not true host networking
2. **Kernel XFRM limitations** - IPsec policies may not work identically
3. **Network namespace differences** - VM vs native Linux

## Production Expectations

On Linux (production):
- ✅ True host networking
- ✅ Full kernel XFRM support
- ✅ Proper network namespace
- ✅ Connection should work with correct config

## The "Left is Other Host, Swapping Ends" Issue

When strongSwan detects that a configuration is written from the "other host's" perspective, it automatically swaps left and right. This can cause confusion:

**Client Logs Show:**
```
[CFG] left is other host, swapping ends
```

This means strongSwan detected the config perspective and swapped it. However, this can cause issues when:
- Client config has `right=127.0.0.1` (specific IP)
- Server config has `right=%any` (any IP)
- After swapping, the matching logic may see `%any` and fail

## Why Server Still Receives Initiate Commands

Even with `auto=add`, the server logs show:
```
[CFG] received stroke: initiate 'test_vpn'
```

This suggests:
1. Something is explicitly sending "initiate" commands (not from `auto=start`)
2. Could be from VPN manager checking status
3. Could be from strongSwan's internal logic
4. The server shouldn't process these if `auto=add` is set correctly

## Network Mode: Host Reality

With `network_mode: host`:
- ✅ Both containers share the same network namespace
- ✅ They can communicate via `127.0.0.1`
- ✅ Ports are accessible
- ✅ Network connectivity works

**The issue is NOT networking** - it's strongSwan's connection matching and initiation logic when dealing with `%any` and perspective swapping.

## Summary

**The "unable to resolve %any" error is caused by:**
1. Configuration perspective issues (left/right swapping)
2. Server receiving initiate commands (even with `auto=add`)
3. strongSwan trying to match configurations with `%any` on one side

**Fixes Applied:**
- ✅ Changed server to `auto=add` (wait for connections)
- ✅ Fixed identity mismatch (`@kspb.remote`)
- ✅ Client correctly uses `auto=start` (initiate)

**Remaining Investigation:**
- Why server still receives initiate commands
- Whether `auto=route` would work better
- Whether macOS Docker Desktop affects connection matching logic

