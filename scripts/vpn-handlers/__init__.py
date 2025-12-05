"""
VPN Protocol Handlers
Abstract base class and protocol-specific implementations
"""

from .base import VPNProtocolHandler
from .ipsec import IPSecHandler
from .wireguard import WireGuardHandler
from .openvpn import OpenVPNHandler

__all__ = ['VPNProtocolHandler', 'IPSecHandler', 'WireGuardHandler', 'OpenVPNHandler']

