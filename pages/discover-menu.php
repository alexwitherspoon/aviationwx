<?php
/**
 * Discover menu: pick a demo mode and scope, get a configured URL.
 *
 * The landing page for the discover kiosk. It renders one card per demo mode
 * and a scope selector, then shows a live preview URL for the configured
 * combination. The URL carries every choice in query params, so a screen can
 * bookmark or be pointed at one specific flow. Play/Load navigates there.
 *
 * This file is included from pages/discover.php only when no mode (or an
 * unhandled mode) was requested. It owns its own full document; the shell does
 * not wrap it.
 */

require_once __DIR__ . '/../lib/config.php';

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php if ($discoverMenuNoIndex): ?>
    <meta name="robots" content="noindex, nofollow">
    <?php endif; ?>
    <title>Discover - AviationWX.org</title>
    <style>
        :root {
            --bg: #0b0f14;
            --panel: #141a22;
            --panel-border: #2a3441;
            --text: #e6edf3;
            --muted: #9aa7b4;
            --accent: #4c9aff;
            --accent-hover: #6aadff;
        }
        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            padding: 0;
            background: var(--bg);
            color: var(--text);
            font-family: system-ui, -apple-system, sans-serif;
            min-height: 100vh;
        }
        .wrap {
            max-width: 860px;
            margin: 0 auto;
            padding: 2.5rem 1.5rem 4rem;
        }
        h1 {
            font-size: 1.6rem;
            margin: 0 0 0.25rem;
        }
        .tagline {
            color: var(--muted);
            margin: 0 0 2rem;
        }
        .modes {
            display: grid;
            gap: 1rem;
            margin-bottom: 2rem;
        }
        .mode-card {
            background: var(--panel);
            border: 1px solid var(--panel-border);
            border-radius: 10px;
            padding: 1.25rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }
        .mode-card h2 { margin: 0 0 0.25rem; font-size: 1.1rem; }
        .mode-card p { margin: 0; color: var(--muted); }

        .controls {
            background: var(--panel);
            border: 1px solid var(--panel-border);
            border-radius: 10px;
            padding: 1.25rem 1.5rem;
            margin-bottom: 2rem;
        }
        .controls h3 {
            margin: 0 0 1rem;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--muted);
        }
        .field {
            margin-bottom: 1rem;
        }
        .field:last-child { margin-bottom: 0; }
        .field label {
            display: block;
            color: var(--muted);
            font-size: 0.85rem;
            margin-bottom: 0.35rem;
        }
        .field select,
        .field input {
            width: 100%;
            padding: 0.6rem 0.75rem;
            background: #0f141b;
            border: 1px solid var(--panel-border);
            border-radius: 6px;
            color: var(--text);
            font-size: 0.95rem;
        }
        .field select:focus,
        .field input:focus {
            outline: none;
            border-color: var(--accent);
        }

        .url-row {
            display: flex;
            gap: 0.6rem;
            align-items: center;
        }
        .url-row .preview {
            flex: 1;
            background: #0f141b;
            border: 1px solid var(--panel-border);
            border-radius: 6px;
            padding: 0.6rem 0.75rem;
            font-family: 'Monaco', 'Menlo', monospace;
            font-size: 0.8rem;
            color: var(--text);
            overflow-x: auto;
            white-space: nowrap;
        }
        button {
            border: 0;
            border-radius: 6px;
            padding: 0.6rem 1rem;
            font-size: 0.9rem;
            cursor: pointer;
            background: var(--accent);
            color: #fff;
            white-space: nowrap;
        }
        button:hover { background: var(--accent-hover); }
        button.secondary {
            background: transparent;
            border: 1px solid var(--panel-border);
            color: var(--text);
        }
        button.secondary:hover { background: #1a222c; }
        button:disabled {
            background: transparent;
            border: 1px solid var(--panel-border);
            color: var(--muted);
            cursor: not-allowed;
        }
        .hint { color: var(--muted); font-size: 0.8rem; margin-top: 0.5rem; }
        .footer {
            margin-top: 2rem;
            color: var(--muted);
            font-size: 0.85rem;
            text-align: center;
        }
        .footer a { color: var(--accent); text-decoration: none; }
        .footer a:hover { text-decoration: underline; }
        .hidden { display: none; }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>AviationWX Discover</h1>
        <p class="tagline">Pick a demo, choose what to show, and point a display at the URL.</p>

        <div class="modes">
            <div class="mode-card">
                <div>
                    <h2>Dashboard Tour</h2>
                    <p>Fly the map to each airport, then scroll its live dashboard.</p>
                </div>
                <button type="button" id="pick-dashboard">Choose</button>
            </div>
            <div class="mode-card">
                <div>
                    <h2>Weathercam Loop</h2>
                    <p>Fly to each airport for context, then play its webcams.</p>
                </div>
                <button type="button" id="pick-weathercam">Choose</button>
            </div>
        </div>

        <div class="controls">
            <h3>Configure</h3>
            <div class="field">
                <label for="mode">Mode</label>
                <select id="mode">
                    <option value="dashboard">Dashboard Tour</option>
                    <option value="weathercam">Weathercam Loop</option>
                </select>
            </div>
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
            <div class="field">
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

        <div class="controls">
            <h3>URL</h3>
            <div class="url-row">
                <div class="preview" id="preview" aria-live="polite"></div>
                <button type="button" id="copy">Copy</button>
                <button type="button" id="play">Play</button>
            </div>
            <p class="hint">Bookmark the URL, or paste it into a display screen. This page stays off the search index when configured.</p>
        </div>

        <div class="footer">
            <a href="https://aviationwx.org">aviationwx.org</a> &middot; Built for pilots, by pilots
        </div>
    </div>

    <script>
    (function () {
        'use strict';

        var AIRPORTS = <?= $discoverMenuAirportsJson ?>;

        var mode = document.getElementById('mode');
        var scope = document.getElementById('scope');
        var tourDuration = document.getElementById('tourDuration');
        var preview = document.getElementById('preview');
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
            params.set('mode', mode.value);
            params.set('scope', scope.value);
            params.set('tourDuration', tourDuration.value);
            return '/discover?' + params.toString();
        }

        function render() {
            preview.textContent = buildUrl();
        }

        mode.addEventListener('change', render);
        scope.addEventListener('change', render);
        tourDuration.addEventListener('change', render);

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

        function chooseMode(value) {
            mode.value = value;
            render();
            playBtn.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        document.getElementById('pick-dashboard').addEventListener('click', function () { chooseMode('dashboard'); });
        document.getElementById('pick-weathercam').addEventListener('click', function () { chooseMode('weathercam'); });

        buildScopeOptions();
        render();
    })();
    </script>
</body>
</html>