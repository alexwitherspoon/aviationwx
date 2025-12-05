#!/usr/bin/env python3
"""
VPN Manager Service
Manages multi-protocol VPN connections (IPsec, WireGuard, OpenVPN) based on airports.json configuration
"""

import json
import os
import time
import logging
import signal
import sys
from pathlib import Path
from typing import Dict, Optional, List
from collections import defaultdict

# Import protocol handlers
# Add scripts directory to path for imports
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from vpn_handlers.base import VPNProtocolHandler
from vpn_handlers.ipsec import IPSecHandler
from vpn_handlers.wireguard import WireGuardHandler
from vpn_handlers.openvpn import OpenVPNHandler
from vpn_handlers.utils import get_server_ip, get_vpn_subnet

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s [%(levelname)s] %(message)s',
    datefmt='%Y-%m-%d %H:%M:%S'
)
logger = logging.getLogger(__name__)

# Configuration paths
CONFIG_PATH = os.getenv('CONFIG_PATH', '/var/www/html/config/airports.json')
STATUS_FILE = os.getenv('STATUS_FILE', '/var/www/html/cache/vpn-status.json')
CLIENT_CONFIG_DIR = os.getenv('CLIENT_CONFIG_DIR', '/var/www/html/config/vpn-clients')
UPDATE_INTERVAL = 30  # seconds

# Protocol-specific shared directories
IPSEC_SHARED_DIR = '/etc/ipsec-shared'
WIREGUARD_SHARED_DIR = '/etc/wireguard-shared'
OPENVPN_SHARED_DIR = '/etc/openvpn-shared'
OPENVPN_PSK_FILE = os.path.join(OPENVPN_SHARED_DIR, 'psk.key')

# Ensure directories exist
os.makedirs(IPSEC_SHARED_DIR, exist_ok=True)
os.makedirs(WIREGUARD_SHARED_DIR, exist_ok=True)
os.makedirs(OPENVPN_SHARED_DIR, exist_ok=True)
os.makedirs(CLIENT_CONFIG_DIR, exist_ok=True)
os.makedirs(os.path.dirname(STATUS_FILE), exist_ok=True)


