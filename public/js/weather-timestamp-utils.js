/**
 * Weather "last updated" timestamp helpers (safety-critical display)
 *
 * Airport UI should show **when the observation was made**, not when our server last
 * fetched the METAR/sensor (`last_updated_*`). Pipeline fields:
 * - Observation: `obs_time_metar`, `obs_time_primary`, per-field `_field_obs_time_map`
 * - Fetch/cache: `last_updated`, `last_updated_primary`, `last_updated_metar`
 *
 * `pickObservationUnixTimestamp` / `lastUpdatedDateFromWeather` prefer observation times
 * (max of available observation candidates), then fall back to fetch times if no obs metadata.
 *
 * `pickWeatherUnixTimestamp` keeps legacy behavior: max of all candidate fields (API parity,
 * diagnostics). Numeric strings are accepted where noted below.
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
     * Max of fetch/cache fields only (`last_updated*`).
     *
     * @param {object|null|undefined} w Weather object from cache or API
     * @returns {number|null} Unix seconds, or null if no valid time
     */
    function pickFetchUnixTimestamp(w) {
        if (w === null || w === undefined || typeof w !== 'object') {
            return null;
        }
        const candidates = [w.last_updated, w.last_updated_primary, w.last_updated_metar];
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
     * Unix second for UI "last updated": latest **observation** time, not server fetch.
     * Uses `obs_time_metar`, `obs_time_primary`, and `_field_obs_time_map` values; if none,
     * falls back to {@link pickFetchUnixTimestamp}.
     *
     * @param {object|null|undefined} w Weather object from cache or API
     * @returns {number|null} Unix seconds, or null if no valid time
     */
    function pickObservationUnixTimestamp(w) {
        if (w === null || w === undefined || typeof w !== 'object') {
            return null;
        }
        const nums = [];
        [w.obs_time_metar, w.obs_time_primary].forEach(function (v) {
            const t = toPositiveUnixSeconds(v);
            if (t !== null) {
                nums.push(t);
            }
        });
        const map = w._field_obs_time_map;
        if (map && typeof map === 'object' && !Array.isArray(map)) {
            const keys = Object.keys(map);
            for (let i = 0; i < keys.length; i++) {
                const t = toPositiveUnixSeconds(map[keys[i]]);
                if (t !== null) {
                    nums.push(t);
                }
            }
        }
        if (nums.length > 0) {
            return Math.max.apply(null, nums);
        }
        return pickFetchUnixTimestamp(w);
    }

    /**
     * Legacy: max of observation and fetch fields (freshest metadata of any kind).
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
        const map = w._field_obs_time_map;
        if (map && typeof map === 'object' && !Array.isArray(map)) {
            const keys = Object.keys(map);
            for (let j = 0; j < keys.length; j++) {
                const t = toPositiveUnixSeconds(map[keys[j]]);
                if (t !== null) {
                    nums.push(t);
                }
            }
        }
        if (nums.length === 0) {
            return null;
        }
        return Math.max.apply(null, nums);
    }

    /**
     * Safe Date for airport "last updated" line: **observation** time when available.
     *
     * @param {object|null|undefined} w Weather object from cache or API
     * @returns {Date|null}
     */
    function lastUpdatedDateFromWeather(w) {
        const sec = pickObservationUnixTimestamp(w);
        if (sec === null) {
            return null;
        }
        const d = new Date(sec * 1000);
        return Number.isFinite(d.getTime()) ? d : null;
    }

    const api = {
        pickFetchUnixTimestamp: pickFetchUnixTimestamp,
        pickObservationUnixTimestamp: pickObservationUnixTimestamp,
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
