<?php
/**
 * Embed Widget API
 * 
 * Returns widget HTML only (no page wrapper) for use by web components.
 * Uses the same PHP templates as iframe embeds for maximum code reuse.
 * 
 * Query Parameters:
 *   - airport: Airport ID (required)
 *   - style: Widget style (card, webcam-only, dual-only, multi-only, full-single, full-dual, full-multi)
 *   - theme: Color theme (light, dark, auto)
 *   - webcam: Webcam index for single-cam styles
 *   - cams: Comma-separated camera indices for multi-cam styles
 *   - temp: Temperature unit (F, C)
 *   - dist: Distance unit (ft, m)
 *   - wind: Wind speed unit (kt, mph, kmh)
 *   - baro: Barometer unit (inHg, hPa, mmHg)
 *   - target: Link target (_blank, _self)
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/cors.php';
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

// CORS for embed widget (allows cross-origin fetch from third-party sites)
if (handleEmbedCorsPreflight()) {
    exit;
}
sendEmbedCorsHeaders();

// Get embed parameters
$airportId = $_GET['airport'] ?? '';
$style = $_GET['style'] ?? 'card';
$theme = $_GET['theme'] ?? 'light';
$webcamIndex = isset($_GET['webcam']) ? intval($_GET['webcam']) : 0;
$target = $_GET['target'] ?? '_blank';

// Parse cams parameter for multi-cam widgets
$cams = [0, 1, 2, 3];
if (isset($_GET['cams'])) {
    $camsParsed = array_map('intval', explode(',', $_GET['cams']));
    for ($i = 0; $i < 4; $i++) {
        if (isset($camsParsed[$i])) {
            $cams[$i] = $camsParsed[$i];
        }
    }
}

// Unit preferences
$tempUnit = $_GET['temp'] ?? 'F';
$distUnit = $_GET['dist'] ?? 'ft';
$windUnit = $_GET['wind'] ?? 'kt';
$baroUnit = $_GET['baro'] ?? 'inHg';

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

// Validate airport ID
if (empty($airportId)) {
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode(['error' => 'Airport ID is required']);
    exit;
}

// Fetch data from public API (includes daily tracking: temp_high_today, temp_low_today)
require_once __DIR__ . '/../lib/embed-helpers.php';
$data = fetchEmbedDataFromApi($airportId);

// ERROR: If we cannot retrieve airport information, this is an error condition
// Missing runways are NOT an error - compass will render without runway line
if ($data === null || !isset($data['airport']) || !isset($data['airportId'])) {
    header('Content-Type: application/json');
    http_response_code(404);
    echo json_encode(['error' => 'Airport not found']);
    exit;
}

$airport = $data['airport'];
$weather = $data['weather'];
$airportId = $data['airportId'];

// Build dashboard URL
$dashboardUrl = 'https://' . $airportId . '.aviationwx.org';
if (!isProduction()) {
    $dashboardUrl = 'http://localhost:8080';
}

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

// Render the widget based on style (same as pages/embed.php)
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

require_once __DIR__ . '/../lib/http-integrity.php';
$digest = computeContentDigestFromString($widgetHtml);
$md5 = computeContentMd5FromString($widgetHtml);
$etag = '"' . base64_encode(hash('sha256', $widgetHtml, true)) . '"';
$ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
if ($ifNoneMatch !== '') {
    foreach (array_map('trim', explode(',', $ifNoneMatch)) as $candidate) {
        if ($candidate === $etag || $candidate === '*') {
            http_response_code(304);
            sendEmbedCorsHeaders();
            header('ETag: ' . $etag);
            exit;
        }
    }
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=0, must-revalidate');
header('ETag: ' . $etag);
header('Content-Digest: ' . $digest);
header('Content-MD5: ' . $md5);
echo $widgetHtml;
