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

// The kiosk never benefits from caching: the deck and the health re-check must
// reflect current data on every load.
if (php_sapi_name() !== 'cli' && !headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
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