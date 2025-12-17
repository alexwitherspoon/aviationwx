# vsftpd Health Check - Options and Implementation

## Problem
The `vsftpd -olisten=NO` command hangs indefinitely, blocking container startup and preventing Apache from starting.

## Options Considered

### Option 1: Process Check Only
- Start vsftpd, wait 1-2s, check if process is running
- **Pros**: Fast, simple
- **Cons**: Doesn't verify vsftpd is actually listening/working

### Option 2: Port Listening Check
- Start vsftpd, wait 2-3s, check if port 2121 is listening
- **Pros**: Verifies vsftpd is actually listening (more reliable)
- **Cons**: Slightly slower

### Option 3: Port Connection Test
- Start vsftpd, wait 2-3s, try to connect to port 2121
- **Pros**: Most thorough - verifies port is accepting connections
- **Cons**: Requires netcat or bash TCP support, slightly slower

### Option 4: Config File Validation Only
- Check config file exists/readable, basic syntax checks
- **Pros**: Fast, catches obvious config errors
- **Cons**: Doesn't catch all config errors, doesn't verify vsftpd starts

### Option 5: Hybrid Approach (✅ IMPLEMENTED)
- Basic config file check (exists, readable)
- Start vsftpd in background
- Poll for 3 seconds (6 iterations of 0.5s)
- Check if process is running (`kill -0`)
- Check if port is listening (`netstat`/`ss`)
- **Pros**: Fast startup, catches both process and port issues, no hanging
- **Cons**: Slightly more complex

## Implementation Details

### Removed
- `vsftpd -olisten=NO` config validation (hanging command)
- Timeout workaround (no longer needed)

### Added
- Basic config file validation (exists, readable)
- Process health check with polling loop
- Port listening check (verifies vsftpd actually bound to port)
- Graceful degradation (warns but continues if vsftpd fails)

### Health Check Flow
1. **Config File Check**: Verify file exists and is readable
2. **Start vsftpd**: Launch in background, capture PID
3. **Poll for Health** (up to 3 seconds):
   - Check if process is still running (`kill -0`)
   - Check if port 2121 is listening (`netstat`/`ss`)
   - If both pass, mark as healthy
4. **Handle Failures**:
   - If process dies: Log warning, return failure (non-fatal)
   - If process runs but port not listening: Log warning, continue (may bind later)
   - If both pass: Success!

## Benefits

1. **No Hanging**: Removed the problematic `vsftpd -olisten=NO` command
2. **Faster Startup**: No 5-second timeout wait
3. **More Reliable**: Actually verifies vsftpd is listening, not just running
4. **Better Diagnostics**: Clear messages about what's wrong (process vs port)
5. **Graceful Degradation**: Container continues to start even if vsftpd fails

## Testing

The new approach:
- ✅ Starts vsftpd without hanging
- ✅ Verifies process is running
- ✅ Verifies port is listening
- ✅ Provides clear error messages
- ✅ Allows container to continue if vsftpd fails

## Comparison

| Approach | Speed | Reliability | Hanging Risk |
|----------|-------|-------------|--------------|
| Old (`-olisten=NO`) | Slow (5s timeout) | Medium | ❌ High |
| Process Check Only | Fast | Low | ✅ None |
| Port Check Only | Medium | High | ✅ None |
| **Hybrid (New)** | **Fast** | **High** | **✅ None** |

## Code Changes

### Before
```bash
# Validate config (hangs!)
if ! timeout 5 vsftpd -olisten=NO "$config_file" >/dev/null 2>&1; then
    # ... error handling
fi

# Start vsftpd
vsftpd "$config_file" &
sleep 1
if ! kill -0 $pid; then
    # ... error handling
fi
```

### After
```bash
# Basic config check (fast, no hanging)
if [ ! -f "$config_file" ] || [ ! -r "$config_file" ]; then
    return 1
fi

# Start vsftpd
vsftpd "$config_file" &
pid=$!

# Poll for health (process + port)
while [ $iteration -lt 6 ]; do
    if kill -0 $pid && (netstat | grep :2121 || ss | grep :2121); then
        # Healthy!
        break
    fi
    sleep 0.5
done
```

