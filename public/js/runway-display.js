/**
 * Runway facts display for Airport Information (hybrid cards).
 */
(function () {
    'use strict';

    const MISSING = '---';

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function isCalmWindSpeed(speed) {
        if (typeof window !== 'undefined'
            && window.AviationWX
            && typeof window.AviationWX.isCalmWindSpeed === 'function') {
            return window.AviationWX.isCalmWindSpeed(speed);
        }
        return speed !== null && speed !== undefined && Number.isFinite(speed) && speed < 3;
    }

    function distanceUnit() {
        if (typeof getDistanceUnit === 'function') {
            return getDistanceUnit();
        }
        return 'ft';
    }

    function windUnitLabel() {
        if (typeof getWindSpeedUnitLabel === 'function') {
            return getWindSpeedUnitLabel();
        }
        return 'kts';
    }

    function formatWindKts(kts) {
        if (kts === null || kts === undefined) {
            return MISSING;
        }
        if (typeof formatWindSpeed === 'function') {
            return String(formatWindSpeed(kts));
        }
        return String(Math.round(kts));
    }

    function formatLengthFt(lengthFt) {
        if (lengthFt === null || lengthFt === undefined || !Number.isFinite(Number(lengthFt))) {
            return MISSING;
        }
        if (typeof formatAltitude === 'function') {
            return String(formatAltitude(lengthFt));
        }
        const unit = distanceUnit();
        return unit === 'm'
            ? String(Math.round(Number(lengthFt) * 0.3048))
            : String(Math.round(Number(lengthFt)));
    }

    function formatDimensions(lengthFt, widthFt) {
        const len = formatLengthFt(lengthFt);
        if (len === MISSING) {
            return MISSING;
        }
        const unit = distanceUnit() === 'm' ? 'm' : 'ft';
        if (widthFt === null || widthFt === undefined || !Number.isFinite(Number(widthFt))) {
            return len + ' ' + unit;
        }
        const wid = typeof formatAltitude === 'function'
            ? String(formatAltitude(widthFt))
            : (distanceUnit() === 'm'
                ? String(Math.round(Number(widthFt) * 0.3048))
                : String(Math.round(Number(widthFt))));
        return len + ' \u00d7 ' + wid + ' ' + unit;
    }

    function headwindKts(windFromDeg, windSpeedKts, runwayHeadingDeg) {
        const delta = ((runwayHeadingDeg - windFromDeg) * Math.PI) / 180;
        return windSpeedKts * Math.cos(delta);
    }

    function signedCrosswindKts(windFromDeg, windSpeedKts, runwayHeadingDeg) {
        const delta = ((runwayHeadingDeg - windFromDeg) * Math.PI) / 180;
        return windSpeedKts * Math.sin(delta);
    }

    /**
     * Along-fuselage arrow from the pilot's perspective on final (nose toward threshold).
     * Down = into the wind (headwind); up = tailwind.
     *
     * @param {number} headwindKtsSigned Signed headwind component in knots
     * @return {string} Unicode arrow character
     */
    function alongRunwayWindArrow(headwindKtsSigned) {
        return headwindKtsSigned >= 0 ? '\u2193' : '\u2191';
    }

    /**
     * Across-fuselage drift arrow from the pilot's perspective on final.
     * Right = pushed right; left = pushed left (not METAR wind-from direction).
     *
     * @param {number} crosswindKtsSigned Signed crosswind component in knots
     * @return {string} Unicode arrow character
     */
    function crosswindDriftArrow(crosswindKtsSigned) {
        return crosswindKtsSigned >= 0 ? '\u2192' : '\u2190';
    }

    /**
     * Whether a signed component is large enough to show a directional arrow after rounding.
     *
     * @param {number} signedKts Signed component in knots
     * @return {boolean}
     */
    function windComponentShowsDirectionalArrow(signedKts) {
        if (!Number.isFinite(signedKts)) {
            return false;
        }

        return formatWindKts(Math.abs(signedKts)) !== '0';
    }

    /**
     * Directional arrow for display, omitted when the rounded magnitude is zero.
     *
     * @param {number} signedKts Signed component in knots
     * @param {function(number): string} arrowFn Arrow selector for non-zero components
     * @return {string}
     */
    function formatWindComponentArrow(signedKts, arrowFn) {
        if (!windComponentShowsDirectionalArrow(signedKts)) {
            return '';
        }

        return arrowFn(signedKts);
    }

    /**
     * CSS class for along-runway wind component coloring.
     *
     * @param {number} headwindKtsSigned Signed headwind component in knots
     * @return {string} rwy-comp-hw or rwy-comp-tw
     */
    function alongRunwayWindCssClass(headwindKtsSigned) {
        return headwindKtsSigned >= 0 ? 'rwy-comp-hw' : 'rwy-comp-tw';
    }

    /**
     * Per-end wind display arrows and classes for tests and render.
     *
     * @param {number} windFromDeg Wind direction (magnetic, degrees from)
     * @param {number} windSpeedKts Wind speed in knots
     * @param {number} runwayHeadingDeg Runway end magnetic heading
     * @return {{headwindKts: number, crosswindKts: number, alongArrow: string, crosswindArrow: string, alongClass: string}}
     */
    function runwayEndWindDisplay(windFromDeg, windSpeedKts, runwayHeadingDeg) {
        const hw = headwindKts(windFromDeg, windSpeedKts, runwayHeadingDeg);
        const xw = signedCrosswindKts(windFromDeg, windSpeedKts, runwayHeadingDeg);
        return {
            headwindKts: hw,
            crosswindKts: xw,
            alongArrow: formatWindComponentArrow(hw, alongRunwayWindArrow),
            crosswindArrow: formatWindComponentArrow(xw, crosswindDriftArrow),
            alongClass: alongRunwayWindCssClass(hw),
        };
    }

    function windFromWeather(weather) {
        if (!weather) {
            return null;
        }
        const wdObj = weather.wind_direction;
        if (wdObj && typeof wdObj === 'object') {
            return wdObj.magnetic_north != null ? wdObj.magnetic_north : null;
        }
        if (weather.wind_direction == null || weather.wind_direction === undefined) {
            return null;
        }
        return weather.wind_direction_magnetic != null ? weather.wind_direction_magnetic : null;
    }

    function isRunwayWindReady(weather) {
        const windFromRaw = windFromWeather(weather);
        const windSpeedRaw = weather && weather.wind_speed;
        return windFromRaw != null && windSpeedRaw != null
            && Number.isFinite(Number(windFromRaw))
            && Number.isFinite(Number(windSpeedRaw));
    }

    function calmWindEndTags(end, calm) {
        if (!calm) {
            return '';
        }
        const arrival = end.calm_wind_arrival === true;
        const departure = end.calm_wind_departure === true;
        if (arrival && departure) {
            return '<span class="runway-calm-wind-badge runway-calm-wind-badge--inline">Calm Wind Runway</span>';
        }
        const tags = [];
        if (arrival) {
            tags.push('<span class="runway-calm-wind-badge runway-calm-wind-badge--inline">Calm wind arr</span>');
        }
        if (departure) {
            tags.push('<span class="runway-calm-wind-badge runway-calm-wind-badge--inline">Calm wind dep</span>');
        }
        return tags.join(' ');
    }

    function endWindLine(end, weather, calm) {
        const heading = end.heading_mag;
        const unit = windUnitLabel();
        const endIdHtml = escapeHtml(end.end_id);
        const calmTags = calmWindEndTags(end, calm);

        if (heading == null || !Number.isFinite(heading)) {
            return ''
                + '<div class="runway-hybrid-wind-row runway-dense-end">'
                + '<span class="runway-hybrid-end-id runway-dense-end-id">' + endIdHtml + '</span>'
                + '<span class="runway-dense-end-id">:</span> '
                + '<span class="rwy-comp-hw">' + MISSING + ' ' + unit + '</span> '
                + '<span class="rwy-comp-xw">' + MISSING + ' ' + unit + '</span>'
                + calmTags
                + '</div>';
        }

        if (!calm && !isRunwayWindReady(weather)) {
            return ''
                + '<div class="runway-hybrid-wind-row runway-dense-end">'
                + '<span class="runway-hybrid-end-id runway-dense-end-id">' + endIdHtml + '</span>'
                + '<span class="runway-dense-end-id">:</span> '
                + '<span class="rwy-comp-hw">' + MISSING + ' ' + unit + '</span> '
                + '<span class="rwy-comp-xw">' + MISSING + ' ' + unit + '</span>'
                + calmTags
                + '</div>';
        }

        const windFrom = calm ? 0 : Number(windFromWeather(weather));
        const windSpeed = calm ? 0 : Number(weather.wind_speed);
        let hw = headwindKts(windFrom, windSpeed, heading);
        let xwSigned = signedCrosswindKts(windFrom, windSpeed, heading);
        const hwArrow = formatWindComponentArrow(hw, alongRunwayWindArrow);
        const hwClass = alongRunwayWindCssClass(hw);
        const hwVal = formatWindKts(Math.abs(hw));
        const xwVal = formatWindKts(Math.abs(xwSigned));
        const xwArrow = formatWindComponentArrow(xwSigned, crosswindDriftArrow);
        const hwPrefix = hwArrow === '' ? '' : hwArrow + ' ';
        const xwPrefix = xwArrow === '' ? '' : xwArrow + ' ';
        return ''
            + '<div class="runway-hybrid-wind-row runway-dense-end">'
            + '<span class="runway-hybrid-end-id runway-dense-end-id">' + endIdHtml + '</span>'
            + '<span class="runway-dense-end-id">:</span> '
            + '<span class="' + hwClass + '">' + hwPrefix + hwVal + ' ' + unit + '</span> '
            + '<span class="rwy-comp-xw">' + xwPrefix + xwVal + ' ' + unit + '</span>'
            + calmTags
            + '</div>';
    }

    function displayValue(value) {
        if (value === null || value === undefined || value === '') {
            return MISSING;
        }
        return escapeHtml(String(value));
    }

    function runwayCardHtml(row, weather) {
        const calm = isCalmWindSpeed(weather.wind_speed);
        const closedHtml = row.closed
            ? '<span class="runway-style-status">\u274C RWY CLSD</span>'
            : '';
        const dim = formatDimensions(row.length_ft, row.width_ft);
        const surface = displayValue(row.surface);
        const lights = displayValue(row.lights);
        const traffic = row.traffic
            ? '<div class="runway-style-traffic">' + escapeHtml(row.traffic) + '</div>'
            : '';
        const ends = Array.isArray(row.ends) ? row.ends : [];
        const windHtml = row.is_helipad
            ? ''
            : ends.map(function (end) {
                return endWindLine(end, weather, calm);
            }).join('');
        const specsHtml = ''
            + '<div class="runway-hybrid-specs">'
            + '<span><span class="spec-label">Surface:</span> <span class="spec-val">' + surface + '</span></span>'
            + '<span><span class="spec-label">Lights:</span> <span class="spec-val">' + lights + '</span></span>'
            + '</div>';

        return ''
            + '<div class="weather-item runway-hybrid-card">'
            + '<div class="runway-hybrid-headline"><span class="value">' + escapeHtml(row.rwy_id) + '</span>' + closedHtml + '</div>'
            + '<div class="runway-hybrid-desktop-only">'
            + '<div class="runway-hybrid-meta"><span class="label">Dimensions</span><span>' + dim + '</span></div>'
            + '<div class="runway-hybrid-meta"><span class="label">Surface</span><span>' + surface + '</span></div>'
            + '<div class="runway-hybrid-meta"><span class="label">Lights</span><span>' + lights + '</span></div>'
            + '<div class="runway-hybrid-winds runway-style-winds">' + windHtml + '</div>'
            + traffic
            + '</div>'
            + '<div class="runway-hybrid-mobile-only">'
            + '<span class="runway-hybrid-dim">' + dim + '</span>'
            + '<div class="runway-hybrid-winds runway-style-winds">' + windHtml + '</div>'
            + traffic
            + specsHtml
            + '</div>'
            + '</div>';
    }

    function renderRunwayDisplay(weather) {
        const section = document.getElementById('runway-display-section');
        const list = document.getElementById('runway-display-list');
        if (!section || !list) {
            return;
        }

        const payload = weather && weather.runway_display;
        const runways = payload && Array.isArray(payload.runways) ? payload.runways : [];
        if (runways.length === 0) {
            section.hidden = true;
            list.innerHTML = '';
            return;
        }

        list.innerHTML = runways.map(function (row) {
            return runwayCardHtml(row, weather || {});
        }).join('');
        section.hidden = false;
    }

    if (typeof window !== 'undefined') {
        window.AviationWX = window.AviationWX || {};
        window.AviationWX.renderRunwayDisplay = renderRunwayDisplay;
        window.AviationWX.isRunwayWindReady = isRunwayWindReady;
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = {
            isRunwayWindReady: isRunwayWindReady,
            isCalmWindSpeed: isCalmWindSpeed,
            headwindKts: headwindKts,
            signedCrosswindKts: signedCrosswindKts,
            alongRunwayWindArrow: alongRunwayWindArrow,
            crosswindDriftArrow: crosswindDriftArrow,
            alongRunwayWindCssClass: alongRunwayWindCssClass,
            windComponentShowsDirectionalArrow: windComponentShowsDirectionalArrow,
            formatWindComponentArrow: formatWindComponentArrow,
            runwayEndWindDisplay: runwayEndWindDisplay,
            MISSING: MISSING
        };
    }
})();
