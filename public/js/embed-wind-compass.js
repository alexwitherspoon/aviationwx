/**
 * Backward-compatibility shim.
 *
 * The wind compass renderer moved to public/js/wind-visual.js. This file
 * preserves the historical /public/js/embed-wind-compass.js URL so any cached or
 * third-party loader (for example a widget.js bundle cached before the rename)
 * keeps working. It loads wind-visual.js, which defines
 * window.AviationWX.drawWindCompass.
 *
 * Loading wind-visual.js is asynchronous, so a legacy caller that loaded this
 * file as the implementation and calls drawWindCompass immediately would race
 * the load. To preserve the original "callable once this script has loaded"
 * contract, a temporary stub queues calls and replays them against the real
 * renderer once wind-visual.js is ready.
 */
(function () {
    'use strict';

    const ns = window.AviationWX = window.AviationWX || {};

    // Real implementation already present - nothing to do.
    if (typeof ns.drawWindCompass === 'function') {
        return;
    }

    // Resolve wind-visual.js relative to this script so it works on any origin
    // (production, staging, self-hosted deployments). Preserve any query string
    // (for example a ?v= cache-buster) so it propagates to wind-visual.js.
    const thisSrc = (document.currentScript && document.currentScript.src) || '';
    const target = thisSrc
        ? thisSrc.replace(/embed-wind-compass\.js(\?.*)?$/, (_match, query) => 'wind-visual.js' + (query || ''))
        : '/public/js/wind-visual.js';

    // Queue calls made before wind-visual.js finishes loading.
    const queued = [];
    const stub = function () {
        queued.push(arguments);
    };
    ns.drawWindCompass = stub;

    // wind-visual.js overwrites drawWindCompass with the real renderer on load;
    // replay anything that was queued against it.
    function flushQueue() {
        if (ns.drawWindCompass === stub) {
            return;
        }
        while (queued.length > 0) {
            ns.drawWindCompass.apply(ns, queued.shift());
        }
    }

    // If wind-visual.js cannot load (network error, blocked CSP, 404), surface
    // it and stop queuing so a long-lived page does not grow the queue unbounded.
    // The compass degrades gracefully (no draw) rather than failing silently.
    function handleLoadError() {
        queued.length = 0;
        if (ns.drawWindCompass === stub) {
            ns.drawWindCompass = function () {};
        }
        console.error('[AviationWX] Failed to load wind-visual.js; wind compass unavailable.');
    }

    const existing = document.querySelector('script[src="' + target + '"]');
    if (existing) {
        existing.addEventListener('load', flushQueue);
        existing.addEventListener('error', handleLoadError);
        return;
    }

    const script = document.createElement('script');
    script.src = target;
    script.addEventListener('load', flushQueue);
    script.addEventListener('error', handleLoadError);
    document.head.appendChild(script);
})();
