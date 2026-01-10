<?php
/**
 * Cloudflare Analytics Integration
 * 
 * Fetches analytics data from Cloudflare Analytics API (GraphQL)
 * Uses multi-layer caching (APCu + file fallback) with 30-minute TTL
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
 * Caching Strategy:
 * - Primary: APCu cache (30 minutes)
 * - Fallback: File cache (up to 2 hours old if API fails)
 * - Stale data is preferred over no data (graceful degradation)
 * 
 * Unique Visitor Calculation:
 * - Fetches 24 hourly buckets from Cloudflare
 * - Sums unique visitors across all hours
 * - This provides a good estimate (may slightly overcount if same
 *   visitor returns in multiple hours, but much more accurate than
 *   taking max of a single hour)
 * 
 * @see https://developers.cloudflare.com/analytics/graphql-api/
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logger.php';

// Cache TTL for analytics data (30 minutes = 1800 seconds)
// Longer TTL to avoid API slowdowns and show stale data vs no data
const CLOUDFLARE_ANALYTICS_CACHE_TTL = 1800;

// APCu cache key for analytics data
const CLOUDFLARE_ANALYTICS_CACHE_KEY = 'cloudflare_analytics';

// Fallback cache file for persistence across APCu restarts
const CLOUDFLARE_ANALYTICS_FALLBACK_FILE = __DIR__ . '/../cache/cloudflare_analytics.json';

/**
 * Get Cloudflare Analytics data with multi-layer caching
 * 
 * Priority:
 * 1. APCu cache (30 min TTL)
 * 2. File cache fallback (persists across APCu restarts)
 * 3. Fresh API fetch
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
    
    // Try file cache fallback (in case APCu was cleared)
    $fallbackFile = CLOUDFLARE_ANALYTICS_FALLBACK_FILE;
    if (file_exists($fallbackFile)) {
        $cacheData = json_decode(file_get_contents($fallbackFile), true);
        if ($cacheData && isset($cacheData['cached_at'])) {
            $age = time() - $cacheData['cached_at'];
            // Use file cache if less than 2 hours old
            if ($age < 7200) {
                // Also restore to APCu
                if (function_exists('apcu_store')) {
                    apcu_store(CLOUDFLARE_ANALYTICS_CACHE_KEY, $cacheData, CLOUDFLARE_ANALYTICS_CACHE_TTL);
                }
                return $cacheData;
            }
        }
    }
    
    // Fetch fresh data
    $analytics = fetchCloudflareAnalytics();
    
    // If fetch failed, return stale data if available (better than nothing)
    if (empty($analytics) && isset($cacheData) && !empty($cacheData)) {
        aviationwx_log('warning', 'Cloudflare API failed, using stale cache', [
            'cache_age_seconds' => $age ?? 'unknown'
        ]);
        return $cacheData;
    }
    
    // Cache the result in both APCu and file
    if (!empty($analytics)) {
        if (function_exists('apcu_store')) {
            apcu_store(CLOUDFLARE_ANALYTICS_CACHE_KEY, $analytics, CLOUDFLARE_ANALYTICS_CACHE_TTL);
        }
        
        // Store in file cache as fallback
        $cacheDir = dirname($fallbackFile);
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        @file_put_contents($fallbackFile, json_encode($analytics));
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
    // Config structure: $config['config']['cloudflare']
    if (!isset($config['config']['cloudflare'])) {
        return [];
    }
    
    $cf = $config['config']['cloudflare'];
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
    
    // GraphQL query for zone analytics (using hourly groups)
    // https://developers.cloudflare.com/analytics/graphql-api/features/data-sets/
    $query = <<<'GRAPHQL'
query GetZoneAnalytics($zoneTag: string, $since: Time!, $until: Time!) {
  viewer {
    zones(filter: {zoneTag: $zoneTag}) {
      httpRequests1hGroups(
        limit: 24,
        filter: {datetime_geq: $since, datetime_leq: $until}
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
    
    if (!isset($data['data']['viewer']['zones'][0]['httpRequests1hGroups'])) {
        aviationwx_log('warning', 'Cloudflare Analytics API returned unexpected structure', [
            'response' => $response
        ]);
        return [];
    }
    
    $hourlyGroups = $data['data']['viewer']['zones'][0]['httpRequests1hGroups'];
    
    // Aggregate hourly data to get 24-hour totals
    $totalRequests = 0;
    $totalBytes = 0;
    
    // Track unique visitors across all hours
    // Note: Cloudflare's zone-level GraphQL API doesn't provide true 24h unique
    // count (that would require account-level access). Each hour's uniques are
    // deduplicated within that hour only.
    //
    // We sum the hourly uniques as the best available estimate. This may slightly
    // overcount if the same user visits in multiple hours, but it's the most
    // representative metric available and better than taking max (one hour) or
    // average (underestimates total reach).
    //
    // For "Pilots Served Today", this represents the total number of unique
    // user sessions across the day, which is the appropriate metric for reach.
    $totalUniques = 0;
    
    foreach ($hourlyGroups as $hour) {
        $totalRequests += $hour['sum']['requests'] ?? 0;
        $totalBytes += $hour['sum']['bytes'] ?? 0;
        $totalUniques += $hour['uniq']['uniques'] ?? 0;
    }
    
    return [
        'unique_visitors_today' => $totalUniques,
        'requests_today' => $totalRequests,
        'bandwidth_today' => $totalBytes,
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
