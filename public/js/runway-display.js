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
        if (window.AviationWX && typeof window.AviationWX.isCalmWindSpeed === 'function') {
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

    function windFromWeather(weather) {
        const wdObj = weather && weather.wind_direction;
        if (wdObj && typeof wdObj === 'object' && wdObj.magnetic_north != null) {
            return wdObj.magnetic_north;
        }
        return weather && weather.wind_direction_magnetic != null ? weather.wind_direction_magnetic : null;
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
        const windFrom = calm ? 0 : (windFromWeather(weather) != null ? Number(windFromWeather(weather)) : 0);
        const windSpeed = calm ? 0 : (weather.wind_speed != null ? Number(weather.wind_speed) : 0);
        const unit = windUnitLabel();
        let hw = 0;
        let xwSigned = 0;
        if (heading != null && Number.isFinite(heading)) {
            hw = headwindKts(windFrom, windSpeed, heading);
            xwSigned = signedCrosswindKts(windFrom, windSpeed, heading);
        }
        const hwArrow = hw >= 0 ? '\u2191' : '\u2193';
        const hwClass = hw >= 0 ? 'rwy-comp-hw' : 'rwy-comp-tw';
        const hwVal = formatWindKts(Math.abs(hw));
        const xwVal = formatWindKts(Math.abs(xwSigned));
        const xwArrow = xwSigned >= 0 ? '\u2190' : '\u2192';
        return ''
            + '<div class="runway-hybrid-wind-row runway-dense-end">'
            + '<span class="runway-hybrid-end-id runway-dense-end-id">' + escapeHtml(end.end_id) + '</span>'
            + '<span class="runway-dense-end-id">:</span> '
            + '<span class="' + hwClass + '">' + hwArrow + ' ' + hwVal + ' ' + unit + '</span> '
            + '<span class="rwy-comp-xw">' + xwArrow + ' ' + xwVal + ' ' + unit + '</span>'
            + calmWindEndTags(end, calm)
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
        const windHtml = ends.map(function (end) {
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

    window.AviationWX = window.AviationWX || {};
    window.AviationWX.renderRunwayDisplay = renderRunwayDisplay;
})();