class VPNManager:
    def __init__(self):
        self.running = True
        self.connections: Dict[str, Dict] = {}
        self.connection_states: Dict[str, str] = {}
        self.last_health_check: Dict[str, float] = {}
        self.config_mtime = 0
        
        # Initialize protocol handlers
        try:
            server_ip = get_server_ip()
            vpn_subnet = get_vpn_subnet()
            
            self.handlers: Dict[str, VPNProtocolHandler] = {
                'ipsec': IPSecHandler(server_ip, vpn_subnet),
                'wireguard': WireGuardHandler(server_ip, vpn_subnet),
                'openvpn': OpenVPNHandler(server_ip, vpn_subnet),
            }
            
            logger.info(f"VPN Manager initialized with {len(self.handlers)} protocol handlers")
            logger.info(f"Server IP: {server_ip}, VPN Subnet: {vpn_subnet}")
        except Exception as e:
            logger.error(f"Failed to initialize VPN Manager: {e}")
            raise
        
        # Setup signal handlers for graceful shutdown
        signal.signal(signal.SIGTERM, self.signal_handler)
        signal.signal(signal.SIGINT, self.signal_handler)
    
    def signal_handler(self, signum, frame):
        """Handle shutdown signals gracefully"""
        logger.info(f"Received signal {signum}, shutting down...")
        self.running = False
    
    def load_config(self) -> Optional[Dict]:
        """Load airports.json configuration"""
        try:
            if not os.path.exists(CONFIG_PATH):
                logger.error(f"Config file not found: {CONFIG_PATH}")
                return None
            
            with open(CONFIG_PATH, 'r') as f:
                return json.load(f)
        except Exception as e:
            logger.error(f"Failed to load config: {e}")
            return None
    
    def parse_vpn_configs(self, config: Dict) -> Dict[str, Dict]:
        """
        Extract VPN configurations from airports.json
        Handles key generation for protocols that need it (WireGuard)
        """
        vpn_configs = {}
        all_existing_configs = []
        
        # First pass: collect all existing configs for IP assignment
        for airport_id, airport in config.get('airports', {}).items():
            vpn = airport.get('vpn')
            if vpn and vpn.get('enabled'):
                all_existing_configs.append(vpn)
        
        # Second pass: process each config
        for airport_id, airport in config.get('airports', {}).items():
            vpn = airport.get('vpn')
            if not vpn or not vpn.get('enabled'):
                continue
            
            protocol_type = vpn.get('type', 'ipsec').lower()
            connection_name = vpn.get('connection_name', f"{airport_id}_vpn")
            
            # Get handler for this protocol
            handler = self.handlers.get(protocol_type)
            if not handler:
                logger.warning(f"Unknown VPN protocol '{protocol_type}' for {airport_id}, skipping")
                continue
            
            # Build config dict
            vpn_config = {
                'airport_id': airport_id,
                'connection_name': connection_name,
                'protocol': protocol_type,
                'remote_subnet': vpn.get('remote_subnet'),
                'handler': handler,
            }
            
            # Protocol-specific config processing
            if protocol_type == 'ipsec':
                vpn_config.update({
                    'psk': vpn.get('psk'),
                    'ike_version': vpn.get('ike_version', '2'),
                    'encryption': vpn.get('encryption', 'aes256gcm128'),
                    'dh_group': vpn.get('dh_group', '14'),
                    'lifetime': vpn.get('lifetime', '3600'),
                })
            
            elif protocol_type == 'wireguard':
                wireguard_config = vpn.get('wireguard', {})
                
                # Generate keys if not present
                if not wireguard_config.get('server_private_key'):
                    logger.info(f"Generating WireGuard keys for {airport_id}")
                    server_priv, server_pub = WireGuardHandler.generate_keypair()
                    client_priv, client_pub = WireGuardHandler.generate_keypair()
                    
                    wireguard_config['server_private_key'] = server_priv
                    wireguard_config['server_public_key'] = server_pub
                    wireguard_config['client_private_key'] = client_priv
                    wireguard_config['client_public_key'] = client_pub
                    
                    # Update airports.json (if writable)
                    # Note: In production, this should be done via config update process
                    logger.info(f"Generated keys for {airport_id} - update airports.json with these keys")
                
                # Assign client IP if not present
                if not vpn.get('client_ip'):
                    try:
                        client_ip = handler.assign_client_ip(all_existing_configs)
                        vpn['client_ip'] = client_ip
                        logger.info(f"Assigned client IP {client_ip} to {airport_id}")
                    except Exception as e:
                        logger.error(f"Failed to assign client IP for {airport_id}: {e}")
                        continue
                
                vpn_config['wireguard'] = wireguard_config
                vpn_config['client_ip'] = vpn.get('client_ip')
                vpn_config['server_port'] = wireguard_config.get('server_port', 51820)
            
            elif protocol_type == 'openvpn':
                openvpn_config = vpn.get('openvpn', {})
                
                # Generate PSK if not present
                if not openvpn_config.get('psk'):
                    logger.info(f"Generating OpenVPN PSK for {airport_id}")
                    psk = OpenVPNHandler.generate_psk()
                    openvpn_config['psk'] = psk
                    logger.info(f"Generated PSK for {airport_id} - update airports.json with this PSK")
                
                # Assign client IP if not present
                if not vpn.get('client_ip'):
                    try:
                        client_ip = handler.assign_client_ip(all_existing_configs)
                        vpn['client_ip'] = client_ip
                        logger.info(f"Assigned client IP {client_ip} to {airport_id}")
                    except Exception as e:
                        logger.error(f"Failed to assign client IP for {airport_id}: {e}")
                        continue
                
                vpn_config['openvpn'] = openvpn_config
                vpn_config['client_ip'] = vpn.get('client_ip')
                vpn_config['server_port'] = openvpn_config.get('server_port', 1194)
                vpn_config['protocol'] = openvpn_config.get('protocol', 'udp')
            
            vpn_configs[connection_name] = vpn_config
        
        return vpn_configs
    
    def generate_configs(self, vpn_configs: Dict[str, Dict]):
        """
        Generate server configuration files for each protocol
        Groups configs by protocol and uses appropriate handler
        """
        # Group configs by protocol
        by_protocol = defaultdict(dict)
        for conn_name, config in vpn_configs.items():
            protocol = config.get('protocol', 'ipsec')
            by_protocol[protocol][conn_name] = config
        
        # Generate configs for each protocol
        for protocol, configs in by_protocol.items():
            handler = self.handlers.get(protocol)
            if not handler:
                logger.warning(f"No handler for protocol {protocol}")
                continue
            
            try:
                if protocol == 'ipsec':
                    # IPsec needs both conf and secrets files
                    server_config = handler.generate_server_config(configs)
                    secrets_config = handler.generate_secrets_config(configs)
                    self._write_ipsec_configs(server_config, secrets_config)
                
                elif protocol == 'wireguard':
                    # WireGuard needs single config file
                    server_config = handler.generate_server_config(configs)
                    self._write_wireguard_config(server_config)
                
                elif protocol == 'openvpn':
                    # OpenVPN needs server config and PSK file
                    server_config = handler.generate_server_config(configs)
                    psk_content = handler.generate_psk_file(configs)
                    self._write_openvpn_configs(server_config, psk_content)
                
                logger.info(f"Generated {protocol} server configuration for {len(configs)} connection(s)")
                
            except Exception as e:
                logger.error(f"Failed to generate {protocol} config: {e}", exc_info=True)
    
    def generate_client_configs(self, vpn_configs: Dict[str, Dict]):
        """Generate client configuration files for download"""
        for conn_name, config in vpn_configs.items():
            try:
                handler = config.get('handler')
                if not handler:
                    continue
                
                client_config = handler.generate_client_config(conn_name, config)
                
                # Write client config file
                airport_id = config.get('airport_id', conn_name)
                protocol = config.get('protocol', 'ipsec')
                filename = f"{airport_id}_{protocol}_client.conf"
                filepath = os.path.join(CLIENT_CONFIG_DIR, filename)
                
                with open(filepath, 'w') as f:
                    f.write(client_config)
                
                # Set restrictive permissions (owner read/write only)
                os.chmod(filepath, 0o600)
                
                logger.info(f"Generated client config: {filename}")
                
            except Exception as e:
                logger.error(f"Failed to generate client config for {conn_name}: {e}")
    
    def _write_ipsec_configs(self, ipsec_conf: str, ipsec_secrets: str):
        """Write IPsec configuration files atomically"""
        ipsec_conf_file = os.path.join(IPSEC_SHARED_DIR, 'ipsec.conf')
        ipsec_secrets_file = os.path.join(IPSEC_SHARED_DIR, 'ipsec.secrets')
        
        try:
            # Write ipsec.conf
            with open(ipsec_conf_file, 'w') as f:
                f.write(ipsec_conf)
            
            # Write ipsec.secrets atomically with secure permissions
            tmp_secrets = ipsec_secrets_file + ".tmp"
            try:
                with open(tmp_secrets, 'w') as f:
                    f.write(ipsec_secrets)
                os.chmod(tmp_secrets, 0o600)
                os.rename(tmp_secrets, ipsec_secrets_file)
            except Exception as e:
                if os.path.exists(tmp_secrets):
                    try:
                        os.remove(tmp_secrets)
                    except OSError:
                        pass
                raise
            
            logger.debug("IPsec configuration files written")
        except Exception as e:
            logger.error("Failed to write IPsec config files")
            raise
    
    def _write_wireguard_config(self, wg_config: str):
        """Write WireGuard configuration file atomically"""
        wg_config_file = os.path.join(WIREGUARD_SHARED_DIR, 'wg0.conf')
        
        try:
            tmp_config = wg_config_file + ".tmp"
            with open(tmp_config, 'w') as f:
                f.write(wg_config)
            os.chmod(tmp_config, 0o600)
            os.rename(tmp_config, wg_config_file)
            logger.debug("WireGuard configuration file written")
        except Exception as e:
            logger.error("Failed to write WireGuard config file")
            raise
    
    def _write_openvpn_configs(self, server_config: str, psk_content: str):
        """Write OpenVPN configuration files atomically"""
        server_config_file = os.path.join(OPENVPN_SHARED_DIR, 'server.conf')
        psk_file = os.path.join(OPENVPN_SHARED_DIR, 'psk.key')
        
        try:
            # Write server config
            tmp_config = server_config_file + ".tmp"
            with open(tmp_config, 'w') as f:
                f.write(server_config)
            os.chmod(tmp_config, 0o644)
            os.rename(tmp_config, server_config_file)
            
            # Write PSK file with secure permissions
            tmp_psk = psk_file + ".tmp"
            with open(tmp_psk, 'w') as f:
                f.write(psk_content)
            os.chmod(tmp_psk, 0o600)
            os.rename(tmp_psk, psk_file)
            
            logger.debug("OpenVPN configuration files written")
        except Exception as e:
            logger.error("Failed to write OpenVPN config files")
            raise
    
    def update_status_file(self):
        """Update status file with current connection states (protocol-agnostic)"""
        try:
            status = {
                "timestamp": int(time.time()),
                "connections": {}
            }
            
            for conn_name, config in self.connections.items():
                handler = config.get('handler')
                if not handler:
                    continue
                
                conn_status = handler.check_connection_status(conn_name)
                state = conn_status['status']
                
                last_connected = 0
                last_disconnected = 0
                uptime = 0
                
                if state == 'up':
                    last_connected = int(time.time())
                
                # Health check for up connections
                health_check = 'unknown'
                if state == 'up':
                    is_healthy = handler.health_check_connection(
                        conn_name,
                        config['remote_subnet']
                    )
                    health_check = 'pass' if is_healthy else 'fail'
                
                status["connections"][conn_name] = {
                    "airport_id": config['airport_id'],
                    "connection_name": conn_name,
                    "protocol": config.get('protocol', 'unknown'),
                    "status": state,
                    "last_connected": last_connected,
                    "last_disconnected": last_disconnected,
                    "uptime_seconds": uptime,
                    "health_check": health_check
                }
            
            # Atomic write
            tmp_file = STATUS_FILE + ".tmp"
            with open(tmp_file, 'w') as f:
                json.dump(status, f, indent=2)
            os.rename(tmp_file, STATUS_FILE)
            
            logger.debug("Status file updated")
        except Exception as e:
            logger.error(f"Failed to update status file: {e}")
    
    def monitor_connections(self):
        """Main monitoring loop"""
        logger.info("Starting VPN Manager service")
        
        while self.running:
            try:
                # Check if config file changed
                current_mtime = os.path.getmtime(CONFIG_PATH) if os.path.exists(CONFIG_PATH) else 0
                config_changed = current_mtime != self.config_mtime
                
                if config_changed:
                    logger.info("Configuration file changed, reloading...")
                    self.config_mtime = current_mtime
                
                # Load configuration
                config = self.load_config()
                if not config:
                    logger.warning("Failed to load config, retrying in 60s")
                    time.sleep(60)
                    continue
                
                # Parse VPN configs (handles key generation)
                vpn_configs = self.parse_vpn_configs(config)
                
                # Generate server and client configs if changed
                if config_changed or not self.connections:
                    self.generate_configs(vpn_configs)
                    self.generate_client_configs(vpn_configs)
                
                # Update connection tracking
                self.connections = vpn_configs
                
                # Check each connection status
                for conn_name, config in vpn_configs.items():
                    handler = config.get('handler')
                    if not handler:
                        continue
                    
                    conn_status = handler.check_connection_status(conn_name)
                    state = conn_status['status']
                    self.connection_states[conn_name] = state
                    
                    if state == 'down':
                        logger.warning(f"Connection {conn_name} is down")
                    elif state == 'up':
                        # Periodic health check
                        last_check = self.last_health_check.get(conn_name, 0)
                        if time.time() - last_check > 300:  # Every 5 minutes
                            is_healthy = handler.health_check_connection(
                                conn_name,
                                config['remote_subnet']
                            )
                            self.last_health_check[conn_name] = time.time()
                            
                            if not is_healthy:
                                logger.warning(f"Health check failed for {conn_name}")
                
                # Update status file
                self.update_status_file()
                
                # Sleep until next iteration
                time.sleep(UPDATE_INTERVAL)
                
            except Exception as e:
                logger.error(f"Error in monitoring loop: {e}", exc_info=True)
                time.sleep(60)  # Back off on error
        
        logger.info("VPN Manager service stopped")
    
    def run(self):
        """Start the service"""
        self.monitor_connections()


