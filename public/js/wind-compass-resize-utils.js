/**
 * Wind compass resize helpers for full-* embeds.
 *
 * Keeps the canvas bitmap in sync with its displayed box so narrow columns
 * get a crisp redraw instead of a CSS-downscaled bitmap.
 *
 * @module wind-compass-resize-utils
 */
(function (global) {
    'use strict';

    const MIN_CSS_SIZE = 48;
    const MAX_CSS_SIZE = 300;

    /**
     * Clamp and round the CSS box size used for a square wind compass.
     *
     * @param {number} clientWidth Measured container/client width in CSS pixels
     * @param {number} [fallback=200] Fallback when width is unavailable
     * @returns {number} CSS pixel size (width and height)
     */
    function resolveWindCompassCssSize(clientWidth, fallback) {
        const base = Number.isFinite(clientWidth) && clientWidth > 0
            ? clientWidth
            : (fallback || 200);
        return Math.max(MIN_CSS_SIZE, Math.min(MAX_CSS_SIZE, Math.round(base)));
    }

    /**
     * Map a CSS display size to canvas backing-store pixels.
     *
     * @param {number} cssSize Display width/height in CSS pixels
     * @param {number} [devicePixelRatio=1] Window/device pixel ratio
     * @returns {{cssSize: number, pixelSize: number}}
     */
    function computeWindCompassPixelSize(cssSize, devicePixelRatio) {
        const safeCss = resolveWindCompassCssSize(cssSize, cssSize);
        const dpr = Number.isFinite(devicePixelRatio) && devicePixelRatio > 0
            ? devicePixelRatio
            : 1;
        return {
            cssSize: safeCss,
            pixelSize: Math.max(1, Math.round(safeCss * dpr)),
        };
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = {
            MIN_CSS_SIZE,
            MAX_CSS_SIZE,
            resolveWindCompassCssSize,
            computeWindCompassPixelSize,
        };
    } else {
        global.AviationWX = global.AviationWX || {};
        global.AviationWX.windCompassResize = {
            MIN_CSS_SIZE,
            MAX_CSS_SIZE,
            resolveWindCompassCssSize,
            computeWindCompassPixelSize,
        };
    }
})(typeof window !== 'undefined' ? window : (typeof globalThis !== 'undefined' ? globalThis : this));
