/**
 * Backward-compatibility shim.
 *
 * The wind compass renderer moved to public/js/wind-visual.js. This file
 * preserves the historical /public/js/embed-wind-compass.js URL so any cached or
 * third-party loader (for example a widget.js bundle cached before the rename)
 * keeps working. It loads wind-visual.js, which defines
 * window.AviationWX.drawWindCompass.
 */
(function () {
    'use strict';

    // Already provided by wind-visual.js - nothing to do.
    if (window.AviationWX && typeof window.AviationWX.drawWindCompass === 'function') {
        return;
    }

    // Resolve wind-visual.js relative to this script so it works on any origin
    // (production, staging, self-hosted deployments).
    const thisSrc = (document.currentScript && document.currentScript.src) || '';
    const target = thisSrc
        ? thisSrc.replace(/embed-wind-compass\.js(\?.*)?$/, 'wind-visual.js')
        : '/public/js/wind-visual.js';

    // Avoid loading twice if something else already requested it.
    if (document.querySelector('script[src="' + target + '"]')) {
        return;
    }

    const script = document.createElement('script');
    script.src = target;
    document.head.appendChild(script);
})();
