/**
 * Weather "last updated" timestamp helpers (safety-critical display)
 *
 * Airport dashboards show when observations were last refreshed. The API/cache may
 * expose aggregate `last_updated` or only source-specific times (`last_updated_primary`,
 * `obs_time_*`). This module picks a single Unix second for UI consistency.
 *
 * Policy: use the maximum of all positive numeric candidate fields so the label
 * reflects the freshest data the UI is showing (never silently hide recency).
 *
 * @module weather-timestamp-utils
 */
/* global module -- UMD: Node tests require(); browser omits */
(function (global) {
    'use strict';

    /**
     * Best-effort Unix timestamp (seconds) for "last updated" display.
     *
     * @param {object|null|undefined} w Weather object from cache or API
     * @returns {number|null} Unix seconds, or null if no valid time
     */
    function pickWeatherUnixTimestamp(w) {
        if (w === null || w === undefined || typeof w !== 'object') {
            return null;
        }
        const nums = [
            w.last_updated,
            w.last_updated_primary,
            w.last_updated_metar,
            w.obs_time_metar,
            w.obs_time_primary
        ].filter(function (t) {
            return typeof t === 'number' && t > 0 && Number.isFinite(t);
        });
        if (nums.length === 0) {
            return null;
        }
        return Math.max.apply(null, nums);
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { pickWeatherUnixTimestamp };
    } else {
        global.AviationWX = global.AviationWX || {};
        global.AviationWX.weatherTimestamp = {
            pickWeatherUnixTimestamp: pickWeatherUnixTimestamp
        };
    }
})(typeof window !== 'undefined' ? window : globalThis);
