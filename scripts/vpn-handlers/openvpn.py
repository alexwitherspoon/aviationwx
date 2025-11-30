"""
OpenVPN VPN Protocol Handler
Uses PSK mode for simplicity
"""

import os
import subprocess
import secrets
from typing import Dict
from .base import VPNProtocolHandler
from .utils import get_server_ip, get_vpn_subnet


class OpenVPNHandler(VPNProtocolHandler):
    """Handler for OpenVPN VPN connections using PSK mode"""
    
    def __init__(self, server_ip: str = None, vpn_subnet: str = None):
        # Resolve server IP if not provided
        if server_ip is None:
            server_ip = get_server_ip()
        if vpn_subnet is None:
            vpn_subnet = get_vpn_subnet()
        
        super().__init__('openvpn', server_ip, vpn_subnet)
        self.openvpn_config_dir = '/etc/openvpn-shared'
        self.openvpn_config_file = os.path.join(self.openvpn_config_dir, 'server.conf')
        self.openvpn_psk_file = os.path.join(self.openvpn_config_dir, 'psk.key')
        self.server_port = 1194  # Default OpenVPN port
        self.protocol = 'udp'  # UDP is more efficient for VPN
    
    @staticmethod
    def generate_psk() -> str:
        """
        Generate OpenVPN Pre-Shared Key (PSK)
        
        Returns:
            PSK as hex string (256 bits = 64 hex characters)
        """
        # OpenVPN PSK is 256 bits (32 bytes) = 64 hex characters
        psk_bytes = secrets.token_bytes(32)
        return psk_bytes.hex()
    
    def generate_server_config(self, vpn_configs: Dict[str, Dict]) -> str:
        """
        Generate OpenVPN server configuration
        
        Args:
            vpn_configs: Dictionary of connection_name -> config dict
            
        Returns:
            OpenVPN server configuration file content
        """
        if not vpn_configs:
            # Return minimal config if no connections
            return self._get_base_server_config()
        
        # Get PSK from first config (should be same for all in PSK mode)
        first_config = next(iter(vpn_configs.values()))
        openvpn_config = first_config.get('openvpn', {})
        psk = openvpn_config.get('psk')
        
        if not psk:
            self.logger.warning("PSK not found in config")
            psk = self.generate_psk()
        
        config_lines = [
            "# OpenVPN server configuration",
            "# Auto-generated from airports.json",
            "",
            f"port {self.server_port}",
            f"proto {self.protocol}",
            "dev tun",
            "",
            "# Server IP in VPN subnet",
            f"server {self._get_server_network()} {self._get_server_netmask()}",
            "",
            "# PSK for authentication",
            f"secret {self.openvpn_psk_file}",
            "",
            "# Keepalive",
            "keepalive 10 120",
            "",
            "# Compression",
            "comp-lzo",
            "",
            "# Logging",
            "verb 3",
            "",
            "# Security",
            "cipher AES-256-CBC",
            "auth SHA256",
            "",
            "# Persistence",
            "persist-key",
            "persist-tun",
            "",
        ]
        
        # Add client-specific routes (one per airport)
        for conn_name, config in vpn_configs.items():
            airport_id = config.get('airport_id', conn_name)
            client_ip = config.get('client_ip', '')
            remote_subnet = config.get('remote_subnet', '')
            
            if client_ip and remote_subnet:
                # Route remote subnet to this client
                client_ip_only = client_ip.split('/')[0]
                config_lines.append(f"# Route for {airport_id} ({conn_name})")
                config_lines.append(f"route {remote_subnet} {client_ip_only}")
                config_lines.append("")
        
        return '\n'.join(config_lines)
    
    def generate_psk_file(self, vpn_configs: Dict[str, Dict]) -> str:
        """
        Generate OpenVPN PSK file content
        
        Args:
            vpn_configs: Dictionary of connection_name -> config dict
            
        Returns:
            PSK file content (hex string)
        """
        if not vpn_configs:
            return ""
        
        # Get PSK from first config (should be same for all)
        first_config = next(iter(vpn_configs.values()))
        openvpn_config = first_config.get('openvpn', {})
        psk = openvpn_config.get('psk')
        
        if not psk:
            self.logger.warning("PSK not found, generating new one")
            psk = self.generate_psk()
        
        return psk
    
    def generate_client_config(self, connection_name: str, config: Dict) -> str:
        """
        Generate OpenVPN client configuration for UniFi Cloud Gateway
        
        Args:
            connection_name: Name of the VPN connection
            config: VPN configuration dictionary
            
        Returns:
            OpenVPN client configuration file content (.ovpn format)
        """
        airport_id = config.get('airport_id', connection_name)
        openvpn_config = config.get('openvpn', {})
        psk = openvpn_config.get('psk')
        client_ip = config.get('client_ip', '')
        remote_subnet = config.get('remote_subnet', '')
        
        if not psk:
            raise ValueError(f"Missing PSK for {connection_name}")
        
        client_config = [
            f"# OpenVPN client configuration for {airport_id}",
            f"# Connection: {connection_name}",
            "",
            "client",
            "dev tun",
            f"proto {self.protocol}",
            f"remote {self.server_ip} {self.server_port}",
            "resolv-retry infinite",
            "nobind",
            "persist-key",
            "persist-tun",
            "",
            "# PSK (shared secret)",
            "<secret>",
            psk,
            "</secret>",
            "",
            "# Cipher settings",
            "cipher AES-256-CBC",
            "auth SHA256",
            "",
            "# Compression",
            "comp-lzo",
            "",
            "verb 3",
        ]
        
        # Add route for remote subnet if specified
        if remote_subnet:
            client_config.append(f"route {remote_subnet} 255.255.255.255")
        
        return '\n'.join(client_config)
    
    def check_connection_status(self, connection_name: str) -> Dict[str, any]:
        """
        Check status of an OpenVPN connection
        
        Args:
            connection_name: Name of the connection (not used directly in OpenVPN)
            
        Returns:
            Dictionary with 'status' and 'details'
        """
        try:
            # Check if OpenVPN process is running
            result = subprocess.run(
                ['pgrep', '-f', 'openvpn.*server.conf'],
                capture_output=True,
                timeout=5
            )
            
            if result.returncode != 0:
                return {'status': 'down', 'details': 'OpenVPN server not running'}
            
            # Check for active clients (via management interface or status file)
            # For now, if server is running, consider it up
            # TODO: Implement more detailed status checking via management interface
            return {'status': 'up', 'details': 'OpenVPN server running'}
                
        except FileNotFoundError:
            return {'status': 'down', 'details': 'OpenVPN tools not available'}
        except Exception as e:
            self.logger.error(f"Failed to check OpenVPN status: {e}")
            return {'status': 'down', 'details': str(e)}
    
    def health_check_connection(self, connection_name: str, remote_subnet: str) -> bool:
        """
        Perform health check on OpenVPN connection
        
        Args:
            connection_name: Name of the connection
            remote_subnet: Remote subnet to check
            
        Returns:
            True if connection is healthy
        """
        # Check if OpenVPN server is running
        try:
            result = subprocess.run(
                ['pgrep', '-f', 'openvpn.*server.conf'],
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
    
    def _get_server_network(self) -> str:
        """Get server network address (e.g., 10.0.0.0 for 10.0.0.0/16)"""
        subnet_parts = self.vpn_subnet.split('/')
        subnet_base = subnet_parts[0]
        return subnet_base
    
    def _get_server_netmask(self) -> str:
        """Get server netmask from subnet"""
        subnet_parts = self.vpn_subnet.split('/')
        mask = int(subnet_parts[1]) if len(subnet_parts) > 1 else 16
        
        # Convert CIDR to netmask
        netmask = (0xffffffff >> (32 - mask)) << (32 - mask)
        return f"{netmask >> 24 & 0xff}.{netmask >> 16 & 0xff}.{netmask >> 8 & 0xff}.{netmask & 0xff}"
    
    def _get_base_server_config(self) -> str:
        """Get base OpenVPN server configuration"""
        return f"""# OpenVPN server configuration (no clients configured)
port {self.server_port}
proto {self.protocol}
dev tun
server {self._get_server_network()} {self._get_server_netmask()}
keepalive 10 120
comp-lzo
verb 3
cipher AES-256-CBC
auth SHA256
persist-key
persist-tun
"""





