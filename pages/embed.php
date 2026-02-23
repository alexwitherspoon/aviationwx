<?php
/**
 * Embed Widget Renderer
 * 
 * Renders embeddable weather widgets for airports using shared templates.
 * Supports multiple styles: card, webcam-only, dual-only, multi-only, full, full-single, full-dual, full-multi
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/seo.php';
require_once __DIR__ . '/../lib/weather/utils.php';
require_once __DIR__ . '/../lib/cache-paths.php';
require_once __DIR__ . '/../lib/webcam-metadata.php';
require_once __DIR__ . '/../lib/embed-templates/shared.php';
require_once __DIR__ . '/../lib/embed-templates/card.php';
require_once __DIR__ . '/../lib/embed-templates/webcam.php';
require_once __DIR__ . '/../lib/embed-templates/dual.php';
require_once __DIR__ . '/../lib/embed-templates/multi.php';
require_once __DIR__ . '/../lib/embed-templates/full.php';

// Get embed parameters
$embedAirportId = $_GET['embed_airport'] ?? $_GET['airport'] ?? '';
$style = $_GET['style'] ?? 'card';
$theme = $_GET['theme'] ?? 'light';
$responsive = isset($_GET['responsive']) && $_GET['responsive'] === '1';
$webcamIndex = isset($_GET['webcam']) ? intval($_GET['webcam']) : 0;
$target = $_GET['target'] ?? '_blank';

// Parse cams parameter for multi-cam widgets (comma-separated indices)
$cams = [0, 1, 2, 3]; // Default camera indices
if (isset($_GET['cams'])) {
    $camsParsed = array_map('intval', explode(',', $_GET['cams']));
    for ($i = 0; $i < 4; $i++) {
        if (isset($camsParsed[$i])) {
            $cams[$i] = $camsParsed[$i];
        }
    }
}

// Unit preferences
$tempUnit = $_GET['temp'] ?? 'F';  // F or C
$distUnit = $_GET['dist'] ?? 'ft'; // ft or m
$windUnit = $_GET['wind'] ?? 'kt'; // kt, mph, or kmh
$baroUnit = $_GET['baro'] ?? 'inHg'; // inHg, hPa, or mmHg

// Validate units
if (!in_array($tempUnit, ['F', 'C'])) $tempUnit = 'F';
if (!in_array($distUnit, ['ft', 'm'])) $distUnit = 'ft';
if (!in_array($windUnit, ['kt', 'mph', 'kmh'])) $windUnit = 'kt';
if (!in_array($baroUnit, ['inHg', 'hPa', 'mmHg'])) $baroUnit = 'inHg';

// Validate style
$validStyles = ['card', 'webcam-only', 'dual-only', 'multi-only', 'full', 'full-single', 'full-dual', 'full-multi'];
if (!in_array($style, $validStyles)) {
    $style = 'card';
}

// Validate theme
if (!in_array($theme, ['dark', 'light', 'auto'])) {
    $theme = 'auto';
}

// Validate target
if (!in_array($target, ['_blank', '_self', '_parent', '_top'])) {
    $target = '_blank';
}

// Fetch data from public API (includes daily tracking: temp_high_today, temp_low_today)
require_once __DIR__ . '/../lib/embed-helpers.php';
$data = fetchEmbedDataFromApi($embedAirportId);

// ERROR: If we cannot retrieve airport information, this is an error condition
// Missing runways are NOT an error - compass will render without runway line
if ($data === null || !isset($data['airport']) || !isset($data['airportId'])) {
    http_response_code(404);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="robots" content="noindex, nofollow">
        <title>Airport Not Found</title>
        <link rel="stylesheet" href="/public/css/embed-widgets.css">
    </head>
    <body>
        <div class="no-data">
            <div class="icon">✈️</div>
            <p>Airport not found</p>
            <p style="font-size: 0.9rem; color: var(--muted-color);">Please check the airport ID and try again.</p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$airport = $data['airport'];
$weather = $data['weather'];
$airportId = $data['airportId'];

// Embed widgets should never be indexed - they're meant to be embedded in iframes
$shouldNoIndex = true;

// Build dashboard URL
$dashboardUrl = 'https://' . $airportId . '.aviationwx.org';
if (!isProduction()) {
    $dashboardUrl = 'http://localhost:8080';
}

// Prepare data for template (already fetched from API)
$data = [
    'airport' => $airport,
    'weather' => $weather,
    'airportId' => $airportId
];

// Prepare options for template
$options = [
    'dashboardUrl' => $dashboardUrl,
    'target' => $target,
    'primaryIdentifier' => strtoupper($airportId),
    'tempUnit' => $tempUnit,
    'distUnit' => $distUnit,
    'windUnit' => $windUnit,
    'baroUnit' => $baroUnit,
    'theme' => $theme,
    'webcamIndex' => $webcamIndex,
    'cams' => $cams
];

// Determine theme class
// For auto mode, we'll let JavaScript handle it, but set initial class
$themeClass = getThemeClass($theme);

// For auto mode, try to detect system preference from Accept-Language or other headers
// This is a best-effort approach - JavaScript will handle the actual detection
if ($theme === 'auto') {
    // Check if we can detect dark mode preference from headers (some browsers send this)
    // If not available, default to 'theme-auto' and let JavaScript handle it
    $themeClass = 'theme-auto';
}

// Render the widget based on style
$widgetHtml = '';
switch ($style) {
    case 'card':
        $widgetHtml = renderCardWidget($data, $options);
        break;
    case 'webcam-only':
        $widgetHtml = renderWebcamOnlyWidget($data, $options);
        break;
    case 'dual-only':
        $widgetHtml = renderDualOnlyWidget($data, $options);
        break;
    case 'multi-only':
        $widgetHtml = renderMultiOnlyWidget($data, $options);
        break;
    case 'full':
    case 'full-single':
        $widgetHtml = renderFullSingleWidget($data, $options);
        break;
    case 'full-dual':
        $widgetHtml = renderFullDualWidget($data, $options);
        break;
    case 'full-multi':
        $widgetHtml = renderFullMultiWidget($data, $options);
        break;
    default:
        $widgetHtml = '<div class="no-data"><p>Style not supported</p></div>';
}

// Output HTML
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars(strtoupper($airportId)) ?> Weather Widget</title>
    <link rel="stylesheet" href="/public/css/embed-widgets.css">
    <script src="/public/js/embed-wind-compass.js"></script>
    <style>
        /* Ensure full viewport usage for iframe */
        html, body {
            margin: 0;
            padding: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
        }
        /* Responsive: allow content to grow vertically so 16:9 images aren't cut off */
        body.embed-responsive {
            height: auto;
            min-height: 100%;
            overflow: visible;
        }
        body.embed-responsive .embed-container {
            height: auto;
            min-height: 100%;
        }
    </style>
    <?php if ($theme === 'auto'): ?>
    <script>
        // Auto theme: Update theme class IMMEDIATELY to prevent flash
        // This runs in <head> and will update classes as soon as body exists
        (function() {
            'use strict';
            
            function updateThemeClass() {
                const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
                const newThemeClass = prefersDark ? 'theme-dark' : 'theme-light';
                
                // Update body
                if (document.body) {
                    document.body.classList.remove('theme-light', 'theme-dark', 'theme-auto');
                    document.body.classList.add(newThemeClass);
                }
                
                // Update container
                const container = document.querySelector('.embed-container');
                if (container) {
                    container.classList.remove('theme-light', 'theme-dark', 'theme-auto');
                    container.classList.add(newThemeClass);
                }
                
                // Update all elements with theme-auto class
                const autoElements = document.querySelectorAll('.theme-auto');
                autoElements.forEach(function(el) {
                    el.classList.remove('theme-light', 'theme-dark', 'theme-auto');
                    el.classList.add(newThemeClass);
                });
                
                // Trigger compass redraw if AviationWX is available
                if (window.AviationWX && window.AviationWX.drawWindCompass) {
                    const canvases = document.querySelectorAll('canvas[id*="wind-canvas"], canvas[id*="compass"]');
                    canvases.forEach(function(canvas) {
                        if (canvas && canvas.dataset && canvas.dataset.windSpeed !== undefined) {
                            try {
                                window.AviationWX.drawWindCompass(canvas, {
                                    windSpeed: parseFloat(canvas.dataset.windSpeed) || 0,
                                    windDirection: parseFloat(canvas.dataset.windDirection) || 0,
                                    isVRB: canvas.dataset.isVRB === 'true',
                                    runways: canvas.dataset.runways ? JSON.parse(canvas.dataset.runways) : [],
                                    isDark: prefersDark,
                                    size: canvas.dataset.size || 'medium'
                                });
                            } catch (e) {
                                // Ignore errors
                            }
                        }
                    });
                }
                
                // Dispatch custom event for compass scripts
                const event = new CustomEvent('themechange', {
                    detail: { isDark: prefersDark, theme: newThemeClass }
                });
                document.dispatchEvent(event);
            }
            
            // Try to run immediately, then also on DOMContentLoaded
            function runUpdate() {
                updateThemeClass();
            }
            
            // Run as soon as possible
            if (document.readyState === 'loading') {
                // DOM is still loading - wait for it, then run
                document.addEventListener('DOMContentLoaded', runUpdate);
                // Also try immediately in case body exists
                runUpdate();
            } else {
                // DOM already loaded - run immediately
                runUpdate();
            }
            
            // Listen for system preference changes
            if (window.matchMedia) {
                const darkModeQuery = window.matchMedia('(prefers-color-scheme: dark)');
                if (darkModeQuery.addEventListener) {
                    darkModeQuery.addEventListener('change', updateThemeClass);
                } else if (darkModeQuery.addListener) {
                    darkModeQuery.addListener(updateThemeClass);
                }
            }
        })();
    </script>
    <?php endif; ?>
    <script>
        (function() {
            'use strict';
            function lockWebcamAspectRatio(img) {
                if (!img || !img.naturalWidth || !img.naturalHeight) return;
                var actual = img.naturalWidth / img.naturalHeight;
                var current = parseFloat(img.style.aspectRatio) || parseFloat(window.getComputedStyle(img).aspectRatio);
                if (Math.abs(actual - current) > 0.05) {
                    img.style.aspectRatio = actual.toString();
                }
            }
            function initWebcamAspectRatios() {
                document.querySelectorAll('.webcam-image').forEach(function(img) {
                    if (img.complete && img.naturalWidth) {
                        lockWebcamAspectRatio(img);
                    } else {
                        img.addEventListener('load', function() { lockWebcamAspectRatio(this); });
                    }
                });
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initWebcamAspectRatios);
            } else {
                initWebcamAspectRatios();
            }
        })();
    </script>
    <?php if ($responsive): ?>
    <script>
        (function() {
            'use strict';
            function reportHeight() {
                if (window.parent !== window) {
                    var h = Math.ceil(document.body.scrollHeight);
                    window.parent.postMessage({ type: 'aviationwx-resize', height: h }, '*');
                }
            }
            function initResizeReporting() {
                reportHeight();
                document.querySelectorAll('.webcam-image').forEach(function(img) {
                    img.addEventListener('load', reportHeight);
                });
                if (window.ResizeObserver) {
                    var obs = new ResizeObserver(function() { reportHeight(); });
                    obs.observe(document.body);
                }
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initResizeReporting);
            } else {
                initResizeReporting();
            }
        })();
    </script>
    <?php endif; ?>
</head>
<body class="<?= htmlspecialchars($themeClass) ?><?= $responsive ? ' embed-responsive' : '' ?>">
    <div class="embed-container <?= htmlspecialchars($themeClass) ?>">
        <?= $widgetHtml ?>
    </div>
</body>
</html>
