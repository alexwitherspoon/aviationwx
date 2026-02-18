/**
 * Wind Rose Utilities - Safety Critical
 *
 * Sector-to-canvas angle conversion for wind rose petal visualization.
 * Sectors: N=0, NNE=1, NE=2, ENE=3, E=4, ESE=5, SE=6, SSE=7, S=8, SSW=9, SW=10, WSW=11, W=12, WNW=13, NW=14, NNW=15 (WMO/ICAO convention).
 * Canvas: 0 = 3 o'clock (E), angles increase clockwise. North = -Math.PI/2.
 *
 * @module wind-rose-utils
 */
(function (global) {
    'use strict';

    const DEG2RAD = Math.PI / 180;

    /**
     * Get canvas arc angles for a wind rose sector (pie slice).
     * Sector i: meteorological direction = i*22.5° (from N, clockwise).
     * Canvas angle = met - 90 (so N -> -90° = -Math.PI/2).
     *
     * @param {number} sectorIndex 0-15 (N, NNE, NE, ENE, E, ESE, SE, SSE, S, SSW, SW, WSW, W, WNW, NW, NNW)
     * @returns {{center: number, start: number, end: number}} Radians
     */
    function getSectorCanvasAngles(sectorIndex) {
        const i = Math.max(0, Math.min(15, Math.floor(sectorIndex)));
        const centerAngle = (i * 22.5 - 90) * DEG2RAD;
        const halfWidth = (22.5 / 2) * DEG2RAD;
        return {
            center: centerAngle,
            start: centerAngle - halfWidth,
            end: centerAngle + halfWidth,
        };
    }

    /**
     * Validate last_hour_wind array from API (16 sectors, non-negative numbers).
     *
     * @param {*} val Value from API
     * @returns {boolean}
     */
    function isValidLastHourWind(val) {
        if (!Array.isArray(val) || val.length !== 16) return false;
        return val.every((v) => typeof v === 'number' && !Number.isNaN(v) && v >= 0);
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { getSectorCanvasAngles, isValidLastHourWind };
    } else {
        global.AviationWX = global.AviationWX || {};
        global.AviationWX.windRose = {
            getSectorCanvasAngles,
            isValidLastHourWind,
        };
    }
})(typeof window !== 'undefined' ? window : globalThis);
