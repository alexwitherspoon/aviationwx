<?php
/**
 * Discover: autonomous booth tour of the airport network.
 *
 * A kiosk view that runs without interaction. The real airport map flies to a
 * healthy airport, holds briefly, then shows that airport's real dashboard for
 * a few minutes before moving on. The map and every dashboard are the existing
 * production pages, loaded in same-origin iframes and cross-faded by this shell.
 *
 * The tour animation lives entirely in the inline script below; this page owns
 * the timer, the deck, and the fade, and the child pages stay untouched.
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/discover.php';

// The kiosk never benefits from caching: the deck and the health re-check must
// reflect current data on every load.
if (php_sapi_name() !== 'cli' && !headers_sent()) {
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
}

// Show-time health re-check for one airport. The shell queries this just before
// it shows an airport so a dashboard that went unhealthy mid-day is skipped.
// It is a single-id read on purpose: scanning every airport on every pass is
// what the status page does, and the booth does not need that per transition.
if (isset($_GET['health']) && $_GET['health'] !== '') {
    $healthConfig = loadConfig();
    $healthAirportId = strtolower(trim((string) $_GET['health']));
    // Fail closed and listed-only: an unknown, unlisted, or failed read is
    // never operational, so a hidden airport cannot surface in the tour or
    // have its status probed through this endpoint.
    $healthStatus = 'down';
    if ($healthConfig !== null) {
        $listedAirports = getListedAirports($healthConfig);
        if (isset($listedAirports[$healthAirportId])) {
            $healthResult = checkAirportHealth($healthAirportId, $listedAirports[$healthAirportId]);
            $healthStatus = $healthResult['status'] ?? 'down';
        }
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['id' => $healthAirportId, 'status' => $healthStatus]);
    exit;
}

// The current operational pool, for refreshing the deck between passes. Full
// pass over every airport costs the same as the status page, so the shell only
// calls this when its deck is exhausted, not on every transition.
if (isset($_GET['pool']) && $_GET['pool'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['airports' => discoverGetCachedOperationalDeck(loadConfig())], JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// Mode dispatch. The bare /discover path is the menu; a configured flow is
// requested with ?mode=dashboard (tour) or ?mode=weathercam. Anything absent
// or unhandled falls back to the menu so a stale link never lands on a dead
// screen.
$discoverMode = isset($_GET['mode']) ? strtolower(trim((string) $_GET['mode'])) : '';
if ($discoverMode !== 'dashboard') {
    require_once __DIR__ . '/discover-menu.php';
    exit;
}

// HTML shell: compute and shuffle the initial deck once so the booth has an
// airport to show the moment the page finishes loading.
$discoverConfig = loadConfig();
$discoverDeck = discoverGetCachedOperationalDeck($discoverConfig);
shuffle($discoverDeck);
// Substitute invalid bytes so a stray config value can never break the inline
// script and leave the booth with a dead page.
$discoverDeckJson = json_encode($discoverDeck, JSON_INVALID_UTF8_SUBSTITUTE);
if ($discoverDeckJson === false) {
    $discoverDeckJson = '[]';
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <!-- The tour runs on configured URLs (?mode=dashboard), so keep it off the index. -->
    <meta name="robots" content="noindex, nofollow">
    <title>Discover - AviationWX.org</title>
    <style>
        /* Full-bleed kiosk shell. The child pages are the display; the shell is
           only the dark backdrop and the fade between them. */
        html, body {
            margin: 0;
            padding: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            background: #0b0f14;
            cursor: none;
            -webkit-user-select: none;
            user-select: none;
        }
        .discover-stage {
            position: fixed;
            inset: 0;
        }
        .discover-layer {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            border: 0;
            opacity: 1;
            transition: opacity 1.2s ease;
        }
        /* Hiding is visibility after the fade so the kiosk stops painting the
           inactive page entirely, which is what a Raspberry Pi needs. */
        .discover-layer--hidden {
            opacity: 0;
            visibility: hidden;
            transition: opacity 1.2s ease, visibility 0s linear 1.2s;
        }
        .discover-caption {
            position: fixed;
            left: 50%;
            bottom: 2.5rem;
            transform: translateX(-50%);
            padding: 0.6rem 1.2rem;
            background: rgba(11, 15, 20, 0.78);
            color: #e6edf3;
            font-family: system-ui, -apple-system, sans-serif;
            font-size: 1.05rem;
            line-height: 1.4;
            border-radius: 8px;
            opacity: 1;
            transition: opacity 0.6s ease;
            pointer-events: none;
            z-index: 10;
        }
        .discover-caption--hidden {
            opacity: 0;
        }
        .discover-brand {
            position: fixed;
            top: 14px;
            left: 14px;
            background: rgba(11, 15, 20, 0.72);
            border-radius: 8px;
            padding: 8px 12px;
            pointer-events: none;
            z-index: 20;
            line-height: 0;
        }
        .discover-brand img {
            height: 26px;
            display: block;
        }
    </style>
