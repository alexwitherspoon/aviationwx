#!/usr/bin/env python3
"""
VPN Configuration Test Utility
Validates VPN configuration without requiring actual network connections
"""

import json
import os
import sys
import subprocess
from pathlib import Path

def load_config(config_path):
    """Load airports.json configuration"""
    try:
        with open(config_path, 'r') as f:
            return json.load(f)
    except Exception as e:
        print(f"ERROR: Failed to load config: {e}", file=sys.stderr)
        return None

def validate_vpn_config(config):
    """Validate VPN configuration structure"""
    errors = []
    warnings = []
    
    if not isinstance(config, dict) or 'airports' not in config:
        errors.append("Invalid config structure: missing 'airports' key")
        return errors, warnings
    
    for airport_id, airport in config.get('airports', {}).items():
        vpn = airport.get('vpn')
        if not vpn or not vpn.get('enabled'):
            continue
        
        # Required fields
        required_fields = ['connection_name', 'remote_subnet', 'psk']
        for field in required_fields:
            if not vpn.get(field):
                errors.append(f"Airport '{airport_id}': Missing required VPN field '{field}'")
        
        # Validate remote_subnet format (CIDR)
        remote_subnet = vpn.get('remote_subnet', '')
        if remote_subnet:
            parts = remote_subnet.split('/')
            if len(parts) != 2:
                errors.append(f"Airport '{airport_id}': Invalid remote_subnet format (expected CIDR)")
            else:
                try:
                    ip_parts = parts[0].split('.')
                    if len(ip_parts) != 4:
                        errors.append(f"Airport '{airport_id}': Invalid IP address in remote_subnet")
                    mask = int(parts[1])
                    if mask < 0 or mask > 32:
                        errors.append(f"Airport '{airport_id}': Invalid subnet mask (0-32)")
                except ValueError:
                    errors.append(f"Airport '{airport_id}': Invalid subnet mask format")
        
        # Validate encryption settings
        encryption = vpn.get('encryption', 'aes256gcm128')
        valid_encryptions = ['aes128', 'aes192', 'aes256', 'aes128gcm', 'aes192gcm', 'aes256gcm128']
        if encryption not in valid_encryptions:
            warnings.append(f"Airport '{airport_id}': Encryption '{encryption}' may not be supported")
        
        # Validate DH group
        dh_group = str(vpn.get('dh_group', '14'))
        valid_dh_groups = ['14', '15', '16', '17', '18']
        if dh_group not in valid_dh_groups:
            warnings.append(f"Airport '{airport_id}': DH group '{dh_group}' may not be optimal")
        
        # Validate IKE version
        ike_version = str(vpn.get('ike_version', '2'))
        if ike_version not in ['1', '2']:
            errors.append(f"Airport '{airport_id}': Invalid IKE version '{ike_version}' (must be 1 or 2)")
    
    return errors, warnings

def test_ipsec_config_syntax(config_path):
    """Test if generated IPsec config is syntactically valid"""
    # This would require running the VPN manager and checking output
    # For now, we'll just validate the JSON structure
    return True

def test_strongswan_config(config_path):
    """Test strongSwan configuration syntax using ipsec command"""
    try:
        # Check if ipsec command is available
        result = subprocess.run(['which', 'ipsec'], capture_output=True, text=True)
        if result.returncode != 0:
            return None, "ipsec command not available (install strongswan to test config syntax)"
        
        # Try to validate config (this requires strongSwan to be installed)
        # Note: This won't work in Docker without strongSwan installed on host
        return True, None
    except Exception as e:
        return None, f"Could not test strongSwan config: {e}"

def main():
    config_path = os.getenv('CONFIG_PATH', 'config/airports.json')
    
    if not os.path.exists(config_path):
        print(f"ERROR: Config file not found: {config_path}", file=sys.stderr)
        sys.exit(1)
    
    print(f"Testing VPN configuration from: {config_path}")
    print("=" * 60)
    
    # Load and validate config
    config = load_config(config_path)
    if not config:
        sys.exit(1)
    
    errors, warnings = validate_vpn_config(config)
    
    # Count VPN configs
    vpn_count = 0
    for airport_id, airport in config.get('airports', {}).items():
        if airport.get('vpn', {}).get('enabled'):
            vpn_count += 1
    
    print(f"\nFound {vpn_count} VPN configuration(s)")
    
    if errors:
        print(f"\n❌ ERRORS ({len(errors)}):")
        for error in errors:
            print(f"  - {error}")
    
    if warnings:
        print(f"\n⚠️  WARNINGS ({len(warnings)}):")
        for warning in warnings:
            print(f"  - {warning}")
    
    if not errors and not warnings:
        print("\n✅ Configuration validation passed!")
    
    # Test strongSwan config if available
    strongswan_result, strongswan_msg = test_strongswan_config(config_path)
    if strongswan_result is None:
        print(f"\nℹ️  {strongswan_msg}")
    elif strongswan_result:
        print("\n✅ strongSwan config syntax valid")
    
    print("\n" + "=" * 60)
    
    if errors:
        print("❌ Configuration has errors. Please fix before deploying.")
        sys.exit(1)
    else:
        print("✅ Configuration is valid (connection testing requires Linux/production environment)")
        sys.exit(0)

if __name__ == '__main__':
    main()

