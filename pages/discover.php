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
require_once __DIR__ . '/../lib/status-checks.php';
require_once __DIR__ . '/../lib/discover.php';

// The kiosk never benefits from caching: the deck and the health re-check must
// reflect current data on every load.
if (php_sapi_name() !== 'cli' && !headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
}

// Show-time health re-check for one airport. The shell queries this just before
// it shows an airport so a dashboard that went unhealthy mid-day is skipped.
// Reading the health for every airport on every pass would be wasteful; the
// single-id read is what the shell actually needs each transition.
if (isset($_GET['health']) && $_GET['health'] !== '') {
    $healthConfig = loadConfig();
    $healthAirportId = strtolower(trim((string) $_GET['health']));
    // Fail closed: an unknown id or a failed read is never operational.
    $healthStatus = 'down';
    if ($healthConfig !== null && isset($healthConfig['airports'][$healthAirportId])) {
        $healthResult = checkAirportHealth($healthAirportId, $healthConfig['airports'][$healthAirportId]);
        $healthStatus = $healthResult['status'] ?? 'down';
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['id' => $healthAirportId, 'status' => $healthStatus]);
    exit;
}

// The current operational pool, for refreshing the deck between passes. Full
// pass over every airport costs the same as the status page, so the shell only
// calls this when its deck is exhausted, not on every transition.
if (isset($_GET['pool']) && $_GET['pool'] === '1') {
    $poolConfig = loadConfig();
    $poolAirports = $poolConfig !== null ? getListedAirports($poolConfig) : [];
    $poolStatusById = [];
    foreach ($poolAirports as $poolAirportId => $poolAirport) {
        $poolStatusById[$poolAirportId] = checkAirportHealth($poolAirportId, $poolAirport)['status'] ?? 'down';
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['airports' => discoverSelectOperationalAirports($poolAirports, $poolStatusById)]);
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Discover - AviationWX.org</title>
    <style>
        /* Full-bleed kiosk shell. The child pages are the display; the shell is
           only ever the dark backdrop behind the cross-fade between them. */
        html, body {
            margin: 0;
            padding: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            background: #0b0f14;
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
        }
    </style>
</head>
<body>
    <div class="discover-stage">
        <iframe class="discover-layer" id="discover-map" src="/airports" title="Airport network map"></iframe>
    </div>
</body>
</html>