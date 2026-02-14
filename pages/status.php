<?php
/**
 * Status Page
 * Displays system health status for AviationWX.org
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/sentry-js.php';
require_once __DIR__ . '/../lib/constants.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/seo.php';
require_once __DIR__ . '/../lib/process-utils.php';
require_once __DIR__ . '/../lib/weather/source-timestamps.php';
require_once __DIR__ . '/../lib/webcam-format-generation.php';
require_once __DIR__ . '/../lib/weather-health.php';
require_once __DIR__ . '/../lib/webcam-image-metrics.php';
require_once __DIR__ . '/../lib/cloudflare-analytics.php';
require_once __DIR__ . '/../lib/status-checks.php'; // Health check functions
require_once __DIR__ . '/../lib/status-utils.php'; // Utility functions

// =============================================================================
// OPTIMIZATION: Cached data loaders
// =============================================================================

// Prevent caching (only in web context, not CLI)
if (php_sapi_name() !== 'cli' && !headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
}


// =============================================================================
// PAGE DATA LOADING
// =============================================================================
// Note: All helper functions (health checks, utilities) are now in:
// - lib/status-checks.php (health check functions)
// - lib/status-utils.php (formatting and utility functions)
// =============================================================================

// Load configuration
$config = loadConfig();
if ($config === null) {
    http_response_code(503);
    die('Service Unavailable: Configuration cannot be loaded');
}

// Load usage metrics with APCu caching (eliminates duplicate file reads)
require_once __DIR__ . '/../lib/status-metrics.php';
$usageMetrics = getStatusMetricsRolling(METRICS_STATUS_PAGE_DAYS, 60);
$multiPeriodMetrics = getStatusMetricsMultiPeriod(60);
$rolling24h = getStatusMetricsRolling(1, 60);

// Get local performance metrics (cached for 30s)
require_once __DIR__ . '/../lib/status-metrics.php';
require_once __DIR__ . '/../lib/performance-metrics.php';
$imageProcessingMetrics = getCachedData(
    fn() => getImageProcessingMetrics(),
    'status_image_processing',
    null,
    30
);
$pageRenderMetrics = getCachedData(
    fn() => getPageRenderMetrics(),
    'status_page_render',
    null,
    30
);
$nodePerformance = getCachedData(function() {
    return getNodePerformance();
}, 'status_node_performance', null, 30);

// Get system health (cached for 30s)
require_once __DIR__ . '/../lib/cached-data-loader.php';
$systemHealth = getCachedData(function() {
    return checkSystemHealth();
}, 'status_system_health', null, 30);

// Get Cloudflare Analytics (cached for 30min via cloudflare-analytics.php)
$cfAnalytics = getCloudflareAnalyticsForStatus();
$cloudflareConfigured = !empty($cfAnalytics); // Show all CF metrics if configured

// Get public API health (if enabled, cached for 30s)
require_once __DIR__ . '/../lib/public-api/config.php';
$publicApiHealth = null;
if (isPublicApiEnabled()) {
    $publicApiHealth = getCachedData(function() {
        return checkPublicApiHealth();
    }, 'status_public_api_health', CACHE_PUBLIC_API_HEALTH_FILE, 30);
}

// Get airport health for each configured airport (cached for 30s)
$airportHealth = getCachedData(function() use ($config) {
    $health = [];
    if (isset($config['airports']) && is_array($config['airports'])) {
        foreach ($config['airports'] as $airportId => $airport) {
            $health[] = checkAirportHealth($airportId, $airport);
        }
    }
    return $health;
}, 'status_airport_health', null, 30);

// Sort airports by status (down first, then maintenance, then degraded, then operational)
usort($airportHealth, function($a, $b) {
    $statusOrder = ['down' => 0, 'maintenance' => 1, 'degraded' => 2, 'operational' => 3];
    return $statusOrder[$a['status']] <=> $statusOrder[$b['status']];
});

// Prevent HTML output in CLI mode (tests, scripts)
// Functions are still available for testing, but HTML output is skipped
if (php_sapi_name() === 'cli') {
    // In CLI mode, just return - functions are already defined and can be tested
    return;
}

?>
<!DOCTYPE html>
<html lang="en">
<script>
// Apply dark mode immediately based on browser preference to prevent flash
(function() {
    if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
        document.documentElement.classList.add('dark-mode');
    }
})();
</script>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php
    // Initialize Sentry JavaScript SDK for frontend error tracking
    renderSentryJsInit('status');
    ?>
    <title>AviationWX Status</title>
    
    <?php
    // Favicon and icon tags
    echo generateFaviconTags();
    echo "\n    ";
    ?>
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            line-height: 1.6;
            color: #333;
            background: #f5f5f5;
            padding: 2rem 1rem 4rem 1rem;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .header {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
            text-align: center;
        }
        
        .header h1 {
            font-size: 2rem;
            font-weight: 600;
            margin: 0 0 0.5rem 0;
        }
        
        .header .subtitle {
            color: #666;
            font-size: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .cloudflare-metrics {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e0e0e0;
        }
        
        .cf-metric {
            text-align: center;
        }
        
        .cf-metric-value {
            font-size: 1.8rem;
            font-weight: bold;
            color: #0066cc;
            display: block;
            margin-bottom: 0.25rem;
        }
        
        .cf-metric-label {
            font-size: 0.85rem;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .cf-metric-subtext {
            font-size: 0.75rem;
            color: #999;
            margin-top: 0.25rem;
        }
            margin-bottom: 0.5rem;
            color: #1a1a1a;
        }
        
        .header .subtitle {
            color: #555;
            font-size: 0.9rem;
        }
        
        .status-indicator {
            font-size: 1.5rem;
            line-height: 1;
        }
        
        .status-indicator.green { color: #10b981; }
        .status-indicator.yellow { color: #f59e0b; }
        .status-indicator.red { color: #ef4444; }
        .status-indicator.orange { color: #f97316; }
        .status-indicator.gray { color: #9ca3af; }
        
        .status-text {
            font-size: 1.1rem;
            font-weight: 600;
        }
        
        .status-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 1.5rem;
            overflow: hidden;
        }
        
        .section-header {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: #1a1a1a;
        }
        
        .status-card-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .airport-card-header {
            cursor: pointer;
            user-select: none;
            transition: background-color 0.2s;
        }
        
        .airport-card-header:hover {
            background-color: #f9fafb;
        }
        
        .airport-card-header h2 {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1a1a1a;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .airport-header-content {
            display: flex;
            flex-direction: row;
            align-items: center;
            gap: 1.5rem;
            flex: 1;
        }
        
        .airport-views-summary {
            font-size: 0.75rem;
            color: #888;
            display: flex;
            align-items: center;
            gap: 0.35rem;
            flex: 1;
            justify-content: center;
        }
        
        .airport-views-summary .views-label {
            color: #999;
        }
        
        .airport-views-summary .views-period {
            color: #666;
            font-weight: 500;
        }
        
        .airport-views-summary .views-sep {
            color: #ccc;
        }
        
        .usage-metrics-block {
            font-size: 0.85rem;
            color: #666;
        }
        
        .usage-metrics-block .metrics-line {
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
            margin-bottom: 0.25rem;
        }
        
        .usage-metrics-block .metrics-line:last-child {
            margin-bottom: 0;
        }
        
        .usage-metrics-block .metric-group {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }
        
        .usage-metrics-block .metric-label {
            color: #888;
        }
        
        .usage-metrics-block .metric-detail {
            color: #999;
            font-size: 0.85em;
            margin-left: 0.25rem;
        }
        
        .usage-metrics-block .metric-value {
            font-weight: 600;
            color: #333;
        }
        
        .usage-metrics-block .metric-breakdown {
            color: #666;
        }
        
        .status-card-header h2 {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1a1a1a;
        }
        
        .expand-icon {
            display: inline-block;
            transition: transform 0.2s;
            font-size: 0.875rem;
            color: #6b7280;
        }
        
        .airport-card-header.expanded .expand-icon {
            transform: rotate(90deg);
        }
        
        .airport-card-body {
            overflow: hidden;
            transition: max-height 0.3s ease-out, padding 0.3s ease-out;
        }
        
        .status-card-body.airport-card-body.collapsed {
            max-height: 0;
            padding: 0;
        }
        
        .status-card-body.airport-card-body.expanded {
            max-height: 5000px;
            transition: max-height 0.5s ease-in, padding 0.5s ease-in;
        }
        
        .status-card-header .status-badge {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            font-weight: 600;
            text-transform: capitalize;
        }
        
        .status-card-body {
            padding: 1.5rem;
        }
        
        .component-list {
            list-style: none;
        }
        
        .component-item {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .component-item:last-child {
            border-bottom: none;
        }
        
        .component-info {
            flex: 1;
        }
        
        .component-name {
            font-weight: 600;
            color: #1a1a1a;
            margin-bottom: 0.25rem;
        }
        
        .component-message {
            font-size: 0.875rem;
            color: #555;
            margin-bottom: 0.25rem;
        }
        
        .component-message code {
            font-size: 0.85rem;
            background: #f5f5f5;
            padding: 0.15rem 0.4rem;
            border-radius: 3px;
        }
        
        .api-links {
            margin-bottom: 1rem;
            color: #666;
            font-size: 0.9rem;
        }
        
        .api-links a {
            color: #0066cc;
        }
        
        .api-links a:hover {
            text-decoration: underline;
        }
        
        .component-timestamp {
            font-size: 0.75rem;
            color: #999;
            font-style: italic;
        }
        
        .component-status {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            font-weight: 600;
            text-transform: capitalize;
            margin-left: 1rem;
        }
        
        .footer {
            text-align: center;
            color: #555;
            font-size: 0.875rem;
            margin-top: 3rem;
            padding-top: 2rem;
            border-top: 1px solid #eee;
        }
        
        .footer a {
            color: #0066cc;
            text-decoration: none;
        }
        
        .footer a:hover {
            text-decoration: underline;
        }
        
        .node-metrics-row {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 2rem;
            padding-bottom: 1rem;
            margin-bottom: 0.75rem;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .metric-inline {
            display: flex;
            align-items: baseline;
            gap: 0.5rem;
        }
        
        .metric-label-inline {
            font-size: 0.8rem;
            color: #6b7280;
        }
        
        .metric-value-inline {
            font-size: 0.95rem;
            font-weight: 600;
            color: #1a1a1a;
            font-variant-numeric: tabular-nums;
        }
        
        .metric-sub {
            font-size: 0.7rem;
            font-weight: 400;
            color: #9ca3af;
        }
        
        @media (max-width: 768px) {
            .component-item {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .component-status {
                margin-left: 0;
            }
            
            .node-metrics-row {
                flex-direction: column;
                gap: 0.75rem;
            }
            
            /* Airport header stacks vertically on mobile */
            .status-card-header.airport-card-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.75rem;
            }
            
            .airport-header-content {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
                width: 100%;
            }
            
            .airport-views-summary {
                justify-content: flex-start;
            }
            
            .status-card-header.airport-card-header .status-badge {
                align-self: flex-start;
            }
        }
        
        /* ============================================
           Dark Mode Overrides for Status Page
           Automatically applied based on browser preference
           ============================================ */
        @media (prefers-color-scheme: dark) {
            body {
                background: #121212;
                color: #e0e0e0;
            }
            
            .section-header {
                color: #e0e0e0;
            }
        }
        
        body.dark-mode {
            background: #121212;
            color: #e0e0e0;
        }
        
        body.dark-mode .header {
            background: #1e1e1e;
            box-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }
        
        body.dark-mode .header h1 {
            color: #e0e0e0;
        }
        
        body.dark-mode .header .subtitle {
            color: #a0a0a0;
        }
        
        body.dark-mode .cloudflare-metrics {
            border-top-color: #333;
        }
        
        body.dark-mode .cf-metric-value {
            color: #4a9eff;
        }
        
        body.dark-mode .cf-metric-label {
            color: #a0a0a0;
        }
        
        body.dark-mode .cf-metric-subtext {
            color: #666;
        }
        
        body.dark-mode .status-card {
            background: #1e1e1e;
            box-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }
        
        body.dark-mode .section-header {
            color: #e0e0e0;
        }
        
        body.dark-mode .status-card-header {
            border-bottom-color: #333;
        }
        
        body.dark-mode .status-card-header h2 {
            color: #e0e0e0;
        }
        
        body.dark-mode .airport-card-header:hover {
            background-color: #252525;
        }
        
        body.dark-mode .airport-views-summary {
            color: #777;
        }
        
        body.dark-mode .airport-views-summary .views-label {
            color: #666;
        }
        
        body.dark-mode .airport-views-summary .views-period {
            color: #999;
        }
        
        body.dark-mode .airport-views-summary .views-sep {
            color: #555;
        }
        
        body.dark-mode .usage-metrics-block .metric-label {
            color: #777;
        }
        
        body.dark-mode .usage-metrics-block .metric-detail {
            color: #666;
        }
        
        body.dark-mode .usage-metrics-block .metric-value {
            color: #e0e0e0;
        }
        
        body.dark-mode .usage-metrics-block .metric-breakdown {
            color: #999;
        }
        
        body.dark-mode .component-item {
            border-bottom-color: #333;
        }
        
        body.dark-mode .component-name {
            color: #e0e0e0;
        }
        
        body.dark-mode .component-message {
            color: #a0a0a0;
        }
        
        body.dark-mode .component-message code {
            background: #2a2a2a;
            color: #ff7eb6;
        }
        
        body.dark-mode .api-links {
            color: #a0a0a0;
        }
        
        body.dark-mode .api-links a {
            color: #4a9eff;
        }
        
        body.dark-mode .component-timestamp {
            color: #707070;
        }
        
        body.dark-mode .node-metrics-row {
            border-bottom-color: #333;
        }
        
        body.dark-mode .metric-label-inline {
            color: #a0a0a0;
        }
        
        body.dark-mode .metric-value-inline {
            color: #e0e0e0;
        }
        
        body.dark-mode .metric-sub {
            color: #707070;
        }
        
        body.dark-mode .footer {
            border-top-color: #333;
            color: #a0a0a0;
        }
        
        body.dark-mode .footer a {
            color: #4a9eff;
        }
        
        body.dark-mode h2[style*="font-size: 1.5rem"] {
            color: #e0e0e0;
        }
        
        }
    </style>
    <link rel="stylesheet" href="/public/css/navigation.css">
