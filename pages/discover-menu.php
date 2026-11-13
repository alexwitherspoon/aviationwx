<?php
/**
 * Discover menu: pick a demo mode and scope, get a configured URL.
 *
 * The landing page for the discover kiosk, styled to match the embed
 * generator. It renders one card per demo mode and a scope selector, then
 * shows a live preview and a full URL for the configured combination. The URL
 * carries every choice in query params, so a display can be pointed at one
 * specific flow.
 *
 * This file is included from pages/discover.php only when no mode (or an
 * unhandled mode) was requested. It owns its own full document; the shell does
 * not wrap it.
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/seo.php';

header('Content-Type: text/html; charset=utf-8');

// The bare menu is in the sitemap and indexable. A configured page (any query
// params, e.g. a stale mode) must stay off the index, matching the embed
// configurator's noindex rule for configured states.
$discoverMenuNoIndex = !empty($_SERVER['QUERY_STRING']);

// Slim projection of the airports the menu can offer, in listed config order.
// Inlined server-side so the controls render without a client fetch flash, and
// the region grouping has data to work from on first load. Fields mirror the
// public API summary shape so the client code can later swap to a live fetch
// without a schema change.
$discoverMenuAirports = [];
$discoverMenuConfig = loadConfig();
if ($discoverMenuConfig !== null) {
    foreach (getListedAirports($discoverMenuConfig) as $airportId => $airport) {
        $country = getEffectiveIso3166Alpha2ForAirport($airport);
        $region = getEffectiveIso3166RegionForAirport($airportId, $airport);
        $discoverMenuAirports[] = [
            'id' => $airportId,
            'name' => $airport['name'] ?? (string) $airportId,
            'country' => $country,
            'country_name' => $country !== null ? countryNameFromIso3166Alpha2($country) : null,
            'region' => $region,
            'region_name' => $region !== null ? subdivisionNameFromIso3166($region) : null,
            'has_webcams' => isset($airport['webcams']) && is_array($airport['webcams']) && count($airport['webcams']) > 0,
        ];
    }
}
$discoverMenuAirportsJson = json_encode($discoverMenuAirports, JSON_INVALID_UTF8_SUBSTITUTE);
if ($discoverMenuAirportsJson === false) {
    $discoverMenuAirportsJson = '[]';
}

$baseDomain = getBaseDomain();
$pageTitle = 'Discover - AviationWX.org';
$pageDescription = 'Configure and play the AviationWX demo kiosk: a dashboard tour or a landscape weathercam tour you can bookmark onto a display screen.';
$canonicalUrl = 'https://discover.' . $baseDomain;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php if ($discoverMenuNoIndex): ?>
    <meta name="robots" content="noindex, nofollow">
    <?php endif; ?>
    <title><?= htmlspecialchars($pageTitle) ?></title>

    <?php
    echo generateFaviconTags();
    echo "\n    ";
    echo generateEnhancedMetaTags($pageDescription, 'discover, demo, airport webcams, weather kiosk, digital signage');
    echo "\n    ";
    echo generateCanonicalTag($canonicalUrl);
    ?>
    <link rel="stylesheet" href="/public/css/navigation.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            line-height: 1.6;
            color: #333;
            background: #f5f5f5;
            min-height: 100vh;
        }

        .container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 1.5rem;
        }

        .page-heading {
            margin-bottom: 1.5rem;
        }
        .page-heading h1 {
            font-size: 1.5rem;
            color: #222;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .page-heading p {
            color: #666;
            font-size: 0.95rem;
        }

        .main-layout {
            display: grid;
            grid-template-columns: minmax(340px, 400px) 3fr;
            gap: 2rem;
            align-items: start;
        }
        @media (max-width: 900px) {
            .main-layout {
                grid-template-columns: 1fr;
            }
        }
        .left-panel {
            min-width: 0;
        }

        /* Panels and controls, site-light like the embed generator */
        .panel {
            background: #ffffff;
            border: 1px solid #ddd;
            border-radius: 12px;
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.5rem;
        }
        .panel h3 {
            color: #0066cc;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.9rem;
            border-bottom: 1px solid #eee;
            padding-bottom: 0.6rem;
        }
        .panel:last-child { margin-bottom: 0; }

        .modes {
            display: grid;
            gap: 0.75rem;
        }
        .mode-card {
            border: 1px solid #d9d9d9;
            border-radius: 8px;
            padding: 0.9rem 1rem;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            transition: border-color 0.15s, background 0.15s;
        }
        .mode-card:hover { border-color: #0066cc; }
        .mode-card--active {
            border-color: #0066cc;
            background: #f0f7ff;
        }
        .mode-card h4 { font-size: 1rem; color: #222; margin-bottom: 0.15rem; }
        .mode-card p { margin: 0; color: #666; font-size: 0.85rem; }
        .mode-card .check { color: #0066cc; font-size: 1.2rem; line-height: 1; }

        .field { margin-bottom: 1rem; }
        .field:last-child { margin-bottom: 0; }
        .field label {
            display: block;
            color: #666;
            font-size: 0.85rem;
            margin-bottom: 0.35rem;
        }
        .field select {
            width: 100%;
            padding: 0.55rem 0.7rem;
            background: #f8f9fa;
            border: 1px solid #d9d9d9;
            border-radius: 6px;
            color: #333;
            font-size: 0.95rem;
        }
        .field select:focus {
            outline: none;
            border-color: #0066cc;
        }

        /* Right column */
        .right-column {
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .right-column .panel { margin-bottom: 0; }

        .preview-panel {
            background: #ffffff;
            border: 1px solid #ddd;
            border-radius: 12px;
            overflow: hidden;
        }
        .preview-header {
            padding: 0.8rem 1.2rem;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .preview-header h3 {
            color: #0066cc;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0;
            border: 0;
            padding: 0;
        }
        .preview-header span { color: #888; font-size: 0.8rem; }
        .preview-body {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 1rem;
            background: #0b0f14;
        }
        .preview-frame {
            width: 100%;
            height: auto;
            aspect-ratio: 16 / 9;
            border: 0;
            display: block;
            background: #0b0f14;
        }
        .units-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0 1rem;
        }

        .url-row {
            display: flex;
            gap: 0.6rem;
            align-items: center;
        }
        .url-row .url-value {
            flex: 1;
            background: #f8f9fa;
            border: 1px solid #d9d9d9;
            border-radius: 6px;
            padding: 0.55rem 0.7rem;
            font-family: 'Monaco', 'Menlo', monospace;
            font-size: 0.8rem;
            color: #333;
            overflow-x: auto;
            white-space: nowrap;
        }
        .url-row .action-btn {
            border: 0;
            border-radius: 6px;
            padding: 0.55rem 1rem;
            font-size: 0.9rem;
            cursor: pointer;
            background: #0066cc;
            color: #fff;
            white-space: nowrap;
        }
        .url-row .action-btn:hover { background: #0052a3; }
        .url-row .action-btn.secondary {
            background: #fff;
            border: 1px solid #d9d9d9;
            color: #333;
        }
        .url-row .action-btn.secondary:hover { background: #f0f0f0; }
        .hint { color: #888; font-size: 0.8rem; margin-top: 0.5rem; }

        .footer {
            text-align: center;
            padding: 2rem;
            color: #666;
            font-size: 0.85rem;
            border-top: 1px solid #e0e0e0;
            margin-top: 2rem;
        }
        .footer a { color: #0066cc; text-decoration: none; }
        .footer a:hover { text-decoration: underline; }
        .hidden { display: none; }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/../lib/navigation.php'; ?>

    <div class="container">
        <div class="page-heading">
            <h1>✈️ Discover</h1>
            <p>Configure the demo kiosk, preview it, and copy a URL to point a display at.</p>
        </div>

        <div class="main-layout">
            <div class="left-panel">
                <div class="panel">
                    <h3>Mode</h3>
                    <div class="modes">
                        <div class="mode-card mode-card--active" id="mode-dashboard" role="button" tabindex="0">
                            <div>
                                <h4>Dashboard Tour</h4>
                                <p>Fly the map to each airport, then scroll its live dashboard.</p>
                            </div>
                            <span class="check">&#10003;</span>
                        </div>
                        <div class="mode-card" id="mode-weathercam" role="button" tabindex="0">
                            <div>
                                <h4>Weathercam Tour</h4>
                                <p>Fly to each airport for context, then play its webcams.</p>
                            </div>
                            <span class="check"></span>
                        </div>
                    </div>
                </div>

                <div class="panel">
                    <h3>Configure</h3>
                    <div class="field">
                        <label for="scope">Scope</label>
                        <select id="scope">
                            <option value="all">All airports</option>
                            <optgroup label="State / region">
                                <!-- regions filled from the projection -->
                            </optgroup>
                            <optgroup label="Specific airport">
                                <!-- airports filled from the projection -->
                            </optgroup>
                        </select>
                    </div>
                    <div class="field" id="duration-field">
                        <label for="tourDuration">Minutes per airport</label>
                        <select id="tourDuration" title="How long each airport stays up">
                            <option value="1">1</option>
                            <option value="2">2</option>
                            <option value="3">3</option>
                            <option value="5" selected>5</option>
                            <option value="10">10</option>
                            <option value="15">15</option>
                            <option value="30">30</option>
                            <option value="60">60</option>
                        </select>
                    </div>
                </div>

                <div class="panel hidden" id="units-panel">
                    <h3>Units</h3>
                    <div class="units-grid">
                        <div class="field">
                            <label for="unitTemp">Temperature</label>
                            <select id="unitTemp">
                                <option value="F" selected>&deg;F</option>
                                <option value="C">&deg;C</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="unitDist">Distance</label>
                            <select id="unitDist">
                                <option value="ft" selected>ft / SM</option>
                                <option value="m">m / km</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="unitWind">Wind</label>
                            <select id="unitWind">
                                <option value="kt" selected>kt</option>
                                <option value="mph">mph</option>
                                <option value="kmh">km/h</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="unitBaro">Pressure</label>
                            <select id="unitBaro">
                                <option value="inHg" selected>inHg</option>
                                <option value="hPa">hPa</option>
                                <option value="mmHg">mmHg</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="right-column">
                <div class="preview-panel">
                    <div class="preview-header">
                        <h3>Live Preview</h3>
                        <span id="preview-mode-label">Dashboard Tour</span>
                    </div>
                    <div class="preview-body">
                        <iframe class="preview-frame" id="preview-frame" title="Discover preview"></iframe>
                    </div>
                </div>

                <div class="panel">
                    <h3>URL</h3>
                    <div class="url-row">
                        <div class="url-value" id="preview" aria-live="polite"></div>
                        <button type="button" class="action-btn" id="copy">Copy</button>
                        <button type="button" class="action-btn secondary" id="play">Play</button>
                    </div>
                    <p class="hint">Bookmark the URL, or paste it into a display screen. This page stays off the search index when configured.</p>
                </div>
            </div>
        </div>
    </div>

    <footer class="footer">
        <p>
            &copy; <?= date('Y') ?> <a href="https://aviationwx.org">AviationWX.org</a> &middot;
            <a href="https://airports.aviationwx.org">Airports</a> &middot;
            <a href="https://guides.aviationwx.org">Guides</a> &middot;
            <a href="https://aviationwx.org#about-the-project">Built for pilots, by pilots</a> &middot;
            <a href="https://github.com/alexwitherspoon/aviationwx" target="_blank" rel="noopener">Open Source</a> &middot;
            <a href="https://terms.aviationwx.org">Terms of Service</a> &middot;
            <a href="https://api.aviationwx.org">API</a> &middot;
            <a href="https://status.aviationwx.org">Status</a>
        </p>
    </footer>

    <script>
    (function () {
        'use strict';

        var AIRPORTS = <?= $discoverMenuAirportsJson ?>;

        // Mode is chosen by the two cards, not a dropdown.
        var MODES = {
            dashboard: { id: 'mode-dashboard', label: 'Dashboard Tour' },
            weathercam: { id: 'mode-weathercam', label: 'Weathercam Tour' }
        };
        var activeMode = 'dashboard';
        var scope = document.getElementById('scope');
        var tourDuration = document.getElementById('tourDuration');
        var unitTemp = document.getElementById('unitTemp');
        var unitDist = document.getElementById('unitDist');
        var unitWind = document.getElementById('unitWind');
        var unitBaro = document.getElementById('unitBaro');
        var preview = document.getElementById('preview');
        var previewFrame = document.getElementById('preview-frame');
        var previewModeLabel = document.getElementById('preview-mode-label');
        var copyBtn = document.getElementById('copy');
        var playBtn = document.getElementById('play');

        function buildScopeOptions() {
            // Region group: distinct region_name with an ISO 3166-2 value.
            var regions = {};
            AIRPORTS.forEach(function (a) {
                if (a.region && a.region_name) {
                    regions[a.region] = {
                        region: a.region,
                        regionName: a.region_name,
                        countryName: a.country_name || '',
                    };
                }
            });

            var regionGroup = scope.querySelector('optgroup[label="State / region"]');
            regionGroup.innerHTML = '';
            Object.keys(regions).sort(function (ra, rb) {
                return regions[ra].regionName.localeCompare(regions[rb].regionName);
            }).forEach(function (key) {
                var r = regions[key];
                var opt = document.createElement('option');
                opt.value = r.region;
                var label = r.regionName;
                if (r.countryName && r.countryName !== label) {
                    label += ' (' + r.countryName + ')';
                }
                opt.textContent = label;
                regionGroup.appendChild(opt);
            });

            var airportGroup = scope.querySelector('optgroup[label="Specific airport"]');
            airportGroup.innerHTML = '';
            AIRPORTS.slice().sort(function (a, b) {
                return (a.name || '').localeCompare(b.name || '');
            }).forEach(function (a) {
                var opt = document.createElement('option');
                opt.value = a.id;
                opt.textContent = a.name + ' (' + a.id.toUpperCase() + ')';
                airportGroup.appendChild(opt);
            });
        }

        function buildUrl() {
            var params = new URLSearchParams();
            params.set('mode', activeMode);
            params.set('scope', scope.value);
            params.set('tourDuration', tourDuration.value);
            // The unit params only apply to the weathercam tour, so only append
            // them there; the dashboard tour ignores them.
            if (activeMode === 'weathercam') {
                if (unitTemp.value !== 'F') { params.set('temp', unitTemp.value); }
                if (unitDist.value !== 'ft') { params.set('dist', unitDist.value); }
                if (unitWind.value !== 'kt') { params.set('wind', unitWind.value); }
                if (unitBaro.value !== 'inHg') { params.set('baro', unitBaro.value); }
            }
            return window.location.origin + '/discover?' + params.toString();
        }

        function selectModeCard(mode) {
            Object.keys(MODES).forEach(function (m) {
                document.getElementById(MODES[m].id).classList.toggle('mode-card--active', m === mode);
            });
        }

        function render() {
            var url = buildUrl();
            preview.textContent = url;
            previewModeLabel.textContent = MODES[activeMode].label;
            previewFrame.src = url;
        }

        function chooseMode(mode) {
            if (!MODES[mode]) { return; }
            activeMode = mode;
            selectModeCard(mode);
            // Parameters are scoped to the tour that uses them: minutes per
            // airport belongs to the dashboard tour, units to the weathercam
            // tour, and neither shows on the tour it does not apply to.
            var unitsPanel = document.getElementById('units-panel');
            var durationField = document.getElementById('duration-field');
            if (unitsPanel) {
                unitsPanel.classList.toggle('hidden', mode !== 'weathercam');
            }
            if (durationField) {
                durationField.classList.toggle('hidden', mode !== 'dashboard');
            }
            render();
        }

        scope.addEventListener('change', render);
        tourDuration.addEventListener('change', render);
        unitTemp.addEventListener('change', render);
        unitDist.addEventListener('change', render);
        unitWind.addEventListener('change', render);
        unitBaro.addEventListener('change', render);

        Object.keys(MODES).forEach(function (m) {
            var card = document.getElementById(MODES[m].id);
            card.addEventListener('click', function () { chooseMode(m); });
            card.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    chooseMode(m);
                }
            });
        });

        copyBtn.addEventListener('click', function () {
            var url = buildUrl();
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(function () {
                    copyBtn.textContent = 'Copied';
                    setTimeout(function () { copyBtn.textContent = 'Copy'; }, 1500);
                });
            } else {
                // Fallback for older browsers / iframes without clipboard access.
                var ta = document.createElement('textarea');
                ta.value = url;
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); } catch (e) { /* no-op */ }
                document.body.removeChild(ta);
                copyBtn.textContent = 'Copied';
                setTimeout(function () { copyBtn.textContent = 'Copy'; }, 1500);
            }
        });

        playBtn.addEventListener('click', function () {
            window.location.href = buildUrl();
        });

        buildScopeOptions();
        render();
    })();
    </script>
</body>
</html>