"""
Utility functions for VPN handlers
"""

import os
import socket
import logging

logger = logging.getLogger(__name__)


def get_server_ip(domain: str = None) -> str:
    """
    Get VPN server IP address
    
    Priority:
    1. VPN_SERVER_IP environment variable (explicit)
    2. DNS lookup of DOMAIN environment variable
    3. Raise exception if neither available
    
    Args:
        domain: Optional domain name to resolve (defaults to DOMAIN env var)
        
    Returns:
        IP address as string
        
    Raises:
        ValueError: If IP cannot be determined
    """
    # Check explicit VPN_SERVER_IP first
    vpn_server_ip = os.getenv('VPN_SERVER_IP')
    if vpn_server_ip:
        logger.info(f"Using VPN_SERVER_IP from environment: {vpn_server_ip}")
        return vpn_server_ip
    
    # Try DNS lookup of DOMAIN
    domain_to_resolve = domain or os.getenv('DOMAIN')
    if domain_to_resolve:
        try:
            ip = socket.gethostbyname(domain_to_resolve)
            logger.info(f"Resolved {domain_to_resolve} to {ip}")
            return ip
        except socket.gaierror as e:
            logger.warning(f"Failed to resolve {domain_to_resolve}: {e}")
    
    raise ValueError(
        "VPN server IP not configured. Set VPN_SERVER_IP environment variable "
        "or ensure DOMAIN is set and resolvable."
    )


def get_vpn_subnet() -> str:
    """
    Get VPN subnet from environment variable
    
    Returns:
        VPN subnet in CIDR notation (default: 10.0.0.0/16)
    """
    return os.getenv('VPN_SUBNET', '10.0.0.0/16')





