<?php
/**
 * Public API Documentation Page
 * 
 * Serves as the landing page for api.aviationwx.org
 * Displays comprehensive API documentation in a styled HTML format.
 */

require_once __DIR__ . '/../../lib/public-api/config.php';
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/seo.php';

// Check if API is enabled
$apiEnabled = isPublicApiEnabled();
$apiConfig = getPublicApiConfig();

// Get airport count for stats
$config = loadConfig();
$airportCount = 0;
if ($config !== null) {
    $enabledAirports = getEnabledAirports($config);
    $airportCount = count($enabledAirports);
}

// SEO variables
$pageTitle = 'AviationWX Public API - Real-time Aviation Weather Data';
$pageDescription = 'Free public API for real-time aviation weather data, webcam images, and airport information. Access weather conditions, flight categories, and webcam feeds programmatically.';
$canonicalUrl = 'https://api.aviationwx.org';
$baseUrl = getBaseUrl();

// Rate limits
$anonymousLimits = getPublicApiRateLimits('anonymous');
$partnerLimits = getPublicApiRateLimits('partner');
$attribution = getPublicApiAttributionText();
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
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <meta name="description" content="<?= htmlspecialchars($pageDescription) ?>">
    <link rel="canonical" href="<?= htmlspecialchars($canonicalUrl) ?>">
    
    <?php echo generateFaviconTags(); ?>
    
    <link rel="stylesheet" href="/public/css/navigation.css">
    
    <style>
        /* Light mode variables */
        :root {
            --bg-primary: #ffffff;
            --bg-secondary: #f8f9fa;
            --bg-tertiary: #e9ecef;
            --text-primary: #1a1a1a;
            --text-secondary: #555;
            --text-muted: #666;
            --accent-blue: #0066cc;
            --accent-green: #28a745;
            --accent-yellow: #d29922;
            --accent-red: #dc3545;
            --border-color: #e0e0e0;
            --code-bg: #f4f4f4;
        }
        
        /* Dark mode variables (explicit) */
        body.dark-mode {
            --bg-primary: #0d1117;
            --bg-secondary: #161b22;
            --bg-tertiary: #21262d;
            --text-primary: #e6edf3;
            --text-secondary: #8b949e;
            --text-muted: #6e7681;
            --accent-blue: #58a6ff;
            --accent-green: #3fb950;
            --accent-yellow: #d29922;
            --accent-red: #f85149;
            --border-color: #30363d;
            --code-bg: #1a1f26;
        }
        
        /* Auto dark mode from OS preference */
        @media (prefers-color-scheme: dark) {
            body:not(.light-mode) {
                --bg-primary: #0d1117;
                --bg-secondary: #161b22;
                --bg-tertiary: #21262d;
                --text-primary: #e6edf3;
                --text-secondary: #8b949e;
                --text-muted: #6e7681;
                --accent-blue: #58a6ff;
                --accent-green: #3fb950;
                --accent-yellow: #d29922;
                --accent-red: #f85149;
                --border-color: #30363d;
                --code-bg: #1a1f26;
            }
        }
        
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Noto Sans', Helvetica, Arial, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            line-height: 1.6;
            font-size: 16px;
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        
        .container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        main {
            margin-top: 1rem;
        }
        
        header {
            text-align: center;
            padding: 3rem 0;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 2rem;
        }
        
        h1 {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
            background: linear-gradient(135deg, var(--accent-blue), var(--accent-green));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .tagline {
            color: var(--text-secondary);
            font-size: 1.2rem;
        }
        
        h2 {
            color: var(--text-primary);
            font-size: 1.5rem;
            margin: 2rem 0 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        h3 {
            color: var(--text-primary);
            font-size: 1.1rem;
            margin: 1.5rem 0 0.5rem;
        }
        
        p {
            margin: 0.75rem 0;
            color: var(--text-secondary);
        }
        
        a {
            color: var(--accent-blue);
            text-decoration: none;
        }
        
        a:hover {
            text-decoration: underline;
        }
        
        .quick-start {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1.5rem;
            margin: 1.5rem 0;
        }
        
        .quick-start h3 {
            margin-top: 0;
            color: var(--accent-green);
        }
        
        code {
            font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
            font-size: 0.9em;
            background: var(--code-bg);
            padding: 0.2em 0.4em;
            border-radius: 4px;
        }
        
        pre {
            background: var(--code-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1rem;
            overflow-x: auto;
            margin: 1rem 0;
        }
        
        pre code {
            background: none;
            padding: 0;
        }
        
        .endpoint {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            margin: 1rem 0;
            overflow: hidden;
        }
        
        .endpoint-header {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .method {
            background: var(--accent-green);
            color: var(--bg-primary);
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-weight: 600;
            font-size: 0.85rem;
        }
        
        .endpoint-path {
            font-family: 'SFMono-Regular', Consolas, monospace;
            color: var(--text-primary);
        }
        
        .endpoint-body {
            padding: 1rem;
        }
        
        .endpoint-desc {
            color: var(--text-secondary);
            margin-bottom: 0.5rem;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 1rem 0;
        }
        
        th, td {
            text-align: left;
            padding: 0.75rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        th {
            color: var(--text-primary);
            font-weight: 600;
        }
        
        td {
            color: var(--text-secondary);
        }
        
        .badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .badge-green {
            background: rgba(63, 185, 80, 0.2);
            color: var(--accent-green);
        }
        
        .badge-yellow {
            background: rgba(210, 153, 34, 0.2);
            color: var(--accent-yellow);
        }
        
        .rate-limits {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin: 1rem 0;
        }
        
        .rate-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1rem;
        }
        
        .rate-card h4 {
            margin: 0 0 0.5rem;
            color: var(--text-primary);
        }
        
        .rate-card ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .rate-card li {
            color: var(--text-secondary);
            padding: 0.25rem 0;
        }
        
        .warning {
            background: rgba(210, 153, 34, 0.1);
            border: 1px solid rgba(210, 153, 34, 0.3);
            border-radius: 8px;
            padding: 1rem;
            margin: 1rem 0;
            color: var(--accent-yellow);
        }
        
        footer {
            margin-top: 3rem;
            padding-top: 2rem;
            border-top: 1px solid var(--border-color);
            text-align: center;
            color: var(--text-muted);
            font-size: 0.9rem;
        }
        
        footer a {
            color: var(--accent-blue);
            text-decoration: none;
            transition: opacity 0.2s;
        }
        
        footer a:hover {
            opacity: 0.8;
            text-decoration: none;
        }
        
        .stats {
            display: flex;
            justify-content: center;
            gap: 2rem;
            margin: 1.5rem 0;
        }
        
        .stat {
            text-align: center;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--accent-blue);
        }
        
        .stat-label {
            color: var(--text-muted);
            font-size: 0.9rem;
        }
        
        <?php if (!$apiEnabled): ?>
        .api-disabled {
            background: rgba(248, 81, 73, 0.1);
            border: 1px solid rgba(248, 81, 73, 0.3);
            border-radius: 8px;
            padding: 2rem;
            text-align: center;
            margin: 2rem 0;
        }
        
        .api-disabled h2 {
            color: var(--accent-red);
            border: none;
            margin-top: 0;
        }
        <?php endif; ?>
    </style>
</head>
<body>
    <script>
    // Sync dark-mode class from html to body
    if (document.documentElement.classList.contains('dark-mode')) {
        document.body.classList.add('dark-mode');
    }
    </script>
    <?php require_once __DIR__ . '/../../lib/navigation.php'; ?>
    <main>
    <div class="container">
        <header>
            <h1>AviationWX Public API</h1>
            <p class="tagline">Real-time weather, webcam images, and 24-hour history for all airports - programmatically.</p>
            
            <div class="stats">
                <div class="stat">
                    <div class="stat-value"><?= $airportCount ?></div>
                    <div class="stat-label">Airports</div>
                </div>
                <div class="stat">
                    <div class="stat-value">v<?= htmlspecialchars(getPublicApiVersion()) ?></div>
                    <div class="stat-label">API Version</div>
                </div>
            </div>
        </header>
        
        <?php if (!$apiEnabled): ?>
        <div class="api-disabled">
            <h2>API Not Enabled</h2>
            <p>The public API is not currently enabled. Please check back later.</p>
        </div>
        <?php else: ?>
        
        <div class="quick-start">
            <h3>🚀 Quick Start</h3>
            <p>Get current weather for an airport:</p>
            <pre><code>curl https://api.aviationwx.org/v1/airports/kspb/weather</code></pre>
            <p>List all available airports:</p>
            <pre><code>curl https://api.aviationwx.org/v1/airports</code></pre>
        </div>
        
        <h2>Authentication</h2>
        <p>The API supports two access tiers:</p>
        
        <div class="rate-limits">
            <div class="rate-card">
                <h4><span class="badge badge-green">Anonymous</span></h4>
                <p>No API key required</p>
                <ul>
                    <li><?= $anonymousLimits['requests_per_minute'] ?> requests/minute</li>
                    <li><?= number_format($anonymousLimits['requests_per_hour']) ?> requests/hour</li>
                    <li><?= number_format($anonymousLimits['requests_per_day']) ?> requests/day</li>
                </ul>
            </div>
            <div class="rate-card">
                <h4><span class="badge badge-yellow">Partner</span></h4>
                <p>Requires API key</p>
                <ul>
                    <li><?= $partnerLimits['requests_per_minute'] ?> requests/minute</li>
                    <li><?= number_format($partnerLimits['requests_per_hour']) ?> requests/hour</li>
                    <li><?= number_format($partnerLimits['requests_per_day']) ?> requests/day</li>
                </ul>
            </div>
        </div>
        
        <p>To use an API key, include it in the <code>X-API-Key</code> header:</p>
        <pre><code>curl -H "X-API-Key: your_api_key" https://api.aviationwx.org/v1/airports</code></pre>
        
        <h2>Endpoints</h2>
        
        <div class="endpoint">
            <div class="endpoint-header">
                <span class="method">GET</span>
                <span class="endpoint-path">/v1/airports</span>
            </div>
            <div class="endpoint-body">
                <p class="endpoint-desc">List all available airports with basic metadata.</p>
            </div>
        </div>
        
        <div class="endpoint">
            <div class="endpoint-header">
                <span class="method">GET</span>
                <span class="endpoint-path">/v1/airports/{id}</span>
            </div>
            <div class="endpoint-body">
                <p class="endpoint-desc">Get detailed metadata for a single airport including runways, frequencies, and services.</p>
            </div>
        </div>
        
        <div class="endpoint">
            <div class="endpoint-header">
                <span class="method">GET</span>
                <span class="endpoint-path">/v1/airports/{id}/weather</span>
            </div>
            <div class="endpoint-body">
                <p class="endpoint-desc">Get current weather conditions for an airport.</p>
            </div>
        </div>
        
        <div class="endpoint">
            <div class="endpoint-header">
                <span class="method">GET</span>
                <span class="endpoint-path">/v1/airports/{id}/weather/history</span>
            </div>
            <div class="endpoint-body">
                <p class="endpoint-desc">Get 24-hour rolling weather history. Supports <code>hours</code>, <code>resolution</code> parameters.</p>
            </div>
        </div>
        
        <div class="endpoint">
            <div class="endpoint-header">
                <span class="method">GET</span>
                <span class="endpoint-path">/v1/airports/{id}/webcams</span>
            </div>
            <div class="endpoint-body">
                <p class="endpoint-desc">List webcams for an airport with metadata.</p>
            </div>
        </div>
        
        <div class="endpoint">
            <div class="endpoint-header">
                <span class="method">GET</span>
                <span class="endpoint-path">/v1/airports/{id}/webcams/{cam}/image</span>
            </div>
            <div class="endpoint-body">
                <p class="endpoint-desc">Get the current webcam image. Supports <code>fmt</code> parameter (jpg, webp).</p>
            </div>
        </div>
        
        <div class="endpoint">
            <div class="endpoint-header">
                <span class="method">GET</span>
                <span class="endpoint-path">/v1/airports/{id}/webcams/{cam}/history</span>
            </div>
            <div class="endpoint-body">
                <p class="endpoint-desc">Get historical webcam frames. Use <code>ts</code> parameter to retrieve specific frame.</p>
            </div>
        </div>
        
        <div class="endpoint">
            <div class="endpoint-header">
                <span class="method">GET</span>
                <span class="endpoint-path">/v1/weather/bulk?airports=a,b,c</span>
            </div>
            <div class="endpoint-body">
                <p class="endpoint-desc">Get weather for multiple airports in a single request (max <?= getPublicApiBulkMaxAirports() ?> airports).</p>
            </div>
        </div>
        
        <div class="endpoint">
            <div class="endpoint-header">
                <span class="method">GET</span>
                <span class="endpoint-path">/v1/status</span>
            </div>
            <div class="endpoint-body">
                <p class="endpoint-desc">Get API health and status information.</p>
            </div>
        </div>
        
        <h2>Response Format</h2>
        <p>All endpoints return JSON responses with consistent structure:</p>
        <pre><code>{
  "success": true,
  "meta": {
    "api_version": "1",
    "request_time": "2024-12-24T10:00:00Z",
    "attribution": "<?= htmlspecialchars($attribution) ?>"
  },
  "weather": { ... }
}</code></pre>
        
        <h3>Error Responses</h3>
        <pre><code>{
  "success": false,
  "error": {
    "code": "RATE_LIMITED",
    "message": "Rate limit exceeded. Try again in 45 seconds.",
    "retry_after": 45
  }
}</code></pre>
        
        <h2>Rate Limit Headers</h2>
        <p>Every response includes rate limit information:</p>
        <table>
            <tr>
                <th>Header</th>
                <th>Description</th>
            </tr>
            <tr>
                <td><code>X-RateLimit-Limit</code></td>
                <td>Requests allowed per minute</td>
            </tr>
            <tr>
                <td><code>X-RateLimit-Remaining</code></td>
                <td>Requests remaining in current window</td>
            </tr>
            <tr>
                <td><code>X-RateLimit-Reset</code></td>
                <td>Unix timestamp when window resets</td>
            </tr>
        </table>
        
        <h2>Units</h2>
        <table>
            <tr>
                <th>Field</th>
                <th>Unit</th>
            </tr>
            <tr>
                <td>Temperature</td>
                <td>Celsius (with Fahrenheit also provided)</td>
            </tr>
            <tr>
                <td>Wind Speed</td>
                <td>Knots</td>
            </tr>
            <tr>
                <td>Pressure</td>
                <td>Inches of Mercury (inHg)</td>
            </tr>
            <tr>
                <td>Visibility</td>
                <td>Statute Miles</td>
            </tr>
            <tr>
                <td>Ceiling</td>
                <td>Feet AGL</td>
            </tr>
            <tr>
                <td>Elevation</td>
                <td>Feet MSL</td>
            </tr>
        </table>
        
        <h2>Attribution</h2>
        <div class="warning">
            <strong>Required:</strong> All applications using this API must display attribution:
            <br><br>
            <code><?= htmlspecialchars($attribution) ?></code>
        </div>
        
        <h2>Terms of Use</h2>
        <ul style="color: var(--text-secondary); margin-left: 1.5rem;">
            <li>Data is provided as-is without warranty</li>
            <li>Not for life-safety critical decisions without verification</li>
            <li>Always verify conditions through official sources before flight</li>
            <li>Abuse will result in API key revocation and/or IP blocking</li>
            <li>Rate limits may change with notice</li>
        </ul>
        
        <h2>Resources</h2>
        <ul style="color: var(--text-secondary); margin-left: 1.5rem;">
            <li><a href="/openapi.json">OpenAPI Specification (JSON)</a></li>
            <li><a href="https://aviationwx.org">AviationWX.org Website</a></li>
            <li><a href="https://github.com/alexwitherspoon/aviationwx.org">GitHub Repository</a></li>
        </ul>
        
        <?php endif; ?>
        
        <footer>
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
        </footer>
    </div>
    </main>
</body>
</html>

