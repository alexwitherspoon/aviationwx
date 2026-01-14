<?php
/**
 * Public API Configuration Helpers
 * 
 * Provides functions to load and validate public API configuration
 * from airports.json. The API is disabled by default and requires
 * explicit configuration to enable.
 */

require_once __DIR__ . '/../config.php';

/**
 * Check if the public API is enabled
 * 
 * @return bool True if public_api.enabled is true in config
 */
function isPublicApiEnabled(): bool
{
    $config = loadConfig();
    return isset($config['config']['public_api']['enabled'])
        && $config['config']['public_api']['enabled'] === true;
}

/**
 * Get the public API configuration
 * 
 * @return array|null Public API config array or null if not configured
 */
function getPublicApiConfig(): ?array
{
    $config = loadConfig();
    return $config['config']['public_api'] ?? null;
}

/**
 * Get the API version string
 * 
 * @return string Version string (default: "1")
 */
function getPublicApiVersion(): string
{
    $config = getPublicApiConfig();
    return $config['version'] ?? '1';
}

/**
 * Get rate limits for a specific tier
 * 
 * Tiers:
 * - anonymous: Public users without API key (default limits)
 * - partner: External partners with API key (higher limits)
 * 
 * Note: First-party internal requests (embeds, dashboard) forward the original
 * client IP and use anonymous tier limits. This ensures each end user gets
 * their fair share of rate limits rather than a special internal tier.
 * 
 * @param string $tier 'anonymous' or 'partner'
 * @return array Rate limit configuration with requests_per_minute, requests_per_hour, requests_per_day
 */
function getPublicApiRateLimits(string $tier = 'anonymous'): array
{
    $config = getPublicApiConfig();
    $defaults = [
        'anonymous' => [
            'requests_per_minute' => 20,
            'requests_per_hour' => 200,
            'requests_per_day' => 2000,
        ],
        'partner' => [
            'requests_per_minute' => 120,
            'requests_per_hour' => 5000,
            'requests_per_day' => 50000,
        ],
    ];
    
    if ($config && isset($config['rate_limits'][$tier])) {
        return array_merge($defaults[$tier], $config['rate_limits'][$tier]);
    }
    
    return $defaults[$tier] ?? $defaults['anonymous'];
}

/**
 * Get the maximum number of airports for bulk requests
 * 
 * @return int Maximum airports allowed in bulk request
 */
function getPublicApiBulkMaxAirports(): int
{
    $config = getPublicApiConfig();
    return $config['bulk_max_airports'] ?? 10;
}

/**
 * Check if weather history is enabled for the public API
 * 
 * @return bool True if weather history is enabled
 */
function isPublicApiWeatherHistoryEnabled(): bool
{
    $config = getPublicApiConfig();
    return $config['weather_history_enabled'] ?? true;
}

/**
 * Get weather history retention hours
 * 
 * @return int Hours of weather history to retain
 */
function getPublicApiWeatherHistoryRetentionHours(): int
{
    $config = getPublicApiConfig();
    return $config['weather_history_retention_hours'] ?? 24;
}

/**
 * Get the attribution text for API responses
 * 
 * @return string Attribution text
 */
function getPublicApiAttributionText(): string
{
    $config = getPublicApiConfig();
    return $config['attribution_text'] ?? 'Data from AviationWX.org';
}

/**
 * Get all partner API keys
 * 
 * @return array Associative array of API keys and their metadata
 */
function getPublicApiPartnerKeys(): array
{
    $config = getPublicApiConfig();
    return $config['partner_keys'] ?? [];
}

/**
 * Validate an API key and return partner info if valid
 * 
 * @param string $apiKey The API key to validate
 * @return array|null Partner info if valid, null if invalid
 */
function validatePublicApiKey(string $apiKey): ?array
{
    if (empty($apiKey)) {
        return null;
    }
    
    $keys = getPublicApiPartnerKeys();
    
    if (!isset($keys[$apiKey])) {
        return null;
    }
    
    $keyInfo = $keys[$apiKey];
    
    // Check if key is enabled
    if (!isset($keyInfo['enabled']) || $keyInfo['enabled'] !== true) {
        return null;
    }
    
    return $keyInfo;
}

