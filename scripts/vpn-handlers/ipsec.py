"""
IPsec VPN Protocol Handler
Uses strongSwan for IPsec/IKEv2 connections
"""

import os
import subprocess
from typing import Dict, List
from .base import VPNProtocolHandler


class IPSecHandler(VPNProtocolHandler):
    """Handler for IPsec/IKEv2 VPN connections using strongSwan"""
    
    def __init__(self, server_ip: str, vpn_subnet: str):
        super().__init__('ipsec', server_ip, vpn_subnet)
        self.ipsec_conf_dir = '/etc/ipsec-shared'
        self.ipsec_conf = os.path.join(self.ipsec_conf_dir, 'ipsec.conf')
        self.ipsec_secrets = os.path.join(self.ipsec_conf_dir, 'ipsec.secrets')
    
    def generate_server_config(self, vpn_configs: Dict[str, Dict]) -> str:
        """Generate strongSwan ipsec.conf from VPN configurations"""
        config_lines = [
            "# strongSwan IPsec configuration",
            "# Auto-generated from airports.json",
            "",
            "config setup",
            "    charondebug=\"ike 2, knl 2, cfg 2\"",
            "    uniqueids=never",
            "    strictcrlpolicy=no",
            "",
        ]
        
        if not vpn_configs:
            return '\n'.join(config_lines)
        
        # Convert DH group number to bit length mapping
        dh_map = {'14': '2048', '15': '3072', '16': '4096', '17': '6144', '18': '8192'}
        
        for conn_name, config in vpn_configs.items():
            airport_id = config.get('airport_id', conn_name)
            remote_subnet = config.get('remote_subnet', '')
            ike_version = config.get('ike_version', '2')
            encryption = config.get('encryption', 'aes256gcm128')
            dh_group = config.get('dh_group', '14')
            dh_bits = dh_map.get(str(dh_group), '2048')
            
            config_lines.extend([
                f"# Connection for {airport_id} airport",
                f"conn {conn_name}",
                "    type=tunnel",
                "    auto=add",
                f"    keyexchange=ikev{ike_version}",
                f"    ike={encryption}-sha256-modp{dh_bits}!",
                f"    esp={encryption}-sha256-modp{dh_bits}!",
                "    left=%defaultroute",
                "    leftid=@vpn.aviationwx.org",
                "    leftsubnet=0.0.0.0/0",
                "    leftauth=psk",
                "    right=%any",
                f"    rightid=@{airport_id}.remote",
                f"    rightsubnet={remote_subnet}",
                "    rightauth=psk",
                "    dpdaction=restart",
                "    dpddelay=30s",
                "    dpdtimeout=120s",
                "    rekey=yes",
                "    reauth=yes",
                "    fragmentation=yes",
                "    forceencaps=yes",
                "",
            ])
        
        return '\n'.join(config_lines)
    
    def generate_secrets_config(self, vpn_configs: Dict[str, Dict]) -> str:
        """Generate strongSwan ipsec.secrets from VPN configurations"""
        secret_lines = [
            "# IPsec secrets",
            "# Auto-generated from airports.json",
            "",
        ]
        
        if not vpn_configs:
            return '\n'.join(secret_lines)
        
        for conn_name, config in vpn_configs.items():
            psk = config.get('psk')
            if psk:
                secret_lines.append(f": PSK \"{psk}\"")
            else:
                self.logger.warning(f"PSK not configured for {conn_name}")
        
        return '\n'.join(secret_lines)
    
    def generate_client_config(self, connection_name: str, config: Dict) -> str:
        """
        Generate IPsec client configuration
        Note: IPsec typically uses PSK-based config, not file-based client configs
        Returns instructions for manual configuration
        """
        airport_id = config.get('airport_id', connection_name)
        remote_subnet = config.get('remote_subnet', '')
        psk = config.get('psk', '')
        ike_version = config.get('ike_version', '2')
        encryption = config.get('encryption', 'aes256gcm128')
        dh_group = config.get('dh_group', '14')
        
        client_config = f"""# IPsec/IKEv2 Client Configuration for {airport_id}
# Manual configuration required - import these settings into your VPN client

# Connection Settings:
# - Peer IP: {self.server_ip}
# - Pre-Shared Key: {psk}
# - Remote Subnet: {remote_subnet}
# - IKE Version: {ike_version}
# - Encryption: {encryption}
# - DH Group: {dh_group}

# For UniFi Gateway:
# 1. Navigate to Settings > VPN > Site-to-Site VPN
# 2. Add new VPN connection:
#    - Type: Manual IPsec
#    - Peer IP: {self.server_ip}
#    - Pre-Shared Key: {psk}
#    - Remote Subnets: {remote_subnet}
#    - IKE Version: {ike_version}
#    - Encryption: {encryption}
#    - Hash: SHA-256
#    - DH Group: {dh_group}
"""
        return client_config
    
    def check_connection_status(self, connection_name: str) -> Dict[str, any]:
        """Check status of an IPsec connection"""
        try:
            result = subprocess.run(
                ['ipsec', 'status', connection_name],
                capture_output=True,
                text=True,
                timeout=5
            )
            
            output = result.stdout.lower()
            if 'established' in output:
                return {'status': 'up', 'details': output}
            elif 'connecting' in output or 'initiating' in output:
                return {'status': 'connecting', 'details': output}
            else:
                return {'status': 'down', 'details': output}
        except Exception as e:
            self.logger.error(f"Failed to check status for {connection_name}: {e}")
            return {'status': 'down', 'details': str(e)}
    
    def health_check_connection(self, connection_name: str, remote_subnet: str) -> bool:
        """Perform health check on IPsec connection by pinging remote gateway"""
        try:
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





