#!/usr/bin/env python3
"""
VPN Manager Service
Manages IPsec VPN connections based on airports.json configuration
"""

import json
import os
import subprocess
import time
import logging
import signal
import sys
from pathlib import Path
from typing import Dict, Optional, List

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
IPSEC_SHARED_DIR = '/etc/ipsec-shared'
IPSEC_CONF = os.path.join(IPSEC_SHARED_DIR, 'ipsec.conf')
IPSEC_SECRETS = os.path.join(IPSEC_SHARED_DIR, 'ipsec.secrets')
IPSEC_CONF_DIR = '/etc/ipsec.d'
UPDATE_INTERVAL = 30  # seconds

# Test mode: Use static IP instead of %any for local testing
VPN_TEST_MODE = os.getenv('VPN_TEST_MODE', '').lower() in ('true', '1', 'yes')
VPN_TEST_CLIENT_IP = os.getenv('VPN_TEST_CLIENT_IP', '127.0.0.1')  # Default to localhost for testing

# Ensure directories exist
os.makedirs(IPSEC_SHARED_DIR, exist_ok=True)
os.makedirs(os.path.dirname(STATUS_FILE), exist_ok=True)


class VPNManager:
    def __init__(self):
        self.running = True
        self.connections: Dict[str, Dict] = {}
        self.connection_states: Dict[str, str] = {}
        self.last_health_check: Dict[str, float] = {}
        self.config_mtime = 0
        
        # Setup signal handlers for graceful shutdown
        signal.signal(signal.SIGTERM, self.signal_handler)
        signal.signal(signal.SIGINT, self.signal_handler)
        
        logger.info("VPN Manager initialized")
    
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
        """Extract VPN configurations from airports.json"""
        vpn_configs = {}
        
        for airport_id, airport in config.get('airports', {}).items():
            vpn = airport.get('vpn')
            if vpn and vpn.get('enabled'):
                connection_name = vpn.get('connection_name', f"{airport_id}_vpn")
                vpn_configs[connection_name] = {
                    'airport_id': airport_id,
                    'connection_name': connection_name,
                    'remote_subnet': vpn.get('remote_subnet'),
                    'psk': vpn.get('psk'),
                    'ike_version': vpn.get('ike_version', '2'),
                    'encryption': vpn.get('encryption', 'aes256gcm128'),
                    'dh_group': vpn.get('dh_group', '14'),
                    'lifetime': vpn.get('lifetime', '3600'),
                }
        
        return vpn_configs
    
    def generate_ipsec_conf(self, vpn_configs: Dict[str, Dict]) -> str:
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
        
        # If no VPN configs, just return setup section
        if not vpn_configs:
            return '\n'.join(config_lines)
        
        # Convert DH group number to bit length mapping
        dh_map = {'14': '2048', '15': '3072', '16': '4096', '17': '6144', '18': '8192'}
        
        for conn_name, config in vpn_configs.items():
            airport_id = config['airport_id']
            remote_subnet = config['remote_subnet']
            ike_version = config['ike_version']
            encryption = config['encryption']
            dh_group = config['dh_group']
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
                # Server is a responder - always use %any to accept from any IP
                # The original issue was 'auto=start' making server try to initiate
                # With 'auto=add', server waits for connections, so %any is fine
                f"    right=%any",
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
    
    def generate_ipsec_secrets(self, vpn_configs: Dict[str, Dict]) -> str:
        """Generate strongSwan ipsec.secrets from VPN configurations"""
        secret_lines = [
            "# IPsec secrets",
            "# Auto-generated from airports.json",
            "",
        ]
        
        # If no VPN configs, return empty secrets file
        if not vpn_configs:
            return '\n'.join(secret_lines)
        
        for conn_name, config in vpn_configs.items():
            psk = config.get('psk')
            if psk:
                secret_lines.append(f": PSK \"{psk}\"")
            else:
                logger.warning(f"PSK not configured for {conn_name}")
        
        return '\n'.join(secret_lines)
    
    def write_config_files(self, ipsec_conf: str, ipsec_secrets: str):
        """Write generated configuration files
        
        Note: PSKs are written in plain text to ipsec.secrets because strongSwan
        requires them in this format. The file is protected with 0o600 permissions
        (read/write for owner only) and written atomically to prevent race conditions.
        """
        try:
            # Write ipsec.conf
            with open(IPSEC_CONF, 'w') as f:
                f.write(ipsec_conf)
            
            # Write ipsec.secrets atomically with secure permissions
            # Use temporary file to ensure atomic write and prevent race conditions
            tmp_secrets = IPSEC_SECRETS + ".tmp"
            try:
                # Write to temporary file first
                with open(tmp_secrets, 'w') as f:
                    f.write(ipsec_secrets)
                
                # Set restrictive permissions before moving (owner read/write only)
                os.chmod(tmp_secrets, 0o600)
                
                # Atomic rename - ensures file is never in inconsistent state
                os.rename(tmp_secrets, IPSEC_SECRETS)
            except Exception as e:
                # Clean up temp file on error
                if os.path.exists(tmp_secrets):
                    try:
                        os.remove(tmp_secrets)
                    except OSError:
                        pass
                raise
            
            logger.info("Configuration files written successfully")
        except Exception as e:
            # Never log the actual error content if it might contain secrets
            logger.error("Failed to write config files")
            raise
    
    def reload_ipsec_config(self):
        """Reload strongSwan configuration
        
        Note: The VPN server container watches for config file changes
        and automatically reloads. This method is kept for compatibility
        but the server handles reloads automatically.
        """
        # The VPN server watches for file changes and reloads automatically
        # We just need to ensure files are written atomically
        logger.debug("Configuration files written, VPN server will auto-reload")
    
    def check_connection_status(self, connection_name: str) -> Dict[str, any]:
        """Check status of a VPN connection"""
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
            logger.error(f"Failed to check status for {connection_name}: {e}")
            return {'status': 'down', 'details': str(e)}
    
    def health_check_connection(self, connection_name: str, remote_subnet: str) -> bool:
        """Perform health check on VPN connection by pinging remote gateway"""
        # Extract gateway IP from subnet (first IP)
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
    
    def update_status_file(self):
        """Update status file with current connection states"""
        try:
            status = {
                "timestamp": int(time.time()),
                "connections": {}
            }
            
            for conn_name, config in self.connections.items():
                conn_status = self.check_connection_status(conn_name)
                state = conn_status['status']
                
                # Get last connected/disconnected times
                last_connected = 0
                last_disconnected = 0
                uptime = 0
                
                if state == 'up':
                    # Try to get connection uptime from ipsec status
                    # For now, use current time as last_connected
                    last_connected = int(time.time())
                    # TODO: Parse actual connection time from ipsec status
                
                # Health check for up connections
                health_check = 'unknown'
                if state == 'up':
                    is_healthy = self.health_check_connection(
                        conn_name,
                        config['remote_subnet']
                    )
                    health_check = 'pass' if is_healthy else 'fail'
                
                status["connections"][conn_name] = {
                    "airport_id": config['airport_id'],
                    "connection_name": conn_name,
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
                
                # Parse VPN configs
                vpn_configs = self.parse_vpn_configs(config)
                
                # Generate and write config files if changed
                if config_changed or not self.connections:
                    ipsec_conf = self.generate_ipsec_conf(vpn_configs)
                    ipsec_secrets = self.generate_ipsec_secrets(vpn_configs)
                    self.write_config_files(ipsec_conf, ipsec_secrets)
                    self.reload_ipsec_config()
                
                # Update connection tracking
                self.connections = vpn_configs
                
                # Check each connection
                for conn_name, config in vpn_configs.items():
                    conn_status = self.check_connection_status(conn_name)
                    state = conn_status['status']
                    self.connection_states[conn_name] = state
                    
                    if state == 'down':
                        logger.warning(f"Connection {conn_name} is down")
                    elif state == 'up':
                        # Periodic health check
                        last_check = self.last_health_check.get(conn_name, 0)
                        if time.time() - last_check > 300:  # Every 5 minutes
                            is_healthy = self.health_check_connection(
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
    manager = VPNManager()
    manager.run()