</head>
<body>
    <div class="discover-stage">
        <iframe class="discover-layer" id="discover-map" src="/airports" title="Airport network map"></iframe>
        <iframe class="discover-layer discover-layer--hidden" id="discover-dashboard" title="Airport dashboard"></iframe>
    </div>
    <div class="discover-caption discover-caption--hidden" id="discover-caption"></div>
    <div class="discover-brand" id="discover-brand"><img src="/public/images/logo.svg" alt="AviationWX.org"></div>
    <script>
    window.DISCOVER_DECK = <?= $discoverDeckJson ?>;
    (function () {
        var deck = Array.isArray(window.DISCOVER_DECK) ? window.DISCOVER_DECK.slice() : [];
        var mapFrame = document.getElementById('discover-map');
        var dashFrame = document.getElementById('discover-dashboard');
        var caption = document.getElementById('discover-caption');

        var DWELL_MS = 5 * 60 * 1000;       // time a dashboard stays up
        var FLY_TIMEOUT_MS = 8000;          // cap on waiting for the map flight
        var SETTLE_MS = 2500;               // hold after the flight before the fade
        var PREWARM_TIMEOUT_MS = 15000;     // cap on waiting for a dashboard to load
        var SKIP_DELAY_MS = 3000;           // settle pause after skipping a failed dashboard
        var FADE_MS = 1200;                 // matches the CSS opacity transition
        var SECTION_FIRST_DELAY_MS = 8000;  // let the top of the dashboard read first
        var SECTION_DWELL_MS = 20000;       // pause on each section before moving on
        var PASS_GAP_MS = 250;              // breather between section passes
        var PAN_MS = 20000;                 // smooth + legible hop between sections
        var IDLE_RETRY_MS = 30000;          // retry when nothing is eligible

        // Dashboard sections to dwell on, in document order. Each is a CSS
        // selector into the dashboard document; the final null is the page
        // bottom. Sections can be absent on a given airport (webcam-less,
        // weather-less), so the tour builds its target list at runtime.
        var DASH_SECTIONS = [
            '.webcam-section',
            '.wind-visual-section',
            '.weather-section',
            '.airport-info',
            null   // page bottom
        ];

        function delay(ms) {
            return new Promise(function (resolve) { setTimeout(resolve, ms); });
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

        function setLayerVisible(frame, visible) {
            if (visible) {
                frame.classList.remove('discover-layer--hidden');
            } else {
                frame.classList.add('discover-layer--hidden');
            }
        }

        function setCaption(text) {
            if (text) {
                caption.textContent = text;
                caption.classList.remove('discover-caption--hidden');
            } else {
                caption.classList.add('discover-caption--hidden');
            }
        }

        function refreshDeck() {
            return fetch('/discover?pool=1', { cache: 'no-store' })
                .then(function (res) {
                    if (!res.ok) {
                        throw new Error('deck refresh failed');
                    }
                    return res.json();
                })
                .then(function (data) {
                    var airports = (data && Array.isArray(data.airports)) ? data.airports : [];
                    return shuffle(airports);
                })
                .catch(function () { return []; });
        }

        function isOperational(id) {
            // Fail closed: a network blip reads as not operational rather than
            // risking a stale or unknown dashboard on the booth screen.
            return fetch('/discover?health=' + encodeURIComponent(id), { cache: 'no-store' })
                .then(function (res) { return res.json(); })
                .then(function (data) { return data && data.status === 'operational'; })
                .catch(function () { return false; });
        }

        // Pop the next eligible airport. Skips any that no longer report
        // operational right now, and refreshes the deck once when it runs dry.
        // Returns null when there is nothing showable, so the shell idles.
        function nextAirport() {
            function select() {
                var airport = deck.pop();
                return isOperational(airport.id).then(function (ok) {
                    if (ok) {
                        return airport;
                    }
                    if (deck.length === 0) {
                        return null;
                    }
                    return select();
                });
            }

            if (deck.length === 0) {
                return refreshDeck().then(function (fresh) {
                    deck = fresh;
                    if (deck.length === 0) {
                        return null;
                    }
                    return select();
                });
            }
            return select();
        }

        function flyTo(id) {
            return new Promise(function (resolve) {
                var finished = false;

                function finish() {
                    if (finished) {
                        return;
                    }
                    finished = true;
                    window.removeEventListener('message', onMessage);
                    resolve();
                }

                function onMessage(event) {
                    if (event.origin !== window.location.origin) {
                        return;
                    }
                    if (event.data && event.data.type === 'discover-fly-to-done' && event.data.id === id) {
                        finish();
                    }
                }

                window.addEventListener('message', onMessage);
                // The timeout keeps a stuck or missing map from stalling the booth.
                setTimeout(finish, FLY_TIMEOUT_MS);
                if (mapFrame.contentWindow) {
                    mapFrame.contentWindow.postMessage(
                        { type: 'discover-fly-to', id: id },
                        window.location.origin
                    );
                }
            });
        }

        function prewarmDashboard(airport) {
            // Resolves true only when the dashboard actually loaded and is a
            // real dashboard. A 500 or 404 error document also fires iframe
            // load, so the load event alone must not count as success. The
            // #status-banners container is rendered by every airport dashboard
            // and never by an error page.
            return new Promise(function (resolve) {
                var settled = false;

                function finish(loaded) {
                    if (settled) {
                        return;
                    }
                    settled = true;
                    dashFrame.removeEventListener('load', onLoad);
                    if (loaded) {
                        hideScrollbars();
                        applyDarkMode();
                    }
                    resolve(loaded);
                }

                function isDashboardReady() {
                    try {
                        var doc = dashFrame.contentDocument;
                        return !!doc && !!doc.getElementById('status-banners');
                    } catch (e) {
                        return false;
                    }
                }

                function onLoad() {
                    finish(isDashboardReady());
                }

                dashFrame.addEventListener('load', onLoad);
                dashFrame.src = '/?airport=' + encodeURIComponent(airport.primary);
                setTimeout(function () { finish(false); }, PREWARM_TIMEOUT_MS);
            });
        }

        // The pan is driven from here, so the dashboard itself never learns it
        // is on a screen. Hiding its scrollbar keeps the pan from reading as a
        // scroll bar being dragged; the page is otherwise untouched.
        function hideScrollbars() {
            try {
                var doc = dashFrame.contentDocument;
                if (!doc || !doc.head) {
                    return;
                }
                var style = doc.createElement('style');
                style.textContent =
                    'html::-webkit-scrollbar { width: 0 !important; height: 0 !important; }' +
                    'html { scrollbar-width: none; }';
                doc.head.appendChild(style);
            } catch (e) {
                // Same-origin, but never let a cosmetic step break the tour.
            }
        }

        // Prefer the dark dashboard theme to spare the booth's LCD. The cookie
        // persists the choice across subdomain loads; the class set here takes
        // effect on the current iframe instantly so the first dwell is already
        // dark. Both are best-effort - a failed write never blocks the tour.
        function applyDarkMode() {
            try {
                document.cookie = 'aviationwx_theme=dark; path=/; max-age=' + (30 * 24 * 60 * 60);
            } catch (e) {
                // Cookie write failure is not worth stalling the booth.
            }
            try {
                var doc = dashFrame.contentDocument;
                if (doc && doc.documentElement) {
                    doc.documentElement.classList.add('dark-mode');
                    if (doc.body) {
                        doc.body.classList.add('dark-mode');
                    }
                }
            } catch (e) {
                // Same-origin, but never let a theme step break the tour.
            }
        }

        function maxScrollY(win) {
            var doc = win.document.documentElement;
            return Math.max(0, (doc.scrollHeight || 0) - (win.innerHeight || doc.clientHeight));
        }

        function scrollTween(win, targetY, durationMs, abort) {
            return new Promise(function (resolve) {
                var max = maxScrollY(win);
                if (max <= 0) {
                    resolve();
                    return;
                }
                var clamped = Math.max(0, Math.min(targetY, max));
                var doc = win.document.documentElement;
                var startY = typeof win.scrollY === 'number' ? win.scrollY : doc.scrollTop;
                var start = (typeof performance !== 'undefined' && performance.now)
                    ? performance.now()
                    : Date.now();

                function easeInOutQuad(t) {
                    return t < 0.5 ? 2 * t * t : -1 + (4 - 2 * t) * t;
                }

                function step(now) {
                    if (abort && abort.stopped) {
                        resolve();
                        return;
                    }
                    var t = Math.min((now - start) / durationMs, 1);
                    var y = startY + (clamped - startY) * easeInOutQuad(t);
                    win.scrollTo(0, y);
                    if (t < 1) {
                        window.requestAnimationFrame(step);
                    } else {
                        resolve();
                    }
                }

                window.requestAnimationFrame(step);
            });
        }

        // Resolve the sections that exist on the current dashboard into scroll
        // targets (top pixel of each section, then the page bottom). Sections
        // are conditional on an airport, so this is built at runtime and only
        // tall pages are walked at all.
        function dashboardSectionTargets(win) {
            var doc = win.document;
            var max = maxScrollY(win);
            if (max <= 0) {
                return [];
            }
            var targets = [];
            for (var i = 0; i < DASH_SECTIONS.length; i++) {
                var selector = DASH_SECTIONS[i];
                if (selector === null) {
                    // The final null is the page bottom, capped at max scroll.
                    var bottom = targets.length > 0 ? targets[targets.length - 1] : 0;
                    if (max > bottom) {
                        targets.push(max);
                    }
                    continue;
                }
                var el;
                try {
                    el = doc.querySelector(selector);
                } catch (e) {
                    el = null;
                }
                if (el && typeof el.offsetTop === 'number') {
                    // Clamp to the max scroll so a section near the end does not
                    // create an unreachable target.
                    var y = Math.min(el.offsetTop, max);
                    if (targets.length === 0 || targets[targets.length - 1] !== y) {
                        targets.push(y);
                    }
                }
            }
            return targets;
        }

        // Dwell on each section in order, then back up, walking once over the
        // sections that are present. Short dashboards that fit the screen stay
        // put (no targets), matching the prior "only pan tall pages" rule.
        // Resolves when one full down-and-up pass completes so the dwell loop
        // can re-run it for the rest of the dwell window. An abort token
        // ({ stopped }) stops the walk and any in-flight tween so a pass that
        // outlives the dwell window cannot keep scrolling the next dashboard.
        function runSectionCycle(abort) {
            return new Promise(function (resolve) {
                var win;
                try {
                    win = dashFrame.contentWindow;
                    if (!win || !win.document || !win.document.documentElement) {
                        resolve();
                        return;
                    }
                } catch (e) {
                    // Never let an unreachable document stall the booth.
                    resolve();
                    return;
                }
                var targets = dashboardSectionTargets(win);
                if (targets.length === 0) {
                    resolve();
                    return;
                }
                var index = 0;
                var movingDown = true;

                function step() {
                    if (abort && abort.stopped) {
                        resolve();
                        return;
                    }
                    var y = targets[index];
                    scrollTween(win, y, PAN_MS, abort)
                        .then(function () { return delay(SECTION_DWELL_MS); })
                        .then(function () {
                            if (abort && abort.stopped) {
                                resolve();
                                return;
                            }
                            if (movingDown) {
                                if (index === targets.length - 1) {
                                    // Hit the bottom; flip and head back up.
                                    if (targets.length === 1) {
                                        // Nowhere to walk back to; done after
                                        // the single target.
                                        resolve();
                                        return;
                                    }
                                    movingDown = false;
                                    index--;
                                } else {
                                    index++;
                                }
                            } else {
                                if (index === 0) {
                                    resolve();
                                    return;
                                }
                                index--;
                            }
                            step();
                        });
                }
                step();
            });
        }

        function dwell() {
            return new Promise(function (resolve) {
                // One token per dwell: flipping it stops the current walk and
                // its in-flight tween, so a pass that spans past DWELL_MS cannot
                // scroll the dashboard that loads for the next airport.
                var abort = { stopped: false };
                var startTimer = setTimeout(function () {
                    var cycleLoop = function () {
                        if (abort.stopped) {
                            return;
                        }
                        // runSectionCycle can resolve synchronously (short or
                        // section-less dashboard), so yield a real timer gap or
                        // the loop would starve the event loop.
                        runSectionCycle(abort).then(function () {
                            if (abort.stopped) {
                                return;
                            }
                            delay(PASS_GAP_MS).then(cycleLoop);
                        });
                    };
                    cycleLoop();
                }, SECTION_FIRST_DELAY_MS);

                setTimeout(function () {
                    abort.stopped = true;
                    clearTimeout(startTimer);
                    resolve();
                }, DWELL_MS);
            });
        }

        function runCycle() {
            return nextAirport().then(function (airport) {
                if (!airport) {
                    // Nothing eligible right now; rest on the network map and
                    // try again after a quiet pause.
                    setLayerVisible(dashFrame, false);
                    setLayerVisible(mapFrame, true);
                    setCaption('');
                    return delay(IDLE_RETRY_MS).then(runCycle);
                }

                setLayerVisible(mapFrame, true);
                setCaption(airport.name);

                var prewarm = prewarmDashboard(airport);

                return flyTo(airport.id)
                    .then(function () { return delay(SETTLE_MS); })
                    .then(function () { return prewarm; })
                    .then(function (loaded) {
                        if (!loaded) {
                            // The dashboard never finished loading; hold the
                            // map on the airport rather than dwelling on a
                            // blank iframe, then move on to the next airport.
                            setCaption('');
                            return false;
                        }
                        return isOperational(airport.id).then(function (stillOk) {
                            setCaption('');
                            if (!stillOk) {
                                // The airport can go unhealthy during the
                                // flight and prewarm; check again just before
                                // revealing so a dead dashboard is not dwelled.
                                return false;
                            }
                            setLayerVisible(mapFrame, false);
                            setLayerVisible(dashFrame, true);
                            return delay(FADE_MS).then(function () { return true; });
                        });
                    })
                    .then(function (shown) {
                        if (!shown) {
                            return delay(SKIP_DELAY_MS).then(runCycle);
                        }
                        return dwell().then(function () {
                            setLayerVisible(dashFrame, false);
                            setLayerVisible(mapFrame, true);
                            return delay(FADE_MS).then(runCycle);
                        });
                    });
            });
        }

        function startLoop() {
            runCycle().catch(function (err) {
                if (typeof console !== 'undefined' && console.error) {
                    console.error('discover cycle error', err);
                }
                // A bad cycle must never stall the booth.
                delay(IDLE_RETRY_MS).then(startLoop);
            });
        }

        // Wait for the map page to finish loading before the first flight, so
        // the fly-to message has a listener to receive it. The dashboard loads
        // after the first flight anyway.
        function start() {
            new Promise(function (resolve) {
                var doc = null;
                try {
                    doc = mapFrame.contentDocument;
                } catch (e) {
                    doc = null;
                }
                if (doc && doc.readyState === 'complete') {
                    resolve();
                    return;
                }
                mapFrame.addEventListener('load', function onLoad() {
                    mapFrame.removeEventListener('load', onLoad);
                    resolve();
                });
                setTimeout(resolve, FLY_TIMEOUT_MS);
            }).then(startLoop);
        }

        start();
    })();
    </script>
</body>
</html>
