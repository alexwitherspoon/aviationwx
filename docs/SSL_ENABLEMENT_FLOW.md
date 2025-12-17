# SSL Certificate Enablement Flow

## Overview

vsftpd can start without SSL certificates, and SSL can be enabled later when certificates become available. This document explains the flow and how SSL management scripts are involved.

## SSL Enablement Scenarios

### Scenario 1: First Deployment (No Certificates)
1. Container starts
2. Entrypoint checks for certificates → **Not found**
3. vsftpd starts **without SSL** (graceful degradation)
4. Web service functions normally
5. FTP/FTPS available without encryption (FTP only)

### Scenario 2: Certificates Obtained Later
1. Certificates are obtained (via `setup-letsencrypt.sh` or manual certbot)
2. `setup-letsencrypt.sh` automatically calls `enable-vsftpd-ssl.sh`
3. `enable-vsftpd-ssl.sh`:
   - Validates certificates
   - Enables SSL in config files
   - Validates configuration
   - **Restarts vsftpd** (handles dual-instance mode)
4. vsftpd now runs **with SSL** (FTPS available)

### Scenario 3: Invalid Certificates
1. Container starts
2. Entrypoint checks for certificates → **Found but invalid**
3. vsftpd starts **without SSL** (graceful degradation)
4. Clear error messages guide user to fix certificates
5. After fixing, run `enable-vsftpd-ssl.sh` to enable SSL

## SSL Management Scripts

### 1. `setup-letsencrypt.sh`
**Purpose**: Obtain Let's Encrypt certificates for the first time

**Flow**:
1. Requests certificate from Let's Encrypt
2. If successful, automatically calls `enable-vsftpd-ssl.sh`
3. vsftpd is restarted with SSL enabled

**Usage**:
```bash
/usr/local/bin/setup-letsencrypt.sh
```

### 2. `enable-vsftpd-ssl.sh`
**Purpose**: Enable SSL in vsftpd when certificates are available

**Features**:
- ✅ Validates certificates before enabling SSL
- ✅ Handles both single-instance and dual-instance (dual-stack) modes
- ✅ Restarts vsftpd automatically after enabling SSL
- ✅ Validates configuration before restarting
- ✅ Rollback on failure

**Dual-Instance Restart Logic**:
1. Detects if multiple vsftpd instances are running
2. Stops all vsftpd processes
3. Starts IPv4 instance (if config exists)
4. Starts IPv6 instance (if config exists)
5. Verifies both instances are running

**Usage**:
```bash
/usr/local/bin/enable-vsftpd-ssl.sh
```

**When to Use**:
- After obtaining certificates for the first time
- After fixing invalid certificates
- After certificate renewal (if not automated)

### 3. Entrypoint Script (`docker-entrypoint.sh`)
**Purpose**: Container startup logic

**SSL Handling**:
- Checks for certificates on container start
- Validates certificates if found
- Enables SSL only if certificates are valid
- Starts vsftpd without SSL if certificates are missing/invalid
- Provides clear messages about SSL status

## Certificate Renewal

### Current State
- **No automatic renewal hook configured**
- Certificates must be renewed manually or via cron
- After renewal, `enable-vsftpd-ssl.sh` should be run to restart vsftpd

### Recommended Setup
1. **Add certbot renewal cron job**:
   ```bash
   0 3 * * * certbot renew --quiet --deploy-hook "/usr/local/bin/enable-vsftpd-ssl.sh"
   ```

2. **Or use certbot renewal hooks**:
   - Create `/etc/letsencrypt/renewal-hooks/deploy/enable-vsftpd-ssl.sh`
   - Symlink to `/usr/local/bin/enable-vsftpd-ssl.sh`
   - Certbot will automatically call it after renewal

## Workflow Summary

### Initial Deployment
```
Container Start → No Certificates → vsftpd starts without SSL → Web works, FTP only
```

### Certificate Obtained
```
setup-letsencrypt.sh → Certificates obtained → enable-vsftpd-ssl.sh → vsftpd restarted with SSL → FTPS available
```

### Certificate Renewal
```
certbot renew → Certificates renewed → enable-vsftpd-ssl.sh (via hook) → vsftpd restarted → FTPS continues
```

### Invalid Certificates Fixed
```
Container Start → Invalid Certificates → vsftpd starts without SSL
→ Certificates Fixed → enable-vsftpd-ssl.sh → vsftpd restarted with SSL → FTPS available
```

## Key Features

### ✅ Graceful Degradation
- vsftpd always starts, even without valid certificates
- Web service never blocked by SSL issues
- Clear error messages guide recovery

### ✅ Automatic Recovery
- `enable-vsftpd-ssl.sh` can be run anytime to enable SSL
- Handles both single and dual-instance modes
- Validates everything before making changes

### ✅ Dual-Instance Support
- Properly restarts both IPv4 and IPv6 instances
- Verifies both instances are running
- Handles failures gracefully

## Integration Points

1. **Container Startup**: Entrypoint script handles initial SSL enablement
2. **Certificate Setup**: `setup-letsencrypt.sh` automatically enables SSL
3. **Certificate Renewal**: Should call `enable-vsftpd-ssl.sh` (currently manual)
4. **Manual Enablement**: Can run `enable-vsftpd-ssl.sh` anytime

## Next Steps

1. **Add certbot renewal hook** to automatically enable SSL after renewal
2. **Add cron job** for certificate renewal
3. **Test dual-instance restart** in production
4. **Monitor SSL enablement** in logs

