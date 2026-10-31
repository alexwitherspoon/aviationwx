<?php
/**
 * Public API Documentation Page
 *
 * Landing page for api.aviationwx.org. When the Public API is enabled, displays `getCanonicalPublicApiV1BaseUrl()`.
 *
 * Endpoint parameter tables and examples in this page mirror api/docs/openapi.json. Keep the two in sync, with the spec as the source of truth.
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
$pageTitle = 'AviationWX.org Public API - Real-time Aviation Weather Data';
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
        
        .canonical-base {
            background: var(--bg-secondary);
            border: 1px solid var(--accent-blue);
            border-radius: 8px;
            padding: 1.25rem 1.5rem;
            margin: 1.5rem 0;
        }
        
        .canonical-base h3 {
            margin-top: 0;
            color: var(--accent-blue);
            font-size: 1.1rem;
        }
        
        .canonical-base .canonical-url code {
            font-size: 1rem;
            word-break: break-all;
        }
        
        .canonical-base .canonical-note {
            margin-bottom: 0;
            font-size: 0.9rem;
            color: var(--text-muted);
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
            overflow-wrap: anywhere;
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
            align-items: flex-end;
            flex-wrap: wrap;
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
        
        .github-link {
            text-decoration: none;
            color: inherit;
            opacity: 0.85;
        }
        
        .github-link .stat-value {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 2.5rem;
            margin-bottom: 0.2rem;
        }
        
        @media (max-width: 720px) {
            .container {
                padding: 1rem;
            }
            .stats {
                gap: 1.25rem;
            }
            .endpoint-body {
                overflow-x: auto;
            }
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
    <div class="container">
        <header>
            <h1>AviationWX.org Public API</h1>
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
                <div class="stat">
                    <div class="stat-value"><?php $docSha = getGitSha(); echo $docSha ? htmlspecialchars(substr($docSha, 0, 7)) : 'n/a'; ?></div>
                    <div class="stat-label">Build</div>
                </div>
                <a class="stat github-link" href="https://github.com/alexwitherspoon/aviationwx" target="_blank" rel="noopener" aria-label="AviationWX source on GitHub" title="Source on GitHub">
                    <div class="stat-value">
                        <svg viewBox="0 0 16 16" width="28" height="28" fill="currentColor" role="img" aria-hidden="true"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27s1.36.09 2 .27c1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.01 8.01 0 0 0 16 8c0-4.42-3.58-8-8-8z"/></svg>
                    </div>
                    <div class="stat-label">Code Repo</div>
                </a>
            </div>
        </header>
        
        <?php if (!$apiEnabled): ?>
        <div class="api-disabled">
            <h2>API Not Enabled</h2>
            <p>The public API is not currently enabled. Please check back later.</p>
        </div>
        <?php else: ?>
        
        <?php
        $canonicalV1 = getCanonicalPublicApiV1BaseUrl();
        ?>
        <div class="canonical-base" role="region" aria-label="Canonical API base URL">
            <h3>Canonical base URL (v1)</h3>
            <p>Use this HTTP(S) base URL and <code>/v1</code> prefix for Public API v1 requests to this deployment.</p>
            <p class="canonical-url"><code><?= htmlspecialchars($canonicalV1, ENT_QUOTES, 'UTF-8') ?></code></p>
            <p class="canonical-note">Example: <code><?= htmlspecialchars($canonicalV1, ENT_QUOTES, 'UTF-8') ?>/airports/kspb/weather</code>. Override the documented base with <code>config.public_api.canonical_base_url</code> in <code>airports.json</code> when your deployment’s public origin differs.</p>
        </div>
        
        <div class="quick-start">
            <h3>🚀 Quick Start</h3>
            <p>Get current weather for an airport:</p>
            <pre><code>curl https://api.aviationwx.org/v1/airports/kspb/weather</code></pre>
            <p>List AviationWX-operated weathercams (absolute image URLs):</p>
            <pre><code>curl https://api.aviationwx.org/v1/weathercam/bulk?operator=aviationwx</code></pre>
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
        <p>Anonymous requests count against a shared IP bucket. To reach the partner tier, send <code>X-API-Key</code> on every request, image requests included. An invalid key returns 401 and does not fall back to anonymous.</p>
        <p>Request a partner key through the <a href="https://aviationwx.org#contact">contact</a> link.</p>
        
        <h2>Endpoints</h2>
        <p class="endpoint-desc">Replace <code>{id}</code> and <code>{cam}</code> with the airport identifier and webcam index (for example <code>kspb</code> and <code>0</code>). The <code>operator</code> parameter filters by a network slug; on airport endpoints it also covers weather-source type defaults (for example <code>metar</code> is <code>faa</code> and <code>nws</code> is <code>nws</code>).</p>
        
        <div class="endpoint">
            <div class="endpoint-header">
                <span class="method">GET</span>
                <span class="endpoint-path">/v1/airports</span>
            </div>
            <div class="endpoint-body">
                <p class="endpoint-desc">Browse the full airport catalog and filter it by operator, maintenance status, or whether a field has webcams or weather.</p>
                <table>
                    <tr><th>Parameter</th><th>Type</th><th>Description</th></tr>
                    <tr><td><code>operator</code></td><td>string</td><td>Keep airports with a matching weathercam or weather-source operator. Lowercased before matching; unknown slugs return 400.</td></tr>
                    <tr><td><code>has_webcams</code></td><td>string</td><td><code>true</code> or <code>1</code> keeps airports with weathercams; <code>false</code> or <code>0</code> keeps airports without them. Not sliced by operator.</td></tr>
                    <tr><td><code>has_weather</code></td><td>string</td><td><code>true</code> or <code>1</code> keeps airports with weather sources; <code>false</code> or <code>0</code> keeps airports without them. Not sliced by operator.</td></tr>
                    <tr><td><code>maintenance</code></td><td>string</td><td><code>false</code> returns operational airports only; <code>true</code> includes maintenance sites.</td></tr>
                    <tr><td><code>limited_availability</code></td><td>string</td><td><code>true</code> keeps off-grid, solar, or battery sites; <code>false</code> keeps fully available airports.</td></tr>
                </table>
            </div>
        </div>
        
        <div class="endpoint">
            <div class="endpoint-header">
                <span class="method">GET</span>
                <span class="endpoint-path">/v1/airports/{id}</span>
            </div>
            <div class="endpoint-body">
                <p class="endpoint-desc">Get everything about one airport, runways and frequencies included, for a detail page or a preflight look-up.</p>
            </div>
        </div>
        
        <div class="endpoint">
            <div class="endpoint-header">
                <span class="method">GET</span>
                <span class="endpoint-path">/v1/airports/{id}/weather</span>
            </div>
            <div class="endpoint-body">
                <p class="endpoint-desc">Get the single current weather picture for an airport, fused across sources, for display or flight planning. Every response <code>meta</code> carries <code>observation_time</code> and attribution. Compare that timestamp to the current time and do not present an observation as current once it is stale.</p>
                <pre><code>curl https://api.aviationwx.org/v1/airports/kspb/weather</code></pre>
                <pre><code>{
  "success": true,
  "meta": {
    "api_version": "1",
    "request_time": "2026-10-30T12:05:00Z",
    "attribution": "<?= htmlspecialchars($attribution) ?>",
    "airport_id": "kspb",
    "airport_name": "Scappoose Industrial Airpark",
    "observation_time": "2026-10-30T12:04:00Z",
    "units": { ... },
    "sunrise_sunset_timezone": "America/Los_Angeles"
  },
  "weather": {
    "flight_category": "VFR",
    "temperature": 12.5,
    "dewpoint": 7.0,
    "humidity": 68,
    "wind_speed": 8,
    "wind_direction": 320,
    "visibility": 10,
    "ceiling": 4500,
    "observation_time": "2026-10-30T12:04:00Z",
    ...
  }
}</code></pre>
            </div>
        </div>
        
        <div class="endpoint">
            <div class="endpoint-header">
                <span class="method">GET</span>
                <span class="endpoint-path">/v1/airports/{id}/embed</span>
            </div>
            <div class="endpoint-body">
                <p class="endpoint-desc">Get everything an embed widget needs in one payload, or a differential update for gentle polling.</p>
                <table>
                    <tr><th>Parameter</th><th>Type</th><th>Description</th></tr>
                    <tr><td><code>refresh</code></td><td>string</td><td><code>1</code> returns only the topics that changed. Omit or <code>0</code> for the full payload.</td></tr>
                </table>
            </div>
        </div>
        
        <div class="endpoint">
            <div class="endpoint-header">
                <span class="method">GET</span>
                <span class="endpoint-path">/v1/airports/{id}/weather/history</span>
            </div>
            <div class="endpoint-body">
                <p class="endpoint-desc">See how conditions trended over the past 24 hours, with density and pressure altitude on every observation.</p>
                <table>
                    <tr><th>Parameter</th><th>Type</th><th>Description</th></tr>
                    <tr><td><code>hours</code></td><td>integer</td><td>Hours of history to return, 1 through 24. Default <code>24</code>.</td></tr>
                    <tr><td><code>resolution</code></td><td>string</td><td><code>all</code>, <code>hourly</code>, or <code>15min</code>. Default <code>all</code>.</td></tr>
                </table>
            </div>
        </div>
        
        <div class="endpoint">
            <div class="endpoint-header">
                <span class="method">GET</span>
                <span class="endpoint-path">/v1/airports/{id}/webcams</span>
            </div>
            <div class="endpoint-body">
                <p class="endpoint-desc">See which cameras a field has and who runs them, so you can pick the image you want. Each webcam carries <code>captured_at</code> and <code>captured_at_iso</code>, so image age is available without an extra request, and each image also embeds its capture time in EXIF metadata.</p>
                <table>
                    <tr><th>Parameter</th><th>Type</th><th>Description</th></tr>
                    <tr><td><code>operator</code></td><td>string</td><td>Return only weathercams with this operator slug. Omitted returns every camera.</td></tr>
                </table>
                <pre><code>{
  "index": 0,
  "name": "Mulino 4S9 - NW Facing",
  "image_url": "/v1/airports/4s9/webcams/0/image",
  "refresh_seconds": 60,
  "captured_at": 1793361840,
  "captured_at_iso": "2026-10-30T12:04:00Z",
  "approximate_heading": 315,
  "operator": "aviationwx",
  "images": [
    {
      "variant": "original",
      "height": null,
      "format": "jpg",
      "url": "/v1/airports/4s9/webcams/0/image"
    },
    {
      "variant": "720",
      "height": 720,
      "format": "jpg",
      "url": "/v1/airports/4s9/webcams/0/image?size=720"
    }
  ]
}</code></pre>
            </div>
        </div>
        
        <div class="endpoint">
            <div class="endpoint-header">
                <span class="method">GET</span>
                <span class="endpoint-path">/v1/airports/{id}/webcams/{cam}/image</span>
            </div>
            <div class="endpoint-body">
                <p class="endpoint-desc">Fetch a current webcam frame in its native form, a sized variant, or an FAA-compliant rendering for aviation use.</p>
                <table>
                    <tr><th>Parameter</th><th>Type</th><th>Description</th></tr>
                    <tr><td><code>profile</code></td><td>string</td><td><code>faa</code> returns a FAA WCPO-compliant image: crop margins to exclude timestamps, 4:3 aspect, quality-capped at 1280x960 or 640x480, always JPG. Other parameters are ignored when set.</td></tr>
                    <tr><td><code>fmt</code></td><td>string</td><td><code>jpg</code> or <code>webp</code> for generated size variants and dimension transforms. Ignored when profile is set. Omit for the native original.</td></tr>
                    <tr><td><code>size</code></td><td>string</td><td>Pre-generated height variant such as <code>1080</code> or <code>720</code>. Omit for the native original. A size never generated for this camera returns 400 listing the available sizes. Not combined with width or height.</td></tr>
                    <tr><td><code>width</code></td><td>integer</td><td>Target width in pixels, 16 through 3840. With height, center-crops to the aspect ratio then scales. Ignored when profile is set.</td></tr>
                    <tr><td><code>height</code></td><td>integer</td><td>Target height in pixels, 16 through 2160. With width, center-crops to the aspect ratio then scales. Ignored when profile is set.</td></tr>
                    <tr><td><code>metadata</code></td><td>string</td><td><code>1</code> returns JSON describing the available formats and variants instead of the image.</td></tr>
                    <tr><td><code>download</code></td><td>string</td><td><code>1</code> serves the native original as a download with an attachment filename instead of inline.</td></tr>
                </table>
                <pre><code>curl "https://api.aviationwx.org/v1/airports/4s9/webcams/1/image?profile=faa"</code></pre>
            </div>
        </div>
        
        <div class="endpoint">
            <div class="endpoint-header">
                <span class="method">GET</span>
                <span class="endpoint-path">/v1/airports/{id}/webcams/{cam}/history</span>
            </div>
            <div class="endpoint-body">
                <p class="endpoint-desc">Pull past frames to show how a field looked earlier.</p>
                <table>
                    <tr><th>Parameter</th><th>Type</th><th>Description</th></tr>
                    <tr><td><code>ts</code></td><td>integer</td><td>Timestamp of the frame to retrieve. Returns that specific image.</td></tr>
                    <tr><td><code>fmt</code></td><td>string</td><td><code>jpg</code> or <code>webp</code> for generated size variants. Only used with <code>ts</code>. Omit for the native original.</td></tr>
                    <tr><td><code>size</code></td><td>string</td><td>Pre-generated height variant. Only used with <code>ts</code> and never falls back to the original. Omit for the native original.</td></tr>
                </table>
                <pre><code>curl "https://api.aviationwx.org/v1/airports/kspb/webcams/0/history?ts=1704067900&amp;fmt=jpg&amp;size=720"</code></pre>
            </div>
        </div>
        
        <div class="endpoint">
            <div class="endpoint-header">
                <span class="method">GET</span>
                <span class="endpoint-path">/v1/weather/bulk</span>
            </div>
            <div class="endpoint-body">
                <p class="endpoint-desc">Current weather for several airports, or every listed airport when <code>airports</code> is omitted, in one round trip.</p>
                <table>
                    <tr><th>Parameter</th><th>Type</th><th>Description</th></tr>
                    <tr><td><code>airports</code></td><td>string</td><td>Optional. Comma-separated airport identifiers, up to <?= getPublicApiBulkMaxAirports() ?> when present. Omit for all listed airports.</td></tr>
                    <tr><td><code>operator</code></td><td>string</td><td>Keep airports whose weather sources match this operator. <code>aviationwx</code> keeps non-FAA/NWS/institutional sources.</td></tr>
                    <tr><td><code>maintenance</code></td><td>string</td><td><code>true</code> includes maintenance airports; <code>false</code> or omitted (default) excludes them.</td></tr>
                </table>
            </div>
        </div>
        
        <div class="endpoint">
            <div class="endpoint-header">
                <span class="method">GET</span>
                <span class="endpoint-path">/v1/weathercam/bulk</span>
            </div>
            <div class="endpoint-body">
                <p class="endpoint-desc">One call for every weathercam on the platform, with absolute image URLs, for catalogs and directories.</p>
                <table>
                    <tr><th>Parameter</th><th>Type</th><th>Description</th></tr>
                    <tr><td><code>operator</code></td><td>string</td><td>Match weathercams only, not weather sources. Omitted defaults to <code>aviationwx</code>.</td></tr>
                    <tr><td><code>maintenance</code></td><td>string</td><td><code>true</code> includes airports in maintenance mode; <code>false</code> or omitted excludes them.</td></tr>
                </table>
            </div>
        </div>

        <div class="endpoint">
            <div class="endpoint-header">
                <span class="method">GET</span>
                <span class="endpoint-path">/v1/status</span>
            </div>
            <div class="endpoint-body">
                <p class="endpoint-desc">Confirm the API is up and healthy, for monitoring or a service dashboard.</p>
            </div>
        </div>
        
        <div class="endpoint">
            <div class="endpoint-header">
                <span class="method">GET</span>
                <span class="endpoint-path">/v1/operations</span>
            </div>
            <div class="endpoint-body">
                <p class="endpoint-desc">See platform health and scrubbed log summaries at a glance, for operators.</p>
            </div>
        </div>
        
        <h2>Response Format</h2>
        <p>All endpoints return JSON responses with consistent structure:</p>
        <pre><code>{
  "success": true,
  "meta": {
    "api_version": "1",
    "request_time": "2024-12-24T10:00:00Z",
    "attribution": "<?= htmlspecialchars($attribution) ?>",
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
        <p>All errors use this shape. Recognized <code>code</code> values:</p>
        <table>
            <tr><th>Code</th><th>Meaning</th></tr>
            <tr><td><code>INVALID_REQUEST</code></td><td>Malformed request, unknown endpoint, or invalid parameter.</td></tr>
            <tr><td><code>INVALID_API_KEY</code></td><td>The API key is invalid or disabled.</td></tr>
            <tr><td><code>AIRPORT_NOT_FOUND</code></td><td>The airport identifier does not exist.</td></tr>
            <tr><td><code>WEBCAM_NOT_FOUND</code></td><td>The webcam index does not exist for this airport.</td></tr>
            <tr><td><code>RATE_LIMITED</code></td><td>Rate limit exceeded. <code>retry_after</code> says when to try again.</td></tr>
            <tr><td><code>API_NOT_ENABLED</code></td><td>The API is not enabled for this deployment.</td></tr>
            <tr><td><code>SERVICE_UNAVAILABLE</code></td><td>Temporary outage or no data available right now.</td></tr>
        </table>
        
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
            <li><a href="https://aviationwx.org/llms.txt">llms.txt (guidance for AI agents)</a></li>
            <li><a href="https://aviationwx.org">AviationWX.org Website</a></li>
            <li><a href="https://github.com/alexwitherspoon/aviationwx">GitHub Repository</a></li>
        </ul>
        
        <?php endif; ?>
        
        <footer>
            <p>
                &copy; <?= date('Y') ?> <a href="https://aviationwx.org">AviationWX.org</a> • 
                <a href="https://airports.aviationwx.org">Airports</a> • 
                <a href="https://guides.aviationwx.org">Guides</a> • 
                <a href="https://aviationwx.org#about-the-project">Built for pilots, by pilots</a> • 
                <a href="https://github.com/alexwitherspoon/aviationwx" target="_blank" rel="noopener">Open Source<?php $gitSha = getGitSha(); echo $gitSha ? ' - ' . htmlspecialchars($gitSha) : ''; ?></a> • 
                <a href="https://terms.aviationwx.org">Terms of Service</a> • 
                <a href="https://api.aviationwx.org">API</a> • 
                <a href="https://status.aviationwx.org">Status</a>
            </p>
        </footer>
    </div>
</body>
</html>

