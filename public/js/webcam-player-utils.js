/**
 * Webcam history player — preload cache helpers (safety-critical)
 *
 * Cache keys MUST scope by airport + camera + frame timestamp. Co-scheduled
 * cameras often share identical Unix timestamps; a timestamp-only key can
 * display the wrong camera's image.
 *
 * @module webcam-player-utils
 */
/* global module */
(function (global) {
    'use strict';

    /**
     * Build a stable preload cache key for one frame.
     *
     * @param {string} airportId Airport id (e.g. ksea)
     * @param {number} camIndex Camera index (0-based)
     * @param {number} timestamp Unix seconds
     * @returns {string}
     */
    function makeWebcamPreloadKey(airportId, camIndex, timestamp) {
        return String(airportId) + '|' + String(camIndex) + '|' + String(timestamp);
    }

    /**
     * Remove preload state for one camera when timestamps are no longer in the
     * active period. Preserves entries for other cameras on the same page.
     *
     * @param {Object<string, string>} preloadedImages Mutated in place
     * @param {Set<string>} loadingFrames Mutated in place
     * @param {string} airportId
     * @param {number} camIndex
     * @param {Set<number>} validTimestamps Timestamps still valid for this camera/period
     * @returns {void}
     */
    function pruneWebcamPreloadForCameraPeriod(
        preloadedImages,
        loadingFrames,
        airportId,
        camIndex,
        validTimestamps
    ) {
        const prefix = String(airportId) + '|' + String(camIndex) + '|';

        Object.keys(preloadedImages).forEach(function (key) {
            if (key.indexOf(prefix) !== 0) {
                return;
            }
            const ts = parseInt(key.slice(prefix.length), 10);
            if (!validTimestamps.has(ts)) {
                delete preloadedImages[key];
            }
        });

        // Copy to array: mutating Set while iterating is avoided for clarity
        const loadingSnapshot = [];
        loadingFrames.forEach(function (key) {
            loadingSnapshot.push(key);
        });
        loadingSnapshot.forEach(function (key) {
            if (key.indexOf(prefix) !== 0) {
                return;
            }
            const ts = parseInt(key.slice(prefix.length), 10);
            if (!validTimestamps.has(ts)) {
                loadingFrames.delete(key);
            }
        });
    }

    const api = {
        makeWebcamPreloadKey: makeWebcamPreloadKey,
        pruneWebcamPreloadForCameraPeriod: pruneWebcamPreloadForCameraPeriod
    };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        global.AviationWX = global.AviationWX || {};
        global.AviationWX.webcamPlayerUtils = api;
    }
})(typeof window !== 'undefined' ? window : globalThis);
