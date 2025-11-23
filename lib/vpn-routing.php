<?php
/**
 * VPN Routing Utilities
 * Handles VPN-aware routing for webcam fetches
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logger.php';

/**
 * Check if IP address is private
 * @param string $ip IP address
 * @return bool True if private IP
 */
function isPrivateIP($ip) {
    $parts = explode('.', $ip);
    if (count($parts) !== 4) {
        return false;
    }
    
    $first = (int)$parts[0];
    $second = (int)$parts[1];
    
    // Private IP ranges:
    // 10.0.0.0/8
    // 172.16.0.0/12
    // 192.168.0.0/16
    // 127.0.0.0/8 (loopback)
    
    return ($first === 10) ||
           ($first === 172 && $second >= 16 && $second <= 31) ||
           ($first === 192 && $second === 168) ||
           ($first === 127);
}

/**
 * Resolve hostname to IP address (with caching)
 * @param string $hostname Hostname
 * @return string|null IP address or null if resolution fails
 */
function resolveHostname($hostname) {
    static $cache = [];
    
    if (isset($cache[$hostname])) {
        return $cache[$hostname];
    }
    
    $ip = @gethostbyname($hostname);
    if ($ip && $ip !== $hostname) {
        $cache[$hostname] = $ip;
        return $ip;
    }
    
    return null;
}

/**
 * Extract IP address from camera URL
 * @param string $url Camera URL
 * @return string|null IP address or null if not found
 */
function extractIPFromURL($url) {
    // Try to extract IP directly from URL
    if (preg_match('/(?:rtsp|http|https):\/\/(\d+\.\d+\.\d+\.\d+)/', $url, $matches)) {
        return $matches[1];
    }
    
    // Extract hostname from URL
    if (preg_match('/(?:rtsp|http|https):\/\/([^:\/]+)/', $url, $matches)) {
        $hostname = $matches[1];
        // Resolve hostname to IP
        return resolveHostname($hostname);
    }
    
    return null;
}

/**
 * Check if IP is in subnet (CIDR notation)
 * @param string $ip IP address
 * @param string $subnet Subnet in CIDR notation (e.g., 192.168.1.0/24)
 * @return bool True if IP is in subnet
 */
function ipInSubnet($ip, $subnet) {
    list($subnet_ip, $mask) = explode('/', $subnet);
    
    $ip_long = ip2long($ip);
    $subnet_long = ip2long($subnet_ip);
    $mask_long = -1 << (32 - (int)$mask);
    
    return ($ip_long & $mask_long) === ($subnet_long & $mask_long);
}

/**
 * Check if a camera URL requires VPN routing
 * @param string $airportId Airport ID
 * @param array $cam Camera configuration
 * @return array|null VPN routing info or null if no VPN required
 */
function getVpnRoutingInfo($airportId, $cam) {
    $config = loadConfig();
    if (!$config || !isset($config['airports'][$airportId])) {
        return null;
    }
    
    $airport = $config['airports'][$airportId];
    $vpn = $airport['vpn'] ?? null;
    
    if (!$vpn || !($vpn['enabled'] ?? false)) {
        return null;
    }
    
    // Extract IP from camera URL
    $url = $cam['url'] ?? '';
    $ip = extractIPFromURL($url);
    
    if (!$ip) {
        return null;
    }
    
    // Check if IP is private (requires VPN)
    if (!isPrivateIP($ip)) {
        return null;
    }
    
    // Check if IP is in remote_subnet
    $remote_subnet = $vpn['remote_subnet'] ?? null;
    if ($remote_subnet && ipInSubnet($ip, $remote_subnet)) {
        return [
            'required' => true,
            'connection_name' => $vpn['connection_name'] ?? "{$airportId}_vpn",
            'remote_subnet' => $remote_subnet,
        ];
    }
    
    return null;
}

/**
 * Check if VPN connection is up
 * @param string $connectionName VPN connection name
 * @return bool True if VPN connection is up
 */
function isVpnConnectionUp($connectionName) {
    $statusFile = __DIR__ . '/../cache/vpn-status.json';
    
    if (!file_exists($statusFile)) {
        return false;
    }
    
    $statusData = @json_decode(file_get_contents($statusFile), true);
    if (!$statusData || !isset($statusData['connections'][$connectionName])) {
        return false;
    }
    
    $connStatus = $statusData['connections'][$connectionName];
    return ($connStatus['status'] ?? 'down') === 'up';
}

/**
 * Verify VPN before fetching camera
 * @param string $airportId Airport ID
 * @param array $cam Camera configuration
 * @return bool True if VPN is up or not required
 */
function verifyVpnForCamera($airportId, $cam) {
    $vpnInfo = getVpnRoutingInfo($airportId, $cam);
    
    if (!$vpnInfo || !$vpnInfo['required']) {
        return true; // No VPN required
    }
    
    $isUp = isVpnConnectionUp($vpnInfo['connection_name']);
    
    if (!$isUp) {
        aviationwx_log('warning', 'VPN connection down for camera fetch', [
            'airport' => $airportId,
            'connection' => $vpnInfo['connection_name'],
            'camera_url' => $cam['url'] ?? 'unknown'
        ], 'app');
    }
    
    return $isUp;
}

