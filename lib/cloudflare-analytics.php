<?php
/**
 * Cloudflare Analytics Integration
 * 
 * Fetches analytics data from Cloudflare Analytics API (GraphQL)
 * Uses APCu cache with TTL to avoid excessive API calls
 * 
 * Setup:
 * 1. Generate Cloudflare API token with "Analytics:Read" permission
 * 2. Add to config/airports.json global section:
 *    "cloudflare": {
 *      "api_token": "your-token-here",
 *      "zone_id": "your-zone-id-here",
 *      "account_id": "your-account-id-here"
 *    }
 * 
 * Usage:
 *   $analytics = getCloudflareAnalytics();
 *   $uniqueVisitorsToday = $analytics['unique_visitors_today'] ?? 0;
 *   $requestsToday = $analytics['requests_today'] ?? 0;
 * 
 * @see https://developers.cloudflare.com/analytics/graphql-api/
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logger.php';

// Cache TTL for analytics data (5 minutes = 300 seconds)
const CLOUDFLARE_ANALYTICS_CACHE_TTL = 300;

// APCu cache key for analytics data
const CLOUDFLARE_ANALYTICS_CACHE_KEY = 'cloudflare_analytics';

/**
 * Get Cloudflare Analytics data with caching
 * 
 * @return array Analytics data with keys:
 *   - unique_visitors_today: Unique visitors in last 24h
 *   - requests_today: Total requests in last 24h
 *   - bandwidth_today: Bandwidth served in last 24h (bytes)
 *   - cached_at: Unix timestamp when data was fetched
 */
function getCloudflareAnalytics(): array {
    // Check APCu cache first
    if (function_exists('apcu_fetch')) {
        $cached = apcu_fetch(CLOUDFLARE_ANALYTICS_CACHE_KEY, $success);
        if ($success && is_array($cached)) {
            return $cached;
        }
    }
    
    // Fetch fresh data
    $analytics = fetchCloudflareAnalytics();
    
    // Cache the result
    if (function_exists('apcu_store') && !empty($analytics)) {
        apcu_store(CLOUDFLARE_ANALYTICS_CACHE_KEY, $analytics, CLOUDFLARE_ANALYTICS_CACHE_TTL);
    }
    
    return $analytics;
}

/**
 * Fetch fresh analytics data from Cloudflare API
 * 
 * @return array Analytics data or empty array on failure
 */
function fetchCloudflareAnalytics(): array {
    $config = loadConfig();
    
    // Check if Cloudflare credentials are configured
    if (!isset($config['cloudflare'])) {
        return [];
    }
    
    $cf = $config['cloudflare'];
    $apiToken = $cf['api_token'] ?? '';
    $zoneId = $cf['zone_id'] ?? '';
    $accountId = $cf['account_id'] ?? '';
    
    if (empty($apiToken) || empty($zoneId)) {
        return [];
    }
    
    // In test mode, return mock data
    if (isTestMode() || shouldMockExternalServices()) {
        return getMockCloudflareAnalytics();
    }
    
    // Calculate time range (last 24 hours)
    $now = new DateTime('now', new DateTimeZone('UTC'));
    $yesterday = (clone $now)->modify('-24 hours');
    
    // GraphQL query for zone analytics
    // https://developers.cloudflare.com/analytics/graphql-api/features/data-sets/
    $query = <<<'GRAPHQL'
query GetZoneAnalytics($zoneTag: string, $since: Time!, $until: Time!) {
  viewer {
    zones(filter: {zoneTag: $zoneTag}) {
      httpRequests1dGroups(
        limit: 1,
        filter: {
          datetime_geq: $since,
          datetime_leq: $until
        }
      ) {
        sum {
          requests
          bytes
        }
        uniq {
          uniques
        }
      }
    }
  }
}
GRAPHQL;

    $variables = [
        'zoneTag' => $zoneId,
        'since' => $yesterday->format('Y-m-d\TH:i:s\Z'),
        'until' => $now->format('Y-m-d\TH:i:s\Z')
    ];
    
    $payload = [
        'query' => $query,
        'variables' => $variables
    ];
    
    $ch = curl_init('https://api.cloudflare.com/client/v4/graphql');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiToken
        ],
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($response === false || $httpCode !== 200) {
        aviationwx_log('error', 'Cloudflare Analytics API request failed', [
            'http_code' => $httpCode,
            'curl_error' => $curlError
        ]);
        return [];
    }
    
    $data = json_decode($response, true);
    
    if (!isset($data['data']['viewer']['zones'][0]['httpRequests1dGroups'][0])) {
        aviationwx_log('warning', 'Cloudflare Analytics API returned unexpected structure', [
            'response' => $response
        ]);
        return [];
    }
    
    $stats = $data['data']['viewer']['zones'][0]['httpRequests1dGroups'][0];
    
    return [
        'unique_visitors_today' => $stats['uniq']['uniques'] ?? 0,
        'requests_today' => $stats['sum']['requests'] ?? 0,
        'bandwidth_today' => $stats['sum']['bytes'] ?? 0,
        'cached_at' => time()
    ];
}

/**
 * Get mock analytics data for testing
 * 
 * @return array Mock analytics data
 */
function getMockCloudflareAnalytics(): array {
    return [
        'unique_visitors_today' => 1250,
        'requests_today' => 45600,
        'bandwidth_today' => 2147483648, // ~2GB
        'cached_at' => time()
    ];
}

/**
 * Clear analytics cache (useful for testing or admin actions)
 * 
 * @return bool True if cache was cleared
 */
function clearCloudflareAnalyticsCache(): bool {
    if (function_exists('apcu_delete')) {
        return apcu_delete(CLOUDFLARE_ANALYTICS_CACHE_KEY);
    }
    return false;
}

/**
 * Get analytics data for status page display
 * Includes additional derived metrics
 * 
 * @return array Status display data
 */
function getCloudflareAnalyticsForStatus(): array {
    $analytics = getCloudflareAnalytics();
    
    if (empty($analytics)) {
        return [];
    }
    
    // Calculate cache age
    $cacheAge = isset($analytics['cached_at']) ? (time() - $analytics['cached_at']) : 0;
    
    // Format bandwidth
    $bandwidth = $analytics['bandwidth_today'] ?? 0;
    $bandwidthFormatted = formatBytesForAnalytics($bandwidth);
    
    // Calculate requests per visitor (engagement metric)
    $uniqueVisitors = $analytics['unique_visitors_today'] ?? 0;
    $requests = $analytics['requests_today'] ?? 0;
    $requestsPerVisitor = $uniqueVisitors > 0 ? round($requests / $uniqueVisitors, 1) : 0;
    
    return [
        'unique_visitors_today' => $uniqueVisitors,
        'requests_today' => $requests,
        'bandwidth_today' => $bandwidth,
        'bandwidth_formatted' => $bandwidthFormatted,
        'requests_per_visitor' => $requestsPerVisitor,
        'cache_age_seconds' => $cacheAge,
        'cached_at' => $analytics['cached_at'] ?? null
    ];
}

/**
 * Format bytes to human-readable string (for analytics display)
 * 
 * @param int $bytes Number of bytes
 * @return string Formatted string (e.g., "1.5 GB")
 */
function formatBytesForAnalytics(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $index = 0;
    $value = $bytes;
    
    while ($value >= 1024 && $index < count($units) - 1) {
        $value /= 1024;
        $index++;
    }
    
    if ($index === 0) {
        return $value . ' ' . $units[$index];
    }
    
    return number_format($value, 1) . ' ' . $units[$index];
}