if __name__ == '__main__':
    import argparse
    
    parser = argparse.ArgumentParser(description='VPN Manager Service')
    parser.add_argument('--export-client', metavar='AIRPORT_ID', 
                       help='Generate and export client config for airport')
    args = parser.parse_args()
    
    if args.export_client:
        # Export client config mode
        manager = VPNManager()
        config = manager.load_config()
        if not config:
            logger.error("Failed to load configuration")
            sys.exit(1)
        
        vpn_configs = manager.parse_vpn_configs(config)
        
        # Find config for requested airport
        airport_config = None
        for conn_name, vpn_config in vpn_configs.items():
            if vpn_config.get('airport_id') == args.export_client.lower():
                airport_config = vpn_config
                break
        
        if not airport_config:
            logger.error(f"Airport '{args.export_client}' not found or VPN not enabled")
            sys.exit(1)
        
        # Generate client config
        handler = airport_config.get('handler')
        if not handler:
            logger.error("No handler available for this protocol")
            sys.exit(1)
        
        try:
            client_config = handler.generate_client_config(
                airport_config['connection_name'],
                airport_config
            )
            print(client_config)
        except Exception as e:
            logger.error(f"Failed to generate client config: {e}")
            sys.exit(1)
    else:
        # Normal service mode
        manager = VPNManager()
        manager.run()