</head>
<body>
    <script>
    // Sync dark-mode class from html to body
    if (document.documentElement.classList.contains('dark-mode')) {
        document.body.classList.add('dark-mode');
    }
    </script>
    
    <?php require_once __DIR__ . '/../lib/navigation.php'; ?>
    
    <div class="container">
        <div class="header">
            <h1>AviationWX Status</h1>
            <div class="subtitle">Real-time status of AviationWX.org services</div>
            
            <?php if ($cloudflareConfigured): ?>
            <!-- Cloudflare Analytics (Last 24 Hours) -->
            <div class="cloudflare-metrics">
                <div class="cf-metric">
                    <span class="cf-metric-value"><?= number_format($cfAnalytics['unique_visitors_today'] ?? 0) ?></span>
                    <span class="cf-metric-label">Unique Visitors</span>
                    <div class="cf-metric-subtext">Last 24 hours</div>
                </div>
                <div class="cf-metric">
                    <span class="cf-metric-value"><?= number_format($cfAnalytics['requests_today'] ?? 0) ?></span>
                    <span class="cf-metric-label">Total Requests</span>
                    <div class="cf-metric-subtext">Last 24 hours</div>
                </div>
                <div class="cf-metric">
                    <span class="cf-metric-value"><?= $cfAnalytics['bandwidth_formatted'] ?? '0 B' ?></span>
                    <span class="cf-metric-label">Bandwidth</span>
                    <div class="cf-metric-subtext">Last 24 hours</div>
                </div>
                <div class="cf-metric">
                    <span class="cf-metric-value"><?= number_format($cfAnalytics['requests_per_visitor'] ?? 0, 1) ?></span>
                    <span class="cf-metric-label">Requests/Visitor</span>
                    <div class="cf-metric-subtext">Engagement</div>
                </div>
                <div class="cf-metric">
                    <span class="cf-metric-value"><?= number_format($cfAnalytics['threats_blocked_today'] ?? 0) ?></span>
                    <span class="cf-metric-label">Threats Blocked</span>
                    <div class="cf-metric-subtext">Last 24 hours</div>
                </div>
                <?php if (!empty($imageProcessingMetrics) && $imageProcessingMetrics['sample_count'] > 0): ?>
                <div class="cf-metric">
                    <span class="cf-metric-value"><?= number_format($imageProcessingMetrics['avg_ms'], 0) ?>ms</span>
                    <span class="cf-metric-label">Image Processing</span>
                    <div class="cf-metric-subtext">Avg (<?= $imageProcessingMetrics['sample_count'] ?> samples)</div>
                </div>
                <?php endif; ?>
                <?php if (!empty($pageRenderMetrics) && $pageRenderMetrics['sample_count'] > 0): ?>
                <div class="cf-metric">
                    <span class="cf-metric-value"><?= number_format($pageRenderMetrics['avg_ms'], 0) ?>ms</span>
                    <span class="cf-metric-label">Dashboard Load</span>
                    <div class="cf-metric-subtext">Avg (<?= $pageRenderMetrics['sample_count'] ?> samples)</div>
                </div>
                <?php endif; ?>
                <div class="cf-metric">
                    <span class="cf-metric-value"><?= number_format($rolling24h['global']['tiles_by_source']['openweathermap'] ?? 0) ?></span>
                    <span class="cf-metric-label">Cloud Tiles Served</span>
                    <div class="cf-metric-subtext">Last 24 hours</div>
                </div>
                <div class="cf-metric">
                    <span class="cf-metric-value"><?= number_format($rolling24h['global']['tiles_by_source']['rainviewer'] ?? 0) ?></span>
                    <span class="cf-metric-label">Rain Tiles Served</span>
                    <div class="cf-metric-subtext">Last 24 hours</div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- System Status Card -->
        <div class="status-card">
            <div class="status-card-header">
                <h2>System Status</h2>
            </div>
            <div class="status-card-body">
                <!-- Node Performance Row -->
                <div class="node-metrics-row">
                    <div class="metric-inline">
                        <span class="metric-label-inline">CPU Load</span>
                        <span class="metric-value-inline">
                            <?php 
                            $load = $nodePerformance['cpu_load'];
                            if ($load['1min'] !== null) {
                                echo htmlspecialchars($load['1min']) . ' <span class="metric-sub">(1m)</span> ';
                                echo htmlspecialchars($load['5min']) . ' <span class="metric-sub">(5m)</span> ';
                                echo htmlspecialchars($load['15min']) . ' <span class="metric-sub">(15m)</span>';
                            } else {
                                echo '-';
                            }
                            ?>
                        </span>
                    </div>
                    <div class="metric-inline" title="Memory: Rolling averages (RSS).">
                        <span class="metric-label-inline">Memory</span>
                        <span class="metric-value-inline">
                            <?php 
                            $memoryAvg = $nodePerformance['memory_average'] ?? null;
                            if ($memoryAvg && ($memoryAvg['1min'] !== null || $memoryAvg['5min'] !== null || $memoryAvg['15min'] !== null)) {
                                // Show averages
                                $parts = [];
                                if ($memoryAvg['1min'] !== null) {
                                    $parts[] = formatBytes((int)$memoryAvg['1min']) . ' <span class="metric-sub">(1m)</span>';
                                }
                                if ($memoryAvg['5min'] !== null) {
                                    $parts[] = formatBytes((int)$memoryAvg['5min']) . ' <span class="metric-sub">(5m)</span>';
                                }
                                if ($memoryAvg['15min'] !== null) {
                                    $parts[] = formatBytes((int)$memoryAvg['15min']) . ' <span class="metric-sub">(15m)</span>';
                                }
                                echo implode(' ', $parts);
                            } else {
                                // Fallback to current value if averages not available
                                echo formatBytes($nodePerformance['memory_used_bytes']);
                            }
                            ?>
                        </span>
                    </div>
                    <div class="metric-inline" title="Cache: <?php echo formatBytes($nodePerformance['storage_breakdown']['cache']); ?>, Uploads: <?php echo formatBytes($nodePerformance['storage_breakdown']['uploads']); ?>, Logs: <?php echo formatBytes($nodePerformance['storage_breakdown']['logs']); ?>">
                        <span class="metric-label-inline">Storage</span>
                        <span class="metric-value-inline">
                            <?php echo formatBytes($nodePerformance['storage_used_bytes']); ?>
                            <span class="metric-sub">(<?php 
                                $parts = [];
                                if ($nodePerformance['storage_breakdown']['cache'] > 0) {
                                    $parts[] = formatBytes($nodePerformance['storage_breakdown']['cache']) . ' cache';
                                }
                                if ($nodePerformance['storage_breakdown']['uploads'] > 0) {
                                    $parts[] = formatBytes($nodePerformance['storage_breakdown']['uploads']) . ' uploads';
                                }
                                if ($nodePerformance['storage_breakdown']['logs'] > 0) {
                                    $parts[] = formatBytes($nodePerformance['storage_breakdown']['logs']) . ' logs';
                                }
                                echo !empty($parts) ? implode(', ', $parts) : 'cache';
                            ?>)</span>
                        </span>
                    </div>
                </div>
                
                <ul class="component-list">
                    <?php foreach ($systemHealth['components'] as $component): ?>
                    <li class="component-item">
                        <div class="component-info">
                            <div class="component-name"><?php echo htmlspecialchars($component['name']); ?></div>
                            <div class="component-message"><?php echo htmlspecialchars($component['message']); ?></div>
                            <?php if (isset($component['metrics']) && is_array($component['metrics']) && !empty($component['metrics'])): ?>
                            <div class="component-metrics" style="margin-top: 0.25rem; font-size: 0.8rem; color: #888;">
                                <?php 
                                $metricParts = [];
                                if (isset($component['metrics']['total_generations_last_hour'])) {
                                    $metricParts[] = $component['metrics']['total_generations_last_hour'] . ' generated (1h)';
                                }
                                if (isset($component['metrics']['generation_success_rate']) && $component['metrics']['generation_success_rate'] < 100) {
                                    $metricParts[] = $component['metrics']['generation_success_rate'] . '% gen success';
                                }
                                if (isset($component['metrics']['promotion_success_rate']) && $component['metrics']['promotion_success_rate'] < 100) {
                                    $metricParts[] = $component['metrics']['promotion_success_rate'] . '% promo success';
                                }
                                echo htmlspecialchars(implode(' · ', $metricParts));
                                ?>
                            </div>
                            <?php endif; ?>
                            <?php if (isset($component['lastChanged']) && $component['lastChanged'] > 0): ?>
                            <div class="component-timestamp">
                                Last changed: <?php echo formatRelativeTime($component['lastChanged']); ?>
                                <span style="color: #ccc;"> • </span>
                                <?php echo formatAbsoluteTime($component['lastChanged']); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="component-status">
                            <span class="status-indicator <?php echo getStatusColor($component['status']); ?>">
                                <?php echo getStatusIcon($component['status']); ?>
                            </span>
                            <?php echo ucfirst($component['status']); ?>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        
        <?php if ($publicApiHealth !== null): ?>
        <!-- Public API Status Card -->
        <div class="status-card">
            <div class="status-card-header">
                <h2>Public API</h2>
                <span class="status-badge">
                    <span class="status-indicator <?php echo getStatusColor($publicApiHealth['status']); ?>">
                        <?php echo getStatusIcon($publicApiHealth['status']); ?>
                    </span>
                    <?php echo ucfirst($publicApiHealth['status']); ?>
                </span>
            </div>
            <div class="status-card-body">
                <div class="api-links">
                    <a href="https://api.aviationwx.org" target="_blank" rel="noopener">api.aviationwx.org</a> · 
                    <a href="https://api.aviationwx.org/openapi.json" target="_blank" rel="noopener">OpenAPI Spec</a>
                </div>
                <ul class="component-list">
                    <?php foreach ($publicApiHealth['endpoints'] as $endpoint): ?>
                    <li class="component-item">
                        <div class="component-info">
                            <div class="component-name"><?php echo htmlspecialchars($endpoint['name']); ?></div>
                            <div class="component-message">
                                <code><?php echo htmlspecialchars($endpoint['endpoint']); ?></code>
                                · <?php echo htmlspecialchars($endpoint['message']); ?>
                            </div>
                        </div>
                        <div class="component-status">
                            <span class="status-indicator <?php echo getStatusColor($endpoint['status']); ?>">
                                <?php echo getStatusIcon($endpoint['status']); ?>
                            </span>
                            <?php echo ucfirst($endpoint['status']); ?>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Airport Status Cards -->
        <?php if (!empty($airportHealth)): ?>
        <h2 class="section-header">Site Status</h2>
        <?php foreach ($airportHealth as $airport): ?>
        <?php
        // All airports start collapsed - users click to expand
        $isExpanded = false;
        ?>
        <?php 
        // Get multi-period metrics for this airport
        $airportIdLower = strtolower($airport['id']);
        $airportPeriodMetrics = $multiPeriodMetrics[$airportIdLower] ?? null;
        // Get view counts (default to 0 if no metrics)
        $hourViews = $airportPeriodMetrics['hour']['page_views'] ?? 0;
        $dayViews = $airportPeriodMetrics['day']['page_views'] ?? 0;
        $weekViews = $airportPeriodMetrics['week']['page_views'] ?? 0;
        ?>
        <div class="status-card">
            <div class="status-card-header airport-card-header <?php echo $isExpanded ? 'expanded' : ''; ?>" 
                 onclick="toggleAirport('<?php echo htmlspecialchars($airport['id']); ?>')">
                <div class="airport-header-content">
                    <h2>
                        <span class="expand-icon">▶</span>
                        <?php echo htmlspecialchars($airport['id']); ?>
                    </h2>
                    <div class="airport-views-summary">
                        <span class="views-label">Views:</span>
                        <span class="views-period" title="Last hour"><?php echo number_format($hourViews); ?>/hour</span>
                        <span class="views-sep">·</span>
                        <span class="views-period" title="Today"><?php echo number_format($dayViews); ?>/day</span>
                        <span class="views-sep">·</span>
                        <span class="views-period" title="Last 7 days"><?php echo number_format($weekViews); ?>/week</span>
                    </div>
                </div>
                <span class="status-badge">
                    <?php if ($airport['status'] === 'maintenance'): ?>
                        Under Maintenance <span class="status-indicator <?php echo getStatusColor($airport['status']); ?>"><?php echo getStatusIcon($airport['status']); ?></span>
                    <?php elseif ($airport['status'] === 'down' && !empty($airport['limited_availability'])): ?>
                        <span class="status-indicator green">🔋</span>
                        Limited availability
                    <?php else: ?>
                        <span class="status-indicator <?php echo getStatusColor($airport['status']); ?>">
                            <?php echo getStatusIcon($airport['status']); ?>
                        </span>
                        <?php echo ucfirst($airport['status']); ?>
                    <?php endif; ?>
                </span>
            </div>
            <div class="status-card-body airport-card-body <?php echo $isExpanded ? 'expanded' : 'collapsed'; ?>" 
                 id="airport-<?php echo htmlspecialchars($airport['id']); ?>-body">
                <ul class="component-list">
                    <?php foreach ($airport['components'] as $component): ?>
                    <?php 
                    // Check if this is weather or webcams (which have individual sources)
                    $isWeather = ($component['name'] === 'Weather Sources' && isset($component['sources']));
                    $isWebcams = (isset($component['cameras']) && is_array($component['cameras']) && !empty($component['cameras']));
                    ?>
                    <?php if ($isWeather): ?>
                        <!-- Weather Sources - show individual sources -->
                        <?php foreach ($component['sources'] as $source): ?>
                        <li class="component-item">
                            <div class="component-info">
                                <div class="component-name"><?php echo htmlspecialchars($source['name']); ?></div>
                                <div class="component-message"><?php echo htmlspecialchars($source['message']); ?></div>
                                <?php if (isset($source['lastChanged']) && $source['lastChanged'] > 0): ?>
                                <div class="component-timestamp">
                                    Last changed: <?php echo formatRelativeTime($source['lastChanged']); ?>
                                    <span style="color: #ccc;"> • </span>
                                    <?php echo formatAbsoluteTime($source['lastChanged']); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="component-status">
                                <span class="status-indicator <?php echo getStatusColor($source['status']); ?>">
                                    <?php echo getStatusIcon($source['status']); ?>
                                </span>
                                <?php echo ucfirst($source['status']); ?>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    <?php elseif ($isWebcams): ?>
                        <!-- Webcams - show individual cameras without overall status -->
                        <?php foreach ($component['cameras'] as $camera): ?>
                        <li class="component-item">
                            <div class="component-info">
                                <div class="component-name"><?php echo htmlspecialchars($camera['name']); ?></div>
                                <div class="component-message">
                                    <?php echo htmlspecialchars($camera['message']); ?>
                                    <?php 
                                    // Show rejection reason if rejections occurred
                                    $metrics = $camera['image_metrics'] ?? ['rejection_reasons' => []];
                                    $rejected = $metrics['rejected'] ?? 0;
                                    $reasons = $metrics['rejection_reasons'] ?? [];
                                    
                                    if ($rejected > 0 && !empty($reasons)) {
                                        arsort($reasons);
                                        $topReason = array_key_first($reasons);
                                        $topCount = $reasons[$topReason];
                                        
                                        $reasonLabels = [
                                            'no_exif' => 'No EXIF',
                                            'timestamp_future' => 'Clock Ahead',
                                            'timestamp_too_old' => 'Too Old',
                                            'timestamp_unreliable' => 'Clock Wrong',
                                            'exif_rewrite_failed' => 'EXIF Update Failed',
                                            'invalid_exif_timestamp' => 'Invalid Timestamp',
                                            'validation_failed' => 'Invalid Image',
                                            'incomplete_upload' => 'Incomplete Upload',
                                            'image_corrupt' => 'Corrupt Image',
                                            'file_read_error' => 'File Error',
                                            'error_frame' => 'Error Frame',
                                            'file_not_readable' => 'File Error',
                                            'size_too_small' => 'Too Small',
                                            'size_limit_exceeded' => 'Too Large',
                                            'extension_not_allowed' => 'Wrong Format',
                                            'invalid_mime_type' => 'Invalid MIME',
                                            'invalid_format' => 'Invalid Format',
                                            'exif_invalid' => 'EXIF Invalid',
                                            'system_error' => 'System Error',
                                            'file_too_old' => 'File Too Old'
                                        ];
                                        $reasonDisplay = $reasonLabels[$topReason] ?? ucwords(str_replace('_', ' ', $topReason));
                                        
                                        echo ' <span style="color: #ff6b6b;">(';
                                        if ($topCount === $rejected) {
                                            echo $reasonDisplay;
                                        } else {
                                            echo $topCount . 'x ' . $reasonDisplay;
                                        }
                                        echo ')</span>';
                                    }
                                    ?>
                                </div>
                                <?php if (isset($camera['lastChanged']) && $camera['lastChanged'] > 0): ?>
                                <div class="component-timestamp">
                                    Last changed: <?php echo formatRelativeTime($camera['lastChanged']); ?>
                                    <span style="color: #ccc;"> • </span>
                                    <?php echo formatAbsoluteTime($camera['lastChanged']); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="component-status">
                                <span class="status-indicator <?php echo getStatusColor($camera['status']); ?>">
                                    <?php echo getStatusIcon($camera['status']); ?>
                                </span>
                                <?php echo ucfirst($camera['status']); ?>
                            </div>
                        </li>
                        <?php endforeach; ?>
                        
                        <!-- Cache Summary for this airport -->
                        <?php if (isset($component['cache_summary']) && isset($component['cache_stats'])): ?>
                        <?php
                        $cacheStats = $component['cache_stats'];
                        $totalImages = $cacheStats['total_images'];
                        $sizeMB = round($cacheStats['total_size_bytes'] / (1024 * 1024), 1);
                        $imagesVerified = $cacheStats['images_verified'] ?? 0;
                        $imagesRejected = $cacheStats['images_rejected'] ?? 0;
                        ?>
                        <li class="component-item">
                            <div class="component-info">
                                <div class="component-name">Cache Summary</div>
                                <div class="component-message usage-metrics-block">
                                    <div class="metrics-line">
                                        <span class="metric-group">
                                            <span class="metric-label">Total Images:</span>
                                            <span class="metric-value"><?php echo number_format($totalImages); ?></span>
                                        </span>
                                        <span class="metric-group">
                                            <span class="metric-label">Size:</span>
                                            <span class="metric-value"><?php echo $sizeMB; ?> MB</span>
                                        </span>
                                        <?php if ($totalImages > 0 && isset($component['cameras'])): ?>
                                        <span class="metric-group">
                                            <span class="metric-label">Avg per Camera:</span>
                                            <span class="metric-value"><?php echo round($totalImages / count($component['cameras'])); ?></span>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="metrics-line" style="margin-top: 0.5rem;">
                                        <span class="metric-group">
                                            <span class="metric-label">24h Verified:</span>
                                            <span class="metric-value" style="color: #22c55e;"><?php echo number_format($imagesVerified); ?></span>
                                        </span>
                                        <span class="metric-group">
                                            <span class="metric-label">24h Rejected:</span>
                                            <span class="metric-value" style="color: <?php echo $imagesRejected > 0 ? '#ef4444' : '#6b7280'; ?>;"><?php echo number_format($imagesRejected); ?></span>
                                        </span>
                                        <?php if ($imagesVerified + $imagesRejected > 0): ?>
                                        <span class="metric-group">
                                            <span class="metric-label">Verification Rate:</span>
                                            <?php 
                                            $verificationRate = round(($imagesVerified / ($imagesVerified + $imagesRejected)) * 100, 1);
                                            $rateColor = $verificationRate >= 90 ? '#22c55e' : ($verificationRate >= 70 ? '#eab308' : '#ef4444');
                                            ?>
                                            <span class="metric-value" style="color: <?php echo $rateColor; ?>;"><?php echo $verificationRate; ?>%</span>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </li>
                        <?php endif; ?>
                    <?php else: ?>
                        <!-- Other components - show with status indicator -->
                        <li class="component-item">
                            <div class="component-info">
                                <div class="component-name"><?php echo htmlspecialchars($component['name']); ?></div>
                                <div class="component-message"><?php echo htmlspecialchars($component['message']); ?></div>
                                <?php if (isset($component['lastChanged']) && $component['lastChanged'] > 0): ?>
                                <div class="component-timestamp">
                                    Last changed: <?php echo formatRelativeTime($component['lastChanged']); ?>
                                    <span style="color: #ccc;"> • </span>
                                    <?php echo formatAbsoluteTime($component['lastChanged']); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="component-status">
                                <span class="status-indicator <?php echo getStatusColor($component['status']); ?>">
                                    <?php echo getStatusIcon($component['status']); ?>
                                </span>
                                <?php echo ucfirst($component['status']); ?>
                            </div>
                        </li>
                    <?php endif; ?>
                    <?php endforeach; ?>
                    
                    <?php 
                    // Get usage metrics for this airport (use already loaded $airportPeriodMetrics from header)
                    $webcamMetrics = $airportPeriodMetrics['webcams'] ?? [];
                    $weeklyAirportMetrics = $usageMetrics['airports'][$airportIdLower] ?? null;
                    
                    // Calculate webcam totals (serves = successful image deliveries)
                    $totalWebcamServes = 0;
                    $formatTotals = ['jpg' => 0, 'webp' => 0];
                    $sizeTotals = []; // Dynamic: height-based variants like '720', '360', 'original'
                    foreach ($webcamMetrics as $camData) {
                        foreach ($camData['by_format'] ?? [] as $fmt => $count) {
                            $formatTotals[$fmt] += $count;
                            $totalWebcamServes += $count;
                        }
                        foreach ($camData['by_size'] ?? [] as $sz => $count) {
                            if (!isset($sizeTotals[$sz])) {
                                $sizeTotals[$sz] = 0;
                            }
                            $sizeTotals[$sz] += $count;
                        }
                    }
                    
                    // Get request counts (default to 0)
                    $weatherRequests = $weeklyAirportMetrics['weather_requests'] ?? 0;
                    $webcamRequests = $weeklyAirportMetrics['webcam_requests'] ?? 0;
                    ?>
                    
                    <li class="component-item">
                        <div class="component-info">
                            <div class="component-name">API Requests (7d)</div>
                            <div class="component-message usage-metrics-block">
                                <div class="metrics-line">
                                    <span class="metric-group">
                                        <span class="metric-label">Weather:</span>
                                        <span class="metric-value"><?php echo number_format($weatherRequests); ?></span>
                                    </span>
                                    <span class="metric-group">
                                        <span class="metric-label">Webcam:</span>
                                        <?php if ($webcamRequests > 0): ?>
                                        <span class="metric-value"><?php echo number_format($webcamRequests); ?></span>
                                        <?php if ($totalWebcamServes > 0): ?>
                                        <span class="metric-detail" title="Serves = images delivered from server (not browser cache)">(<?php echo number_format($totalWebcamServes); ?> serves)</span>
                                        <?php endif; ?>
                                        <?php else: ?>
                                        <span class="metric-value">0</span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <?php if ($totalWebcamServes > 0): ?>
                                <div class="metrics-line">
                                    <span class="metric-group">
                                        <span class="metric-label">Formats:</span>
                                        <span class="metric-breakdown">
                                            <?php
                                            $formatParts = [];
                                            foreach ($formatTotals as $fmt => $count) {
                                                if ($count > 0) {
                                                    $pct = round(($count / $totalWebcamServes) * 100);
                                                    $formatParts[] = strtoupper($fmt) . " {$pct}%";
                                                }
                                            }
                                            echo implode(' · ', $formatParts);
                                            ?>
                                        </span>
                                    </span>
                                </div>
                                <div class="metrics-line">
                                    <span class="metric-group">
                                        <span class="metric-label">Sizes:</span>
                                        <span class="metric-breakdown">
                                            <?php
                                            $sizeParts = [];
                                            $sizeTotal = array_sum($sizeTotals);
                                            if ($sizeTotal > 0) {
                                                foreach ($sizeTotals as $sz => $count) {
                                                    if ($count > 0) {
                                                        $pct = round(($count / $sizeTotal) * 100);
                                                        $sizeParts[] = ucfirst($sz) . " {$pct}%";
                                                    }
                                                }
                                            }
                                            echo implode(' · ', $sizeParts);
                                            ?>
                                        </span>
                                    </span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
        
        <div class="footer">
            <p>
                &copy; <?= date('Y') ?> <a href="https://aviationwx.org">AviationWX.org</a> • 
                <a href="https://airports.aviationwx.org">Airports</a> • 
                <a href="https://guides.aviationwx.org">Guides</a> • 
                <a href="https://aviationwx.org#about-the-project">Built for pilots, by pilots</a> • 
                <a href="https://github.com/alexwitherspoon/aviationwx.org" target="_blank" rel="noopener">Open Source<?php $gitSha = getGitSha(); echo $gitSha ? ' - ' . htmlspecialchars($gitSha) : ''; ?></a> • 
                <a href="https://terms.aviationwx.org">Terms of Service</a> • 
                <a href="https://api.aviationwx.org">API</a> • 
                <a href="https://status.aviationwx.org">Status</a>
            </p>
            <p style="margin-top: 0.5rem; font-size: 0.75rem; color: #999;">
                Last updated: <?php echo date('Y-m-d H:i:s T'); ?>
            </p>
        </div>
    </div>
    
    <script>
        (function() {
            'use strict';
            
            function toggleAirport(airportId) {
                const header = document.querySelector(`[onclick="toggleAirport('${airportId}')"]`);
                const body = document.getElementById(`airport-${airportId}-body`);
                
                if (header && body) {
                    const isExpanded = header.classList.contains('expanded');
                    
                    if (isExpanded) {
                        header.classList.remove('expanded');
                        body.classList.remove('expanded');
                        body.classList.add('collapsed');
                    } else {
                        header.classList.add('expanded');
                        body.classList.remove('collapsed');
                        body.classList.add('expanded');
                    }
                }
            }
            
            // Expose to global scope for onclick handlers
            window.toggleAirport = toggleAirport;
        })();
    </script>
</body>
</html>


