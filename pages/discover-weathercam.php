<?php
/**
 * Discover weathercam tour: map-first context, then live webcams.
 *
 * A landscape kiosk view. For each airport in the rotation it shows the map,
 * flies to the airport, dwells briefly so the viewer reads location and the
 * surrounding radar, then loads the airport's webcams fullscreen. Each camera
 * plays one full pass over its last hour of frames before the next camera, and
 * the whole airport yields to the next after its cams are done, rotating through
 * the operational network without repeats until the deck is exhausted.
 *
 * The page is self-contained: all the animation, the Leaflet map, the history
 * player, and the overlays live here, and the data comes from the existing
 * internal endpoints (pool/health for the deck, /api/weather.php for the
 * overlays, /api/webcam-history.php for frames). Dark mode is preferred to
 * spare the LCD.
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/discover.php';
require_once __DIR__ . '/../lib/runways.php';

// Deck + scope assembly mirrors the tour and the menu. Health stays with the
// cached operational deck (30s) so a per-page load does not rescan every
// airport; the client re-checks a single airport before each reveal.
$discoverWeathercamConfig = loadConfig();
if ($discoverWeathercamConfig === null) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Weathercam loop unavailable: configuration could not be loaded.';
    exit;
}
$discoverWeathercamOperational = discoverGetCachedOperationalDeck($discoverWeathercamConfig);

// The weathercam tour needs an airport's webcams and their history. Enrich the
// operational deck with webcam presence and region metadata so the client can
// apply scope and skip airports without cameras, without a second pass.
$discoverWeathercamDeck = [];
$listedAirports = getListedAirports($discoverWeathercamConfig);
$listedById = [];
foreach ($listedAirports as $aid => $ap) {
    $listedById[$aid] = $ap;
}

// Build one deck entry for a listed airport. Returns null when the airport is
// not listed or has no webcams, so such airports never surface. The entry is
// the same regardless of whether the airport is operational, degraded, or
// down; the deck-scope pass decides which of those are included.
$discoverWeathercamBuildEntry = function (string $id, array $entry) use ($listedById): ?array {
    $id = strtolower($id);
    $ap = $listedById[$id] ?? null;
    if ($ap === null) {
        return null;  // unlisted: never surfaces
    }
    $webcams = isset($ap['webcams']) && is_array($ap['webcams']) ? $ap['webcams'] : [];
    if (count($webcams) === 0) {
        return null;  // no webcam: not a weathercam-loop airport
    }
    $country = getEffectiveIso3166Alpha2ForAirport($ap);
    $region = getEffectiveIso3166RegionForAirport($id, $ap);
    $segments = getRunwaySegmentsForAirport($id, $ap);
    $segments = is_array($segments) ? $segments : [];
    return [
        'id' => $id,
        'primary' => $entry['primary'],
        'name' => $entry['name'],
        'lat' => is_numeric($ap['lat'] ?? null) ? (float) $ap['lat'] : null,
        'lon' => is_numeric($ap['lon'] ?? null) ? (float) $ap['lon'] : null,
        'region' => $region,
        'region_name' => $region !== null ? subdivisionNameFromIso3166($region) : null,
        'country' => $country,
        'country_name' => $country !== null ? countryNameFromIso3166Alpha2($country) : null,
        'webcam_count' => count($webcams),
        // Normalized runway segments, already rotated to magnetic north by
        // getRunwaySegmentsForAirport, so the compass matches the dashboard.
        'runway_segments' => $segments,
        'magnetic_declination' => getMagneticDeclination($ap),
        // Staleness thresholds so the compass draws the wind only while fresh.
        'stale_failclosed_seconds' => getStaleFailclosedSeconds($ap),
        'metar_stale_failclosed_seconds' => getMetarStaleFailclosedSeconds(),
    ];
};

foreach ($discoverWeathercamOperational as $entry) {
    $built = $discoverWeathercamBuildEntry($entry['id'], $entry);
    if ($built !== null) {
        $discoverWeathercamDeck[] = $built;
    }
}

// Requested scope. all = the deck; a region id (US-OR) filters by region; an
// airport id pins one airport.
$discoverWeathercamScope = isset($_GET['scope']) ? strtolower(trim((string) $_GET['scope'])) : 'all';
if ($discoverWeathercamScope !== '' && $discoverWeathercamScope !== 'all') {
    if (isset($listedById[$discoverWeathercamScope])) {
        // Specific airport. The user asked for it by name, so show it even if
        // it is degraded or down, as long as it is listed and has a webcam.
        $discoverWeathercamDeck = array_values(array_filter(
            $discoverWeathercamDeck,
            function ($entry) use ($discoverWeathercamScope) {
                return strtolower($entry['id']) === $discoverWeathercamScope;
            }
        ));
        if ($discoverWeathercamDeck === []) {
            // Not in the operational/degraded deck (e.g. down). Build it from
            // the listed config directly so the chosen airport still shows.
            $entry = ['id' => $discoverWeathercamScope, 'primary' => '', 'name' => ''];
            $listedAp = $listedById[$discoverWeathercamScope];
            $entry['primary'] = getPrimaryIdentifier($discoverWeathercamScope, $listedAp);
            $entry['name'] = is_string($listedAp['name'] ?? null) ? $listedAp['name'] : (string) $discoverWeathercamScope;
            $built = $discoverWeathercamBuildEntry($discoverWeathercamScope, $entry);
            if ($built !== null) {
                $discoverWeathercamDeck[] = $built;
            }
        }
    } else {
        $regionLower = $discoverWeathercamScope;
        $discoverWeathercamDeck = array_values(array_filter(
            $discoverWeathercamDeck,
            function ($entry) use ($regionLower) {
                return strtolower((string) $entry['region']) === $regionLower;
            }
        ));
    }
}

// True when the scope pinned a specific airport by id. A pinned airport shows
// even when degraded or down; the show-time health gate is skipped for it.
$discoverWeathercamSpecific = $discoverWeathercamScope !== ''
    && $discoverWeathercamScope !== 'all'
    && isset($listedById[$discoverWeathercamScope]);

// Dwell (minutes per airport, soft ceiling) - default 5, clamped to 1-60.
$discoverWeathercamDuration = 5;
if (isset($_GET['tourDuration']) && preg_match('/^[1-9][0-9]*$/', (string) $_GET['tourDuration'])) {
    $v = (int) $_GET['tourDuration'];
    if ($v >= 1 && $v <= 60) {
        $discoverWeathercamDuration = $v;
    }
}

// Display units, following the embed generator's param names, so a display
// can choose units without touching code. Values are validated, not echoed
// back raw, so a bogus param just falls back to the default unit.
$discoverWeathercamUnits = [
    'temp' => 'F',
    'dist' => 'ft',
    'wind' => 'kt',
    'baro' => 'inHg',
];
foreach (['temp', 'dist', 'wind', 'baro'] as $unitKey) {
    if (!isset($_GET[$unitKey])) {
        continue;
    }
    $raw = strtolower(trim((string) $_GET[$unitKey]));
    if ($unitKey === 'temp' && ($raw === 'f' || $raw === 'c')) {
        $discoverWeathercamUnits[$unitKey] = strtoupper($raw);
    } elseif ($unitKey === 'dist' && ($raw === 'ft' || $raw === 'm')) {
        $discoverWeathercamUnits[$unitKey] = $raw;
    } elseif ($unitKey === 'wind' && in_array($raw, ['kt', 'mph', 'kmh'], true)) {
        $discoverWeathercamUnits[$unitKey] = $raw;
    } elseif ($unitKey === 'baro' && in_array($raw, ['inhg', 'hpa', 'mmhg'], true)) {
        $discoverWeathercamUnits[$unitKey] = ($raw === 'inhg') ? 'inHg' : $raw;
    }
}
$discoverWeathercamUnitsJson = json_encode($discoverWeathercamUnits, JSON_INVALID_UTF8_SUBSTITUTE);
if ($discoverWeathercamUnitsJson === false) {
    $discoverWeathercamUnitsJson = '{}';
}

$discoverWeathercamDeckJson = json_encode($discoverWeathercamDeck, JSON_INVALID_UTF8_SUBSTITUTE);
if ($discoverWeathercamDeckJson === false) {
    $discoverWeathercamDeckJson = '[]';
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <!-- Configured page (mode + scope); keep out of the search index. -->
    <meta name="robots" content="noindex, nofollow">
    <title>Weathercam Tour - AviationWX.org</title>
    <link rel="stylesheet" href="/public/css/leaflet.css">
    <style>
        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            padding: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            background: #0b0f14;
            color: #e6edf3;
            font-family: system-ui, -apple-system, sans-serif;
            cursor: none;
            -webkit-user-select: none;
            user-select: none;
        }
        .stage { position: fixed; inset: 0; }
        .layer {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            opacity: 1;
            transition: opacity 1.2s ease;
        }
        .layer--hidden {
            opacity: 0;
            visibility: hidden;
            transition: opacity 1.2s ease, visibility 0s linear 1.2s;
        }
        #map-layer { z-index: 1; }
        #map { width: 100%; height: 100%; background: #0b0f14; }
        #cam-layer { z-index: 2; }
        #cam-stage {
            position: absolute;
            inset: 0;
            background: #0b0f14;
            overflow: hidden;
        }
        #cam-img {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            max-width: 94vw;
            max-height: 88vh;
            object-fit: contain;
            opacity: 0;
            transition: opacity 0.5s ease;
        }
        .cam-img--show { opacity: 1; }
        .overlay {
            position: fixed;
            z-index: 10;
            background: rgba(11, 15, 20, 0.88);
            border: 1px solid #2a3441;
            border-radius: 10px;
            padding: 1rem 1.25rem;
            font-size: 0.9rem;
            line-height: 1.5;
            pointer-events: none;
        }
        .wind-overlay { left: 1rem; bottom: 1rem; }
        .wind-overlay canvas {
            display: block;
            margin: 0 auto;
            width: 210px;
            height: 210px;
        }
        .wind-overlay .wind-readout {
            text-align: center;
            margin-top: 0.4rem;
            color: #e6edf3;
            font-weight: 600;
        }
        .wind-details {
            margin-top: 0.6rem;
            min-width: 15rem;
        }
        .wind-details .metric-row {
            padding: 0.22rem 0;
        }
        .conditions-overlay {
            right: 1rem;
            bottom: 1rem;
            min-width: 15rem;
        }
        .conditions-overlay h3 {
            margin: 0 0 0.6rem;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #9aa7b4;
        }
        .metric-row {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            padding: 0.28rem 0;
        }
        .metric-row .label { color: #9aa7b4; }
        .metric-row .value { color: #e6edf3; font-weight: 600; }
        .metric-row .status-vfr { color: #35c46f; }
        .metric-row .status-mvfr { color: #4c9aff; }
        .metric-row .status-ifr { color: #f0835a; }
        .metric-row .status-lifr { color: #e25ad0; }
        .brand {
            position: fixed;
            top: 14px;
            left: 14px;
            z-index: 20;
            background: #ffffff;
            border-radius: 8px;
            padding: 8px 12px;
            line-height: 0;
            pointer-events: none;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.4);
        }
        .brand img { height: 26px; display: block; }
        .page-title {
            position: fixed;
            top: 14px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 20;
            background: rgba(11, 15, 20, 0.88);
            border: 1px solid #2a3441;
            border-radius: 8px;
            padding: 7px 16px;
            font-size: 1.05rem;
            font-weight: 600;
            color: #e6edf3;
            pointer-events: none;
            max-width: 70vw;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .label { color: #9aa7b4; }
        .value { color: #e6edf3; }
        #cam-label {
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 11;
            background: #000;
            color: #fff;
            padding: 0.45rem 0.7rem;
            font-size: 0.8rem;
            font-weight: 500;
            line-height: 1.2;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 0.5rem;
            pointer-events: none;
        }
        #cam-label .cam-label-name {
            flex: 1;
            text-align: left;
            min-width: 0;
            word-break: break-word;
        }
        #cam-label .cam-label-updated {
            flex-shrink: 0;
            font-size: 0.75rem;
            color: #ccc;
            text-align: right;
        }
        .message {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 30;
            color: #e6edf3;
            font-size: 1.1rem;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="stage">
        <!-- Map-first layer. -->
        <div class="layer" id="map-layer">
            <div id="map"></div>
        </div>
        <!-- Webcam layer. -->
        <div class="layer layer--hidden" id="cam-layer">
            <div id="cam-stage">
                <img id="cam-img" alt="Airport webcam">
            </div>
            <div id="cam-label">
                <span class="cam-label-name" id="cam-label-name"></span>
                <span class="cam-label-updated" id="cam-label-updated">Last Updated: --</span>
            </div>
        </div>
    </div>

    <div class="overlay wind-overlay" id="wind-overlay">
        <canvas id="wind-canvas" width="210" height="210"></canvas>
        <div class="wind-readout" id="wind-readout">--</div>
        <div class="wind-details" id="wind-details"></div>
    </div>
    <div class="overlay conditions-overlay" id="conditions-overlay">
        <h3>Current Conditions</h3>
        <div id="conditions-body">Loading...</div>
    </div>
    <div class="brand" id="brand"><img src="/public/images/logo.svg" alt="AviationWX.org"></div>
    <div class="page-title" id="page-title"></div>
    <div class="message" id="message" style="display:none;"></div>

    <script src="/public/js/leaflet.js"></script>
    <script src="/public/js/runway-label-layout.js"></script>
    <script src="/public/js/wind-compass-resize-utils.js"></script>
    <script src="/public/js/wind-visual.js"></script>
    <script>
    window.DISCOVER_WEATHERCAM_DECK = <?= $discoverWeathercamDeckJson ?>;
    window.DISCOVER_WEATHERCAM_SCOPE = <?= json_encode($discoverWeathercamScope) ?>;
    window.DISCOVER_WEATHERCAM_SPECIFIC = <?= $discoverWeathercamSpecific ? 'true' : 'false' ?>;
    window.DISCOVER_WEATHERCAM_DURATION_MIN = <?= json_encode($discoverWeathercamDuration) ?>;
    window.DISCOVER_WEATHERCAM_UNITS = <?= $discoverWeathercamUnitsJson ?>;
    (function () {
        'use strict';
        var fullDeck = Array.isArray(window.DISCOVER_WEATHERCAM_DECK) ? window.DISCOVER_WEATHERCAM_DECK.slice() : [];
        var runningDeck = shuffle(fullDeck.slice());
        var passRemaining = runningDeck.length;

        var UNITS = window.DISCOVER_WEATHERCAM_UNITS && typeof window.DISCOVER_WEATHERCAM_UNITS === 'object'
            ? window.DISCOVER_WEATHERCAM_UNITS
            : {};
        var TEMP_UNIT = (UNITS.temp === 'C') ? 'C' : 'F';
        var DIST_UNIT = (UNITS.dist === 'm') ? 'm' : 'ft';
        var WIND_UNIT = (UNITS.wind === 'mph') ? 'mph' : ((UNITS.wind === 'kmh') ? 'kmh' : 'kt');
        var BARO_UNIT_RAW = UNITS.baro ? String(UNITS.baro).toLowerCase() : 'inhg';
        var BARO_UNIT = (BARO_UNIT_RAW === 'hpa') ? 'hPa' : ((BARO_UNIT_RAW === 'mmhg') ? 'mmHg' : 'inHg');

        var SCOPE_IS_SPECIFIC = window.DISCOVER_WEATHERCAM_SPECIFIC === true;

        var mapLayer = document.getElementById('map-layer');
        var camLayer = document.getElementById('cam-layer');
        var camImg = document.getElementById('cam-img');
        var camLabelName = document.getElementById('cam-label-name');
        var camLabelUpdated = document.getElementById('cam-label-updated');
        var pageTitle = document.getElementById('page-title');
        var windOverlay = document.getElementById('wind-overlay');
        var conditionsOverlay = document.getElementById('conditions-overlay');
        var windCanvas = document.getElementById('wind-canvas');
        var windReadout = document.getElementById('wind-readout');
        var windDetails = document.getElementById('wind-details');
        var conditionsBody = document.getElementById('conditions-body');
        var message = document.getElementById('message');

        var MAP_DWELL_MS = 15000;          // hold the map after flying to an airport
        var FRAME_MS = 500;                // history player pacing (2 FPS), matches the dashboard player
        var SINGLE_FRAME_DWELL_MS = 30000; // hold a lone webcam image for a readable beat
        var HEALTH_TIMEOUT_MS = 5000;       // cap on the per-airport health re-check
        var IDLE_RETRY_MS = 30000;          // retry when the deck is empty
        var DURATION_MS = Math.max(60000, (window.DISCOVER_WEATHERCAM_DURATION_MIN || 5) * 60 * 1000);
        var radarLayer = null;
        var map = null;

        function delay(ms) {
            return new Promise(function (res) { setTimeout(res, ms); });
        }
        // Relative time for the cam "Last Updated" label, matching the
        // dashboard's format (Xs / Xm / Xh ago).
        function formatRelativeTime(seconds) {
            if (isNaN(seconds) || seconds < 0) {
                return '--';
            }
            if (seconds < 60) {
                return seconds + (seconds === 1 ? ' second' : ' seconds') + ' ago';
            }
            if (seconds < 3600) {
                var minutes = Math.floor(seconds / 60);
                return minutes + (minutes === 1 ? ' minute' : ' minutes') + ' ago';
            }
            if (seconds < 86400) {
                var hours = Math.floor(seconds / 3600);
                var rem = Math.floor((seconds % 3600) / 60);
                return hours + (hours === 1 ? ' hour' : ' hours') + ' ' +
                    rem + (rem === 1 ? ' minute' : ' minutes') + ' ago';
            }
            var days = Math.floor(seconds / 86400);
            return days + (days === 1 ? ' day' : ' days') + ' ago';
        }
        function shuffle(list) {
            for (var i = list.length - 1; i > 0; i--) {
                var j = Math.floor(Math.random() * (i + 1));
                var tmp = list[i];
                list[i] = list[j];
                list[j] = tmp;
            }
            return list;
        }
        function showMessage(text) {
            message.textContent = text || '';
            message.style.display = text ? 'block' : 'none';
        }
        function layerVisibility(layer, visible) {
            layer.classList.toggle('layer--hidden', !visible);
        }
        // The weather overlays sit over the map to give context while an
        // airport is located, then hide so the webcam is unobstructed. The
        // airport title rides on the map too, so it hides with the rest.
        function setWeatherOverlays(visible) {
            windOverlay.style.display = visible ? 'block' : 'none';
            conditionsOverlay.style.display = visible ? 'block' : 'none';
            pageTitle.style.display = visible ? 'block' : 'none';
        }

        function isOperational(id) {
            // A pinned airport shows even when degraded or down: the user asked
            // for it by name, so only a network failure fails closed. For the
            // rotating deck, operational or degraded is showable and a fully
            // down airport is skipped.
            return isReachable(id).then(function (status) {
                if (SCOPE_IS_SPECIFIC && fullDeck.length === 1 && fullDeck[0].id === id) {
                    return status !== null;  // reachable = showable for a pin
                }
                return status === 'operational' || status === 'degraded';
            });
        }

        function isReachable(id) {
            // Fail closed: a network blip or a hung health check reads as down,
            // so the kiosk never stalls waiting on one airport.
            var controller = (typeof AbortController !== 'undefined') ? new AbortController() : null;
            var timer = controller ? setTimeout(function () { controller.abort(); }, HEALTH_TIMEOUT_MS) : null;
            function cleanup() {
                if (timer) { clearTimeout(timer); }
            }
            return fetch('/discover?health=' + encodeURIComponent(id), {
                cache: 'no-store',
                signal: controller ? controller.signal : undefined
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    cleanup();
                    return (data && data.status) || null;
                })
                .catch(function () {
                    cleanup();
                    return null;
                });
        }

        // The server ships a scope-filtered, webcam-enriched deck. Hold it as
        // the full source and rotate a shuffled copy, re-checking each airport
        // before it is revealed. When the running list is exhausted, reshuffle
        // the full deck; the per-transition health check keeps a stale spell
        // from putting an airport on screen.
        function makeRunningDeck() {
            return shuffle(fullDeck.slice());
        }

        // ---- Map ----
        function initMap() {
            map = L.map('map', {
                zoomControl: false,
                attributionControl: false,
                center: [20, 0],
                zoom: 2
            });
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19
            }).addTo(map);
            // Cloud layer via the tile proxy.
            var cloudsUrl = '/api/map-tiles.php?layer=clouds_new&t={t}&z={z}&x={x}&y={y}';
            // Radar layer over the base, from the RainViewer manifest.
            fetch('/api/rainviewer-weather-maps.php')
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data && data.radar && Array.isArray(data.radar.past) && data.radar.past.length > 0) {
                        var last = data.radar.past[data.radar.past.length - 1];
                        var frameId = (last && last.path) ? last.path.split('/').pop() : null;
                        if (!frameId) { return; }
                        radarLayer = L.tileLayer(
                            '/api/map-tiles.php?layer=rainviewer&radar=' + encodeURIComponent(frameId) + '&z={z}&x={x}&y={y}',
                            { opacity: 0.55 }
                        );
                        radarLayer.addTo(map);
                    }
                })
                .catch(function () { /* Radar optional; base map still shows. */ });
        }

        function flyTo(airport) {
            return new Promise(function (resolve) {
                if (!map || airport.lat == null || airport.lon == null) {
                    resolve();
                    return;
                }
                map.flyTo([airport.lat, airport.lon], 13, { duration: 3 });
                setTimeout(resolve, MAP_DWELL_MS);
            });
        }

        // ---- Weather overlay ----
        function loadWeather(airport) {
            return fetch('/api/weather.php?airport=' + encodeURIComponent(airport.primary || airport.id))
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    var w = data && data.weather ? data.weather : null;
                    if (!w) { throw new Error('no weather'); }
                    renderWeather(w, airport);
                })
                .catch(function () {
                    windReadout.textContent = '--';
                    conditionsBody.textContent = 'Unavailable';
                });
        }

        // A row in the current-conditions panel, matching the widget's metric
        // rows: muted label on the left, bold value on the right.
        function metricRow(label, value, cls) {
            var valueCls = cls ? ' class="' + cls + '"' : '';
            return '<div class="metric-row">' +
                '<span class="label">' + label + '</span>' +
                '<span class="value"' + valueCls + '>' + value + '</span>' +
                '</div>';
        }

        // Unit-aware formatting for the metric rows. The weather API reports
        // °F, knots, inHg, and statute miles internally; these convert for
        // display when the configured unit differs.
        function fmtTempF(f) {
            if (f == null) { return '--'; }
            return TEMP_UNIT === 'C' ? Math.round((f - 32) * 5 / 9) + '&deg;C' : Math.round(f) + '&deg;F';
        }
        function fmtWindKt(kt) {
            if (kt == null) { return '--'; }
            if (WIND_UNIT === 'mph') { return Math.round(kt * 1.15078) + ' mph'; }
            if (WIND_UNIT === 'kmh') { return Math.round(kt * 1.852) + ' km/h'; }
            return Math.round(kt) + ' kt';
        }
        function fmtDistFt(ft) {
            if (ft == null) { return '--'; }
            return DIST_UNIT === 'm' ? Math.round(ft * 0.3048) + ' m' : Math.round(ft) + ' ft';
        }
        function fmtPressureInHg(inHg) {
            if (inHg == null) { return '--'; }
            if (BARO_UNIT === 'hPa') { return Math.round(inHg * 33.8639) + ' hPa'; }
            if (BARO_UNIT === 'mmHg') { return Math.round(inHg * 25.4) + ' mmHg'; }
            return inHg.toFixed(2) + ' inHg';
        }
        function fmtVisibilitySm(sm) {
            if (sm == null) { return '--'; }
            return DIST_UNIT === 'm' ? Math.round(sm * 1.60934) + ' km' : Math.round(sm) + ' SM';
        }

        function drawWind(weather, airport) {
            // Use the shared full-mode compass so the rose matches the dashboard
            // exactly: magnetic offset rotation, runway segments, wind-rose
            // petals, and staleness-aware rendering. Full mode needs the deck's
            // runway segments and thresholds plus the weather's obs-time map.
            if (!window.AviationWX || typeof window.AviationWX.drawWindCompass !== 'function') {
                return;
            }
            var ap = airport || {};
            var wd = weather.wind_direction || {};
            var lastHourObj = weather.last_hour_wind || null;
            var lastHourSectors = (lastHourObj && Array.isArray(lastHourObj.sectors)) ? lastHourObj.sectors : null;
            window.AviationWX.drawWindCompass(windCanvas, {
                windSpeed: (typeof weather.wind_speed === 'number') ? weather.wind_speed : null,
                windDirection: (typeof wd.magnetic_north === 'number') ? wd.magnetic_north : null,
                isVRB: !!wd.variable,
                isDark: true,
                night: false,
                size: 'full',
                cssSize: 210,
                fullMode: {
                    runwaySegments: Array.isArray(ap.runway_segments) ? ap.runway_segments : [],
                    lastHourWind: lastHourSectors,
                    periodLabel: (lastHourObj && lastHourObj.period_label) ? lastHourObj.period_label : 'last hour',
                    windDirectionMagnetic: (typeof wd.magnetic_north === 'number') ? wd.magnetic_north : null,
                    magneticDeclination: (typeof ap.magnetic_declination === 'number') ? ap.magnetic_declination : 0,
                    fieldObsTimeMap: (weather._field_obs_time_map && typeof weather._field_obs_time_map === 'object')
                        ? weather._field_obs_time_map
                        : {},
                    isMetarOnly: false,
                    staleFailclosedSeconds: (typeof ap.stale_failclosed_seconds === 'number') ? ap.stale_failclosed_seconds : 10800,
                    metarStaleFailclosedSeconds: (typeof ap.metar_stale_failclosed_seconds === 'number')
                        ? ap.metar_stale_failclosed_seconds
                        : 10800
                }
            });
        }

        // The lower-left wind module mirrors the dashboard's Runway Wind
        // details: speed, direction (magnetic), gusting, gust factor, and the
        // day's peak gust. Values mirror the dashboard's rendering.
        function renderWindDetails(w) {
            var speed = (typeof w.wind_speed === 'number') ? w.wind_speed : null;
            var gust = (typeof w.gust_speed === 'number') ? w.gust_speed : null;
            var wd = w.wind_direction || {};
            var mag = (typeof wd.magnetic_north === 'number') ? wd.magnetic_north : null;
            var isVRB = !!wd.variable;
            var calm = speed != null && speed < 3;

            var speedTxt = (speed == null) ? '--' : (calm ? 'Calm' : fmtWindKt(speed));
            var dirTxt = '---';
            if (!calm) {
                if (isVRB) { dirTxt = 'VRB'; }
                else if (mag != null) { dirTxt = Math.round(mag) + '&deg;'; }
            }
            var gustTxt = (gust != null && gust > 0) ? fmtWindKt(gust) : '--';
            var gustFactor = (speed != null && gust != null) ? (typeof w.gust_factor === 'number' ? fmtWindKt(w.gust_factor) : '--') : '--';
            var peak = (typeof w.peak_gust_today === 'number' && w.peak_gust_today > 0) ? fmtWindKt(w.peak_gust_today) : '--';

            var rows = '';
            rows += metricRow('Wind Speed', speedTxt);
            rows += metricRow('Wind Direction', dirTxt);
            rows += metricRow('Gusting', gustTxt);
            rows += metricRow('Gust Factor', gustFactor);
            rows += metricRow('Today\'s Peak Gust', peak);
            windDetails.innerHTML = rows;
        }

        function renderWeather(w, airport) {
            drawWind(w, airport);
            renderWindDetails(w);

            var speed = (typeof w.wind_speed === 'number') ? Math.round(w.wind_speed) : null;
            var gust = (typeof w.gust_speed === 'number') ? Math.round(w.gust_speed) : null;
            var windTxt = '--';
            if (speed != null) {
                windTxt = fmtWindKt(speed);
                if (gust != null) { windTxt += ' G' + Math.round(gust) + (WIND_UNIT === 'mph' ? ' mph' : (WIND_UNIT === 'kmh' ? ' km/h' : ' kt')); }
            }
            var wd = w.wind_direction || {};
            if (typeof wd.magnetic_north === 'number') {
                windTxt = wd.magnetic_north + '&deg; ' + windTxt;
            } else if (wd.variable) {
                windTxt = 'VRB ' + windTxt;
            }
            windReadout.innerHTML = windTxt;

            conditionsBody.innerHTML = renderConditions(w);
        }

        // Mirror the dashboard's Current Conditions grid on the right panel.
        // A row is only emitted when the airport actually has that data, so
        // an airport without a functional METAR omits condition, visibility,
        // ceiling, and cloud cover instead of showing dashes.
        function renderConditions(w) {
            var rows = '';
            var hasMetar = (typeof w.obs_time_metar === 'number' && w.obs_time_metar > 0) ||
                (typeof w.last_updated_metar === 'number' && w.last_updated_metar > 0);

            if (hasMetar) {
                var cat = (typeof w.flight_category === 'string' && w.flight_category) ? w.flight_category : null;
                if (cat) {
                    rows += metricRow('Condition', cat, 'status-' + cat.toLowerCase());
                }
                if (typeof w.visibility === 'number') {
                    rows += metricRow('Visibility', fmtVisibilitySm(w.visibility));
                }
                if (typeof w.ceiling === 'number') {
                    rows += metricRow('Ceiling', fmtDistFt(w.ceiling));
                }
                if (typeof w.cloud_cover === 'string' && w.cloud_cover) {
                    rows += metricRow('Clouds', w.cloud_cover);
                }
            }

            if (typeof w.temperature_f === 'number') {
                rows += metricRow('Temperature', fmtTempF(w.temperature_f));
            }
            if (typeof w.temp_high_today === 'number') {
                rows += metricRow('Today\'s High', fmtTempF(w.temp_high_today));
            }
            if (typeof w.temp_low_today === 'number') {
                rows += metricRow('Today\'s Low', fmtTempF(w.temp_low_today));
            }
            if (typeof w.density_altitude === 'number') {
                rows += metricRow('Density Altitude', fmtDistFt(w.density_altitude));
            }
            if (typeof w.pressure === 'number') {
                rows += metricRow('Pressure', fmtPressureInHg(w.pressure));
            }
            if (typeof w.pressure_altitude === 'number') {
                rows += metricRow('Pressure Altitude', fmtDistFt(w.pressure_altitude));
            }
            if (typeof w.humidity === 'number') {
                rows += metricRow('Humidity', Math.round(w.humidity) + '%');
            }
            if (typeof w.dewpoint_f === 'number') {
                rows += metricRow('Dewpoint', fmtTempF(w.dewpoint_f));
            }
            if (typeof w.dewpoint_spread === 'number') {
                rows += metricRow('Dewpoint Spread', fmtTempF(w.dewpoint_spread));
            }
            if (typeof w.precip_accum === 'number') {
                var rainaltIn = Math.round(w.precip_accum * 100) / 100;
                rows += metricRow('Rainfall Today',
                    DIST_UNIT === 'm' ? (Math.round(w.precip_accum * 2.54 * 100) / 100) + ' cm' : rainaltIn + ' in');
            }
            if (typeof w.sunrise === 'string' && w.sunrise) {
                rows += metricRow('Sunrise', w.sunrise);
            }
            if (typeof w.sunset === 'string' && w.sunset) {
                rows += metricRow('Sunset', w.sunset);
            }

            if (rows === '') {
                rows = '<span class="label">No weather data</span>';
            }
            return rows;
        }

        // ---- Webcam history player ----
        function getFrames(airport, camIndex) {
            return fetch('/api/webcam-history.php?id=' + encodeURIComponent(airport.id) + '&cam=' + camIndex, { cache: 'no-store' })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data || !data.enabled || !Array.isArray(data.frames) || data.frames.length === 0) {
                        return [];
                    }
                    // Slice to the last hour of frames. The manifest is oldest-first and we play
                    // it in that order, so the loop shows the hour unfold naturally.
                    var nowSec = Date.now() / 1000;
                    var recent = data.frames.filter(function (f) { return (nowSec - f.timestamp) <= 3600; });
                    if (recent.length === 0) {
                        return data.frames.slice(-1);  // fall back to the newest frame
                    }
                    return recent;
                })
                .catch(function () { return []; });
        }
        function preload(url) {
            var img = new Image();
            img.src = url;
            return img;
        }
        function playFrames(frames, camIndex, airport) {
            return new Promise(function (resolve) {
                if (frames.length === 0) {
                    resolve();
                    return;
                }
                var idx = 0;
                var displayIdx = 0;
                var tickTimer = null;
                camLabelName.textContent = airport.name || airport.id;
                camImg.classList.add('cam-img--show');

                // Show the age of the current frame, re-evaluated each second
                // like the dashboard's label until the frame changes.
                function tick() {
                    var ts = frames[displayIdx] && frames[displayIdx].timestamp;
                    var age = (typeof ts === 'number') ? (Date.now() / 1000) - ts : null;
                    camLabelUpdated.textContent = 'Last Updated: ' +
                        (age != null ? formatRelativeTime(age) : '--');
                    tickTimer = setTimeout(tick, 1000);
                }
                tick();

                function step() {
                    if (idx >= frames.length) {
                        if (tickTimer) { clearTimeout(tickTimer); }
                        resolve();
                        return;
                    }
                    camImg.src = frames[idx].url;
                    displayIdx = idx;
                    // Preload the next frame while this one shows.
                    if (idx + 1 < frames.length) {
                        preload(frames[idx + 1].url);
                    }
                    // tick() reads the displayed frame by index, so it tracks
                    // the age of the frame actually on screen.
                    idx++;
                    // A lone image gets a longer beat so it reads as a deliberate
                    // still rather than a near-instant flash.
                    var pause = (frames.length === 1) ? SINGLE_FRAME_DWELL_MS : FRAME_MS;
                    setTimeout(step, pause);
                }
                step();
            });
        }

        // A camera is playable if it has history frames.
        function playCamera(airport, camIndex) {
            return getFrames(airport, camIndex).then(function (frames) {
                if (frames.length === 0) {
                    return false;
                }
                return playFrames(frames, camIndex, airport).then(function () { return true; });
            });
        }

        // Full airport sequence: map fly-to, then each camera. Each camera plays one
        // full last-hour cycle; a soft budget (tourDuration, default 5 min) caps
        // how long a whole airport may run so a camera with dense history cannot
        // hold the rotation for most of a pass. Resolves after the airport (all
        // cams, or the budget) is done so the loop moves on.
        function playAirport(airport) {
            layerVisibility(mapLayer, true);
            layerVisibility(camLayer, false);
            setWeatherOverlays(true);
            showMessage('');
            var code = airport.primary || airport.id || '';
            pageTitle.textContent = (airport.name || '') +
                (code ? ' (' + code + ')' : '');
            loadWeather(airport);
            var startedAt = Date.now();
            var fly = (map && airport.lat != null && airport.lon != null) ? flyTo(airport) : delay(500);
            return fly.then(function () {
                // Re-check health before revealing the cams.
                return isOperational(airport.id).then(function (ok) {
                    if (!ok) {
                        return;
                    }
                    var camIndex = 0;
                    function withinBudget() {
                        return (Date.now() - startedAt) < DURATION_MS;
                    }
                    function loopCams() {
                        if (camIndex >= (airport.webcam_count || 1)) {
                            return;  // All cams done.
                        }
                        if (!withinBudget()) {
                            return;  // Budget spent; let the next airport have the screen.
                        }
                        layerVisibility(mapLayer, false);
                        layerVisibility(camLayer, true);
                        setWeatherOverlays(false);
                        return playCamera(airport, camIndex).then(function (played) {
                            camIndex++;
                            return loopCams();
                        });
                    }
                    if ((airport.webcam_count || 0) <= 0) {
                        return delay(1000);
                    }
                    return loopCams();
                });
            });
        }

        // Pull the next airport, reshuffling the full deck when the running
        // list runs dry. Returns null only when no airport is operational.
        // A full reshuffled pass is counted: if every airport in it fails,
        // null comes back so the kiosk idles instead of re-probing forever.
        function nextAirport() {
            if (runningDeck.length === 0) {
                runningDeck = makeRunningDeck();
                if (runningDeck.length === 0) {
                    return Promise.resolve(null);
                }
                passRemaining = runningDeck.length;
            }
            var airport = runningDeck.shift();
            if (!airport) {
                return Promise.resolve(null);
            }
            passRemaining--;
            return isOperational(airport.id).then(function (ok) {
                if (ok) {
                    return airport;
                }
                // Skip the unhealthy one and keep looking, but stop once a whole
                // pass has been probed and nothing is operational.
                if (passRemaining <= 0) {
                    return null;
                }
                return nextAirport();
            });
        }

        function navigateNext() {
            return nextAirport().then(function (airport) {
                if (!airport) {
                    // Nothing operational; rest on the map and retry.
                    layerVisibility(camLayer, false);
                    layerVisibility(mapLayer, true);
                    setWeatherOverlays(true);
                    pageTitle.textContent = '';
                    showMessage('');
                    return delay(IDLE_RETRY_MS).then(navigateNext);
                }
                return playAirport(airport).then(navigateNext);
            });
        }

        // Prefer dark mode, persist across the session.
        try {
            document.cookie = 'aviationwx_theme=dark; path=/; max-age=' + (30 * 24 * 60 * 60);
        } catch (e) { /* best-effort */ }

        initMap();
        if (runningDeck.length === 0 && fullDeck.length === 0) {
            showMessage('No eligible airports right now');
            return;
        }
        navigateNext();
    })();
    </script>
</body>
</html>