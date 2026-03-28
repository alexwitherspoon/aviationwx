/**
 * Weather "last updated" timestamp helpers (safety-critical display)
 *
 * Airport dashboards show when observations were last refreshed. The API/cache may
 * expose aggregate `last_updated` or only source-specific times (`last_updated_primary`,
 * `obs_time_*`). This module picks a single Unix second for UI consistency.
 *
 * Policy: use the maximum of all positive candidate fields so the label reflects the
 * freshest data the UI is showing (never silently hide recency). Numeric strings are
 * accepted so JSON/PHP edge shapes still resolve (integers only, reasonable range).
 *
 * @module weather-timestamp-utils
 */
/* global module -- UMD: Node tests require(); browser omits */
(function (global) {
    'use strict';

    /** Upper bound for string-parsed seconds (reject absurd strings without capping normal numbers) */
    const MAX_UNIX_SEC_STRING = 4000000000;

    /**
     * Normalize a single field to a positive Unix second, or null.
     * Strings must be decimal digits only (integer seconds); numbers keep legacy rules (positive finite).
     *
     * @param {*} v Raw field value
     * @returns {number|null}
     */
    function toPositiveUnixSeconds(v) {
        if (typeof v === 'number') {
            return v > 0 && Number.isFinite(v) ? v : null;
        }
        if (typeof v === 'string') {
            const s = v.trim();
            if (s.length === 0) {
                return null;
            }
            if (!/^\d+$/.test(s)) {
                return null;
            }
            const n = parseInt(s, 10);
            if (!Number.isFinite(n) || n <= 0 || n >= MAX_UNIX_SEC_STRING) {
                return null;
            }
            return n;
        }
        return null;
    }

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
        const candidates = [
            w.last_updated,
            w.last_updated_primary,
            w.last_updated_metar,
            w.obs_time_metar,
            w.obs_time_primary
        ];
        const nums = [];
        for (let i = 0; i < candidates.length; i++) {
            const t = toPositiveUnixSeconds(candidates[i]);
            if (t !== null) {
                nums.push(t);
            }
        }
        if (nums.length === 0) {
            return null;
        }
        return Math.max.apply(null, nums);
    }

    /**
     * Safe Date for "last updated" UI, or null if observation time cannot be determined.
     * Rejects invalid Date objects (e.g. overflow) so the airport page never formats NaN.
     *
     * @param {object|null|undefined} w Weather object from cache or API
     * @returns {Date|null}
     */
    function lastUpdatedDateFromWeather(w) {
        const sec = pickWeatherUnixTimestamp(w);
        if (sec === null) {
            return null;
        }
        const d = new Date(sec * 1000);
        return Number.isFinite(d.getTime()) ? d : null;
    }

    const api = {
        pickWeatherUnixTimestamp: pickWeatherUnixTimestamp,
        lastUpdatedDateFromWeather: lastUpdatedDateFromWeather
    };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        global.AviationWX = global.AviationWX || {};
        global.AviationWX.weatherTimestamp = api;
    }
})(typeof window !== 'undefined' ? window : globalThis);
