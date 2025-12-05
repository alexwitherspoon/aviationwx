"""
WireGuard VPN Protocol Handler
"""

import os
import subprocess
import secrets
import base64
from typing import Dict, Tuple
from .base import VPNProtocolHandler
from .utils import get_server_ip, get_vpn_subnet


class WireGuardHandler(VPNProtocolHandler):
    """Handler for WireGuard VPN connections"""
    
    def __init__(self, server_ip: str = None, vpn_subnet: str = None):
        # Resolve server IP if not provided
        if server_ip is None:
            server_ip = get_server_ip()
        if vpn_subnet is None:
            vpn_subnet = get_vpn_subnet()
        
        super().__init__('wireguard', server_ip, vpn_subnet)
        self.wg_config_dir = '/etc/wireguard-shared'
        self.wg_config_file = os.path.join(self.wg_config_dir, 'wg0.conf')
        self.server_port = 51820  # Default WireGuard port
    
    @staticmethod
    def generate_keypair() -> Tuple[str, str]:
        """
        Generate WireGuard key pair
        
        Returns:
            Tuple of (private_key, public_key) as base64 strings
        """
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
            # Fallback: if wg command not available, we'll need to handle this differently
            # For now, raise an error - this should only happen in development
            raise RuntimeError(
                "WireGuard tools (wg) not available. Install wireguard-tools package."
            )
        
        return private_key, public_key
    
    def generate_server_config(self, vpn_configs: Dict[str, Dict]) -> str:
        """
        Generate WireGuard server configuration (wg0.conf)
        
        Args:
            vpn_configs: Dictionary of connection_name -> config dict
            
        Returns:
            WireGuard server configuration file content
        """
        config_lines = [
            "# WireGuard server configuration",
            "# Auto-generated from airports.json",
            "",
            "[Interface]",
            f"# Server IP in VPN subnet",
            f"Address = {self._get_server_ip_in_subnet()}/32",
            f"ListenPort = {self.server_port}",
            "",
        ]
        
        # Add server private key if we have configs
        if vpn_configs:
            # Get server private key from first config (should be same for all)
            first_config = next(iter(vpn_configs.values()))
            wireguard_config = first_config.get('wireguard', {})
            server_private_key = wireguard_config.get('server_private_key')
            
            if server_private_key:
                config_lines.append(f"PrivateKey = {server_private_key}")
                config_lines.append("")
            else:
                self.logger.warning("Server private key not found in config")
        
        # Add peer configurations (one per airport)
        for conn_name, config in vpn_configs.items():
            airport_id = config.get('airport_id', conn_name)
            wireguard_config = config.get('wireguard', {})
            client_public_key = wireguard_config.get('client_public_key')
            client_ip = config.get('client_ip', '')
            remote_subnet = config.get('remote_subnet', '')
            
            if not client_public_key:
                self.logger.warning(f"Client public key not found for {conn_name}")
                continue
            
            config_lines.extend([
                f"# Peer: {airport_id} ({conn_name})",
                "[Peer]",
                f"PublicKey = {client_public_key}",
            ])
            
            if client_ip:
                config_lines.append(f"AllowedIPs = {client_ip}")
            
            # Add remote subnet to allowed IPs so server can route to it
            if remote_subnet:
                config_lines.append(f"# Remote subnet: {remote_subnet}")
            
            config_lines.append("")
        
        return '\n'.join(config_lines)
    
    def generate_client_config(self, connection_name: str, config: Dict) -> str:
        """
        Generate WireGuard client configuration for UniFi Cloud Gateway
        
        Args:
            connection_name: Name of the VPN connection
            config: VPN configuration dictionary
            
        Returns:
            WireGuard client configuration file content
        """
        airport_id = config.get('airport_id', connection_name)
        wireguard_config = config.get('wireguard', {})
        client_private_key = wireguard_config.get('client_private_key')
        server_public_key = wireguard_config.get('server_public_key')
        client_ip = config.get('client_ip', '')
        remote_subnet = config.get('remote_subnet', '')
        
        if not client_private_key or not server_public_key:
            raise ValueError(f"Missing keys for {connection_name}")
        
        client_config = [
            f"# WireGuard client configuration for {airport_id}",
            f"# Connection: {connection_name}",
            "",
            "[Interface]",
            f"PrivateKey = {client_private_key}",
        ]
        
        if client_ip:
            client_config.append(f"Address = {client_ip}")
        
        client_config.extend([
            "",
            "[Peer]",
            f"PublicKey = {server_public_key}",
            f"Endpoint = {self.server_ip}:{self.server_port}",
        ])
        
        # Allowed IPs: remote subnet (so client can access cameras)
        # and optionally VPN subnet for server communication
        allowed_ips = [remote_subnet] if remote_subnet else []
        if allowed_ips:
            client_config.append(f"AllowedIPs = {', '.join(allowed_ips)}")
        
        # Persistent keepalive for NAT traversal
        client_config.append("PersistentKeepalive = 25")
        
        return '\n'.join(client_config)
    
    def check_connection_status(self, connection_name: str) -> Dict[str, any]:
        """
        Check status of a WireGuard connection
        
        Args:
            connection_name: Name of the VPN connection (not used directly in WireGuard)
            
        Returns:
            Dictionary with 'status' and 'details'
        """
        try:
            # WireGuard doesn't use connection names like IPsec
            # We check the interface status and look for the peer
            result = subprocess.run(
                ['wg', 'show', 'wg0'],
                capture_output=True,
                text=True,
                timeout=5
            )
            
            if result.returncode != 0:
                return {'status': 'down', 'details': 'WireGuard interface wg0 not found'}
            
            output = result.stdout
            # Check if we have any peers with recent handshakes
            if 'latest handshake' in output.lower():
                # Parse to see if handshake is recent (within last 2 minutes)
                # For now, if we see handshake, consider it up
                return {'status': 'up', 'details': output}
            else:
                return {'status': 'down', 'details': 'No active peers'}
                
        except FileNotFoundError:
            return {'status': 'down', 'details': 'WireGuard tools not available'}
        except Exception as e:
            self.logger.error(f"Failed to check WireGuard status: {e}")
            return {'status': 'down', 'details': str(e)}
    
    def health_check_connection(self, connection_name: str, remote_subnet: str) -> bool:
        """
        Perform health check on WireGuard connection
        
        Args:
            connection_name: Name of the connection
            remote_subnet: Remote subnet to check
            
        Returns:
            True if connection is healthy
        """
        # Check if WireGuard interface exists and is up
        try:
            result = subprocess.run(
                ['ip', 'link', 'show', 'wg0'],
                capture_output=True,
                timeout=5
            )
            if result.returncode != 0:
                return False
            
            # Try to ping gateway in remote subnet
            subnet_parts = remote_subnet.split('/')
            ip_parts = subnet_parts[0].split('.')
            gateway_ip = '.'.join(ip_parts[:-1]) + '.1'
            
            result = subprocess.run(
                ['ping', '-c', '1', '-W', '2', gateway_ip],
                capture_output=True,
                timeout=5
            )
            return result.returncode == 0
        except Exception:
            return False
    
    def _get_server_ip_in_subnet(self) -> str:
        """Get server IP address within the VPN subnet"""
        # Server always gets .1 in the subnet
        subnet_parts = self.vpn_subnet.split('/')
        subnet_base = subnet_parts[0]
        ip_parts = subnet_base.split('.')
        # For 10.0.0.0/16, server is 10.0.0.1
        return f"{ip_parts[0]}.{ip_parts[1]}.{ip_parts[2]}.1"

