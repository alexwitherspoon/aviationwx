# VPN Wizard Utility Specification

## Overview

The VPN wizard is a CLI utility that helps administrators create, validate, and deploy VPN configurations for airport sites. It generates validated JSON snippets that can be added to `airports.json` and deployed separately.

## Workflow

```
1. Run wizard → 2. Get validated JSON → 3. Deploy separately → 4. Containers restart
```

The wizard does NOT modify `airports.json` directly - it outputs validated snippets for manual deployment.

## Features

### 1. Read Existing Configuration
- Loads `airports.json` from configured path
- Validates existing file is valid JSON
- Shows current VPN configurations (if any)
- Identifies which airports already have VPN configured

### 2. Interactive Configuration
- Prompts for airport ID
- Validates airport ID format (3-4 lowercase alphanumeric)
- Checks if airport exists in config
- Prompts for remote subnet (CIDR format)
- Optional: Custom connection name, encryption settings
- Generates secure PSK automatically

### 3. Validation
- **JSON Validation**: Merges snippet with existing config (in memory) and validates JSON syntax
- **Schema Validation**: Validates required fields, format, types
- **Conflict Detection**: 
  - Checks for duplicate connection names
  - Validates remote subnet format
  - Ensures airport exists in config
- **Output**: Clear validation results (✓ or ✗)

### 4. Output Generation
- **JSON Snippet**: Validated configuration block ready to add to `airports.json`
- **Remote Site Instructions**: Step-by-step UniFi configuration with pre-filled values
- **Deployment Checklist**: Steps to deploy the configuration

## Usage

```bash
# Run wizard
./scripts/admin/vpn-wizard

# Or with config path
./scripts/admin/vpn-wizard --config /path/to/airports.json

# Generate PSK only
./scripts/admin/vpn-wizard --psk-only
```

## Example Session

```
$ ./scripts/admin/vpn-wizard

VPN Configuration Wizard
=======================

Reading airports.json... ✓

Available airports:
  - kspb (Scappoose Industrial Airpark)
  - kabc (Another Airport)

Which airport needs VPN configuration? kspb

Remote subnet (CIDR format, e.g., 192.168.1.0/24): 192.168.1.0/24

Connection name [kspb_vpn]: 

IKE version [2]: 

Encryption [aes256gcm128]: 

DH Group [14]: 

Generating secure PSK... ✓

Validating configuration... ✓
  - JSON syntax: ✓
  - Schema validation: ✓
  - No conflicts: ✓

========================================
VPN Configuration Snippet
========================================

Add this to airports.json under the "kspb" airport entry:

{
  "vpn": {
    "enabled": true,
    "type": "ipsec",
    "connection_name": "kspb_vpn",
    "remote_subnet": "192.168.1.0/24",
    "psk": "a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6",
    "ike_version": "2",
    "encryption": "aes256gcm128",
    "dh_group": "14"
  }
}

========================================
Remote Site Configuration Instructions
========================================

For UniFi Gateway at remote site:

1. Navigate to Settings > VPN > Site-to-Site VPN
2. Add new VPN connection:
   - Type: Manual IPsec
   - Peer IP: 203.0.113.1 (production server static IP)
   - Local WAN IP: [Your remote site public IP or DDNS hostname]
   - Remote Subnets: 0.0.0.0/0 (or specific subnets if needed)
   - Pre-Shared Key: a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6
   - IKE Version: 2
   - Encryption: AES-256-GCM
   - Hash: SHA-256
   - DH Group: 14

3. Save and enable the VPN connection

========================================
Deployment Checklist
========================================

[ ] Add VPN configuration snippet to airports.json
[ ] Validate airports.json syntax (jsonlint or similar)
[ ] Deploy airports.json to production server
[ ] Restart Docker containers (docker compose restart)
[ ] Verify VPN connection establishes (check logs)
[ ] Configure remote site UniFi gateway (see instructions above)
[ ] Test camera access via VPN
[ ] Monitor status page for VPN connection status

========================================
```

## Implementation Details

### File Structure

```
scripts/
  admin/
    vpn-wizard          # Main wizard script (bash or Python)
    lib/
      config-validator.php  # Reuse existing validation logic
      psk-generator.sh     # PSK generation
```

### Validation Logic

The wizard should reuse the existing validation logic from `lib/config.php`:
- `validateAirportId()` - Airport ID format
- `loadConfig()` - JSON parsing and basic validation
- Schema validation for VPN configuration

### PSK Generation

- Generate cryptographically secure random PSK
- Length: 32-64 characters (alphanumeric + special chars)
- Format: Base64 or hex-encoded random bytes
- Example: `openssl rand -base64 32`

### Output Format

1. **JSON Snippet**: Clean, formatted JSON ready to paste
2. **Remote Instructions**: Markdown or plain text with pre-filled values
3. **Deployment Checklist**: Simple checklist format

### Error Handling

- Invalid airport ID → Prompt again
- Invalid subnet format → Show example and prompt again
- JSON validation fails → Show error and exit
- Schema validation fails → Show specific errors
- Conflicts detected → Show conflicts and exit

## Future Enhancements

- **Remove VPN**: Wizard to remove VPN configuration
- **Update VPN**: Wizard to update existing VPN configuration
- **List VPNs**: Show all configured VPNs
- **Test Connection**: Validate VPN connection is working
- **Generate Documentation**: Auto-generate remote site docs

## Security Considerations

- PSK is shown in output (user needs it for remote site)
- Wizard should not log PSKs to files
- Output can be saved to file by user if needed
- No sensitive data in wizard logs

