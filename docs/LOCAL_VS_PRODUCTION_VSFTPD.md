# Local vs Production: vsftpd Startup Differences

## Key Differences

### 1. **Network Configuration**

#### Local (`docker-compose.local.yml`)
- **Network Mode**: Bridge networking (default)
- **Port Mapping**: 
  - `12121:2121` (FTP/FTPS) - mapped to avoid conflicts
  - `12222:2222` (SFTP) - mapped to avoid conflicts
- **DNS Resolution**: 
  - Tries to resolve `upload.aviationwx.org` (line 140)
  - **Likely to FAIL locally** (domain won't resolve to localhost)
  - Falls back to single instance mode (line 312-335)

#### Production (`docker-compose.prod.yml`)
- **Network Mode**: `host` networking
- **Port Mapping**: None (ports directly on host)
  - Port `2121` (FTP/FTPS) directly on host
  - Port `2222` (SFTP) directly on host
  - Ports `50000-50099` (FTP passive mode) directly on host
- **DNS Resolution**: 
  - Successfully resolves `upload.aviationwx.org` to production IPs
  - IPv4: `178.128.130.116`
  - IPv6: `2604:a880:2:d1::e88b:3001`
  - Starts **dual instances** (IPv4 + IPv6)

### 2. **SSL Certificate Availability**

#### Local
- **No SSL certificates mounted**
- `/etc/letsencrypt` directory doesn't exist in container
- **vsftpd starts WITHOUT SSL** (line 267-269)
- No SSL validation or enablement

#### Production
- **SSL certificates mounted**: `/etc/letsencrypt:/etc/letsencrypt:rw`
- Certificates exist at: `/etc/letsencrypt/live/upload.aviationwx.org/`
- **vsftpd starts WITH SSL** (if certificates are valid)
- SSL validation happens (lines 186-201)
- SSL is enabled in config files (lines 204-264)

### 3. **vsftpd Startup Flow**

#### Local Startup Flow
```
1. DNS Resolution: upload.aviationwx.org → FAILS (domain doesn't resolve locally)
2. IPV4_RESOLVED = "" (empty)
3. IPV6_RESOLVED = "" (empty)
4. SSL Check: /etc/letsencrypt/... → NOT FOUND
5. SSL: Skipped (no certificates)
6. vsftpd Start: Falls back to single IPv4 instance (line 312-335)
   - Uses pasv_address=0.0.0.0 (placeholder)
   - Starts ONE instance only
7. Process Check: sleep 1 → kill -0
```

#### Production Startup Flow
```
1. DNS Resolution: upload.aviationwx.org → SUCCESS
   - IPv4: 178.128.130.116
   - IPv6: 2604:a880:2:d1::e88b:3001
2. IPV4_RESOLVED = "yes"
3. IPV6_RESOLVED = "yes"
4. SSL Check: /etc/letsencrypt/... → FOUND
5. SSL Validation: 
   - Check if readable
   - Validate certificate format (openssl x509)
   - Validate key format (openssl rsa)
6. SSL Enablement: 
   - Enable SSL in vsftpd_ipv4.conf
   - Enable SSL in vsftpd_ipv6.conf
7. vsftpd Start: TWO instances
   - IPv4 instance (line 275-291)
   - IPv6 instance (line 293-309)
8. Process Check: sleep 1 → kill -0 (for each instance)
```

### 4. **Critical Difference: SSL Certificate Loading**

#### Why Production Fails But Local Works

**Local**:
- ✅ No SSL certificates → vsftpd starts without SSL
- ✅ No SSL validation → no certificate loading errors
- ✅ Simpler config → fewer failure points

**Production**:
- ❌ SSL certificates exist → SSL is enabled
- ❌ SSL certificate loading happens at **runtime** (when vsftpd starts)
- ❌ If certificates are invalid/unreadable, vsftpd **crashes immediately**
- ❌ Process starts, tries to load SSL certs, fails, exits
- ❌ `kill -0` check happens AFTER process has already crashed

### 5. **The Race Condition in Production**

**Timeline**:
```
T+0.0s: vsftpd /etc/vsftpd/vsftpd_ipv4.conf &  (starts in background)
T+0.0s: VSFTPD_IPV4_PID=$!  (captures PID)
T+0.1s: vsftpd process begins parsing config
T+0.2s: vsftpd tries to load SSL certificate
T+0.3s: SSL certificate load FAILS (invalid/unreadable)
T+0.3s: vsftpd process EXITS (crashes)
T+1.0s: sleep 1 completes
T+1.0s: kill -0 $VSFTPD_IPV4_PID → FAILS (process already dead)
```

**The Problem**:
- Fixed `sleep 1` is too long - process crashes in ~0.3s
- But we don't know WHY it crashed until we check
- Config validation happens AFTER failure (too late)

### 6. **Why Local Testing Doesn't Catch This**

1. **No SSL**: Local doesn't test SSL certificate loading
2. **Different DNS**: Local doesn't test DNS resolution
3. **Single Instance**: Local only tests one vsftpd instance
4. **Different Network**: Local uses bridge, production uses host
5. **No Certificate Validation**: Local never validates certificates

## Testing Locally to Reproduce Production Issue

### Option 1: Mount SSL Certificates Locally
```yaml
# docker-compose.override.yml (local)
volumes:
  - /path/to/local/certs:/etc/letsencrypt:ro
```

### Option 2: Create Test Certificates
```bash
# Generate self-signed certs for testing
mkdir -p /tmp/test-certs/live/upload.aviationwx.org
openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
  -keyout /tmp/test-certs/live/upload.aviationwx.org/privkey.pem \
  -out /tmp/test-certs/live/upload.aviationwx.org/fullchain.pem
```

### Option 3: Test with Invalid Certificates
```bash
# Create invalid certificate to test error handling
echo "invalid cert" > /tmp/test-certs/live/upload.aviationwx.org/fullchain.pem
echo "invalid key" > /tmp/test-certs/live/upload.aviationwx.org/privkey.pem
```

### Option 4: Test DNS Resolution Locally
```bash
# Add to /etc/hosts for local testing
127.0.0.1 upload.aviationwx.org
```

## Recommendations

### 1. **Add Config Validation Before Start** (High Priority)
- Validate config with `vsftpd -olisten=NO` BEFORE starting
- This will catch SSL certificate errors early
- Works in both local and production

### 2. **Improve Local Testing** (Medium Priority)
- Add option to mount SSL certificates locally
- Test with both valid and invalid certificates
- Test DNS resolution locally

### 3. **Better Error Messages** (Medium Priority)
- Log SSL certificate validation results
- Show actual vsftpd error output
- Distinguish between config errors and runtime errors

### 4. **Production-Specific Checks** (Low Priority)
- Verify SSL certificates are valid before enabling SSL
- Check certificate permissions
- Validate certificate/key match

## Summary

**Local vs Production Differences**:
1. ✅ **Local**: No SSL → vsftpd starts without SSL → works
2. ❌ **Production**: SSL enabled → vsftpd tries to load certs → fails → crashes
3. ✅ **Local**: Single instance (fallback mode)
4. ❌ **Production**: Dual instances (IPv4 + IPv6)
5. ✅ **Local**: DNS resolution fails → fallback
6. ❌ **Production**: DNS resolution succeeds → dual-stack

**Root Cause**: SSL certificate loading happens at runtime, and if it fails, vsftpd crashes immediately. The fixed `sleep 1` doesn't catch this because the process crashes faster than we check.

**Solution**: Validate config (including SSL certificates) BEFORE starting vsftpd, not after it fails.

