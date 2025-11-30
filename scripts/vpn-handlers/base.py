"""
Abstract base class for VPN protocol handlers
"""

from abc import ABC, abstractmethod
from typing import Dict, List, Optional, Tuple
import logging

logger = logging.getLogger(__name__)


class VPNProtocolHandler(ABC):
    """
    Abstract base class for VPN protocol handlers.
    Each protocol (IPsec, WireGuard, OpenVPN) implements this interface.
    """
    
    def __init__(self, protocol_name: str, server_ip: str, vpn_subnet: str):
        """
        Initialize protocol handler
        
        Args:
            protocol_name: Name of the protocol (e.g., 'wireguard', 'openvpn', 'ipsec')
            server_ip: Public IP address of the VPN server
            vpn_subnet: VPN subnet in CIDR notation (e.g., '10.0.0.0/16')
        """
        self.protocol_name = protocol_name
        self.server_ip = server_ip
        self.vpn_subnet = vpn_subnet
        self.logger = logging.getLogger(f"{__name__}.{protocol_name}")
    
    @abstractmethod
    def generate_server_config(self, vpn_configs: Dict[str, Dict]) -> str:
        """
        Generate server configuration file content
        
        Args:
            vpn_configs: Dictionary of connection_name -> config dict
            
        Returns:
            Configuration file content as string
        """
        pass
    
    @abstractmethod
    def generate_client_config(self, connection_name: str, config: Dict) -> str:
        """
        Generate client configuration file content
        
        Args:
            connection_name: Name of the VPN connection
            config: VPN configuration dictionary for this connection
            
        Returns:
            Client configuration file content as string
        """
        pass
    
    @abstractmethod
    def check_connection_status(self, connection_name: str) -> Dict[str, any]:
        """
        Check status of a VPN connection
        
        Args:
            connection_name: Name of the VPN connection
            
        Returns:
            Dictionary with 'status' ('up', 'down', 'connecting') and 'details'
        """
        pass
    
    @abstractmethod
    def health_check_connection(self, connection_name: str, remote_subnet: str) -> bool:
        """
        Perform health check on VPN connection
        
        Args:
            connection_name: Name of the VPN connection
            remote_subnet: Remote subnet in CIDR notation
            
        Returns:
            True if connection is healthy, False otherwise
        """
        pass
    
    def assign_client_ip(self, existing_configs: List[Dict]) -> str:
        """
        Assign next available client IP sequentially
        
        Args:
            existing_configs: List of existing VPN configurations
            
        Returns:
            Client IP in CIDR notation (e.g., '10.0.0.2/32')
        """
        # Extract subnet base and mask
        subnet_parts = self.vpn_subnet.split('/')
        subnet_base = subnet_parts[0]
        mask = int(subnet_parts[1]) if len(subnet_parts) > 1 else 16
        
        # Parse base IP
        ip_parts = subnet_base.split('.')
        base_octet = int(ip_parts[3])
        
        # Collect used IPs
        used_ips = set()
        for config in existing_configs:
            if 'client_ip' in config:
                ip = config['client_ip'].split('/')[0]
                used_ips.add(ip)
        
        # Find next available IP (start from base + 2, skip .0 and .1)
        # For 10.0.0.0/16, start from 10.0.0.2
        start_ip = base_octet + 2 if base_octet == 0 else base_octet + 1
        
        # For /16 subnet, we can use up to 10.0.255.254
        max_ip = 254 if mask >= 24 else 65534
        
        for i in range(start_ip, max_ip + 1):
            if mask >= 24:
                # /24 or larger: use last octet
                ip = f"{ip_parts[0]}.{ip_parts[1]}.{ip_parts[2]}.{i}"
            else:
                # /16: use last two octets
                third = (i - 1) // 256
                fourth = (i - 1) % 256
                ip = f"{ip_parts[0]}.{ip_parts[1]}.{third}.{fourth}"
            
            if ip not in used_ips:
                return f"{ip}/32"
        
        raise Exception(f"No available IPs in VPN subnet {self.vpn_subnet}")
    
    def validate_config(self, config: Dict) -> Tuple[bool, List[str]]:
        """
        Validate VPN configuration
        
        Args:
            config: VPN configuration dictionary
            
        Returns:
            Tuple of (is_valid, list_of_errors)
        """
        errors = []
        
        # Common validations
        if not config.get('connection_name'):
            errors.append("Missing connection_name")
        
        if not config.get('remote_subnet'):
            errors.append("Missing remote_subnet")
        elif not self._validate_cidr(config.get('remote_subnet')):
            errors.append(f"Invalid remote_subnet format: {config.get('remote_subnet')}")
        
        return (len(errors) == 0, errors)
    
    @staticmethod
    def _validate_cidr(cidr: str) -> bool:
        """Validate CIDR notation"""
        try:
            parts = cidr.split('/')
            if len(parts) != 2:
                return False
            ip_parts = parts[0].split('.')
            if len(ip_parts) != 4:
                return False
            for part in ip_parts:
                if not (0 <= int(part) <= 255):
                    return False
            mask = int(parts[1])
            if not (0 <= mask <= 32):
                return False
            return True
        except (ValueError, AttributeError):
            return False





