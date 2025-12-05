#!/usr/bin/env python3
"""
Simple script to generate WireGuard key pairs
Uses the same method as WireGuardHandler
"""

import base64
import secrets
import subprocess
import sys

def generate_keypair():
    """Generate WireGuard key pair"""
    # Generate private key (32 random bytes)
    private_key_bytes = secrets.token_bytes(32)
    private_key = base64.b64encode(private_key_bytes).decode('ascii').strip()
    
    # Generate public key from private key using wg pubkey
    try:
        result = subprocess.run(
            ['wg', 'pubkey'],
            input=private_key + '\n',
            text=True,
            capture_output=True,
            check=True,
            timeout=5
        )
        public_key = result.stdout.strip()
    except (subprocess.CalledProcessError, FileNotFoundError, subprocess.TimeoutExpired):
        print("Error: WireGuard tools (wg) not available.", file=sys.stderr)
        print("Install wireguard-tools or use Docker to generate keys.", file=sys.stderr)
        sys.exit(1)
    
    return private_key, public_key

if __name__ == '__main__':
    print("Generating WireGuard key pairs...")
    print("")
    
    server_priv, server_pub = generate_keypair()
    client_priv, client_pub = generate_keypair()
    
    print("Server Keys:")
    print(f"  server_private_key: {server_priv}")
    print(f"  server_public_key: {server_pub}")
    print("")
    print("Client Keys:")
    print(f"  client_private_key: {client_priv}")
    print(f"  client_public_key: {client_pub}")
    print("")
    print("Add these to airports.json under vpn.wireguard:")
    print("")
    print('"wireguard": {')
    print(f'  "server_private_key": "{server_priv}",')
    print(f'  "server_public_key": "{server_pub}",')
    print(f'  "client_private_key": "{client_priv}",')
    print(f'  "client_public_key": "{client_pub}"')
    print('}')





