/**
 * Status page: sum sparse UTC hourly buckets into the viewer's local calendar day.
 *
 * Used by pages/status.php. Airport ids must be lowercase (matches server sparse maps).
 *
 * @see METRICS_STATUS_HOURLY_PROFILE_SCHEMA_VERSION in PHP
 */
(function (global) {
    'use strict';

    /**
     * UTC hour bucket bounds from metrics hour id (YYYY-MM-DD-HH).
     *
     * @param {string} hourId
     * @returns {{start: number, end: number}}
     */
    function hourIdToUtcRangeMs(hourId) {
        const p = String(hourId).split('-');
        if (p.length !== 4) {
            return { start: NaN, end: NaN };
        }
        const y = parseInt(p[0], 10);
        const mo = parseInt(p[1], 10);
        const d = parseInt(p[2], 10);
        const h = parseInt(p[3], 10);
        const start = Date.UTC(y, mo - 1, d, h, 0, 0);
        return { start: start, end: start + 3600000 };
    }

    /**
     * Start of local calendar day containing nowMs (binary search).
     * Lower bound covers local midnight even when far behind UTC (52h window).
     *
     * @param {string} timeZone IANA zone
     * @param {number} nowMs
     * @returns {number}
     */
    function startOfLocalCalendarDayMs(timeZone, nowMs) {
        const fmt = new Intl.DateTimeFormat('en-CA', {
            timeZone: timeZone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit'
        });
        const today = fmt.format(new Date(nowMs));
        let lo = nowMs - 52 * 3600000;
        let hi = nowMs + 1;
        while (lo < hi) {
            const mid = Math.floor((lo + hi) / 2);
            if (fmt.format(new Date(mid)) === today) {
                hi = mid;
            } else {
                lo = mid + 1;
            }
        }
        return lo;
    }

    /**
     * @param {string} hourId
     * @param {number} localStartMs
     * @param {number} localEndMs
     * @returns {boolean}
     */
    function utcHourOverlapsLocalWindow(hourId, localStartMs, localEndMs) {
        const r = hourIdToUtcRangeMs(hourId);
        if (Number.isNaN(r.start)) {
            return false;
        }
        return r.start < localEndMs && r.end > localStartMs;
    }

    /**
     * @param {object} profile hourly_profile payload from server
     * @param {string} airportId lowercase airport id
     * @param {string} timeZone IANA zone
     * @param {number} [nowMs]
     * @param {number} [dayStartMsOpt] If set, skips recomputing {@link startOfLocalCalendarDayMs} (batch DOM updates)
     * @returns {number}
     */
    function sumLocalDayViewsForAirport(profile, airportId, timeZone, nowMs, dayStartMsOpt) {
        const end = typeof nowMs === 'number' ? nowMs : Date.now();
        const dayStart =
            typeof dayStartMsOpt === 'number'
                ? dayStartMsOpt
                : startOfLocalCalendarDayMs(timeZone, end);
        const rows = profile && profile.hours ? profile.hours : [];
        let sum = 0;
        for (let i = 0; i < rows.length; i++) {
            const row = rows[i];
            if (!row || !row.hour_id) {
                continue;
            }
            if (!utcHourOverlapsLocalWindow(row.hour_id, dayStart, end)) {
                continue;
            }
            const v = row.views && row.views[airportId] ? row.views[airportId] : 0;
            sum += typeof v === 'number' ? v : 0;
        }
        return sum;
    }

    /**
     * Resolve IANA timezone for Intl (fallback UTC).
     *
     * @returns {string}
     */
    function resolvedTimeZone() {
        try {
            return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
        } catch {
            return 'UTC';
        }
    }

    const TITLE_STALE =
        'Metrics cache not warmed yet (scheduler will populate). Local sum unavailable until hourly buckets load.';
    const TITLE_OK =
        'Your calendar day in %TZ%, summed from UTC hour buckets on the server. Current UTC hour uses the same live partial totals as /hour.';

    /**
     * Update .views-local-calendar-day nodes from hourly_profile.
     *
     * @param {object|null} profile
     * @returns {void}
     */
    function applyLocalCalendarDayViewsToDom(profile) {
        const nodes = document.querySelectorAll('.views-local-calendar-day[data-airport]');
        if (!nodes.length) {
            return;
        }

        const hours = profile && profile.hours ? profile.hours : [];
        if (!hours.length) {
            const tz = resolvedTimeZone();
            for (let i = 0; i < nodes.length; i++) {
                nodes[i].classList.add('views-local-stale');
                nodes[i].textContent = '---/loc';
                nodes[i].setAttribute('title', TITLE_STALE);
                nodes[i].setAttribute('data-local-tz', tz);
            }
            return;
        }

        const tz = resolvedTimeZone();
        const nowMs = Date.now();
        const dayStartMs = startOfLocalCalendarDayMs(tz, nowMs);
        for (let i = 0; i < nodes.length; i++) {
            const el = nodes[i];
            el.classList.remove('views-local-stale');
            const aid = el.getAttribute('data-airport');
            if (!aid) {
                continue;
            }
            const n = sumLocalDayViewsForAirport(profile, aid, tz, nowMs, dayStartMs);
            el.textContent = n.toLocaleString() + '/loc';
            el.setAttribute('title', TITLE_OK.replace('%TZ%', tz));
            el.setAttribute('data-local-tz', tz);
        }
    }

    const api = {
        hourIdToUtcRangeMs: hourIdToUtcRangeMs,
        startOfLocalCalendarDayMs: startOfLocalCalendarDayMs,
        utcHourOverlapsLocalWindow: utcHourOverlapsLocalWindow,
        sumLocalDayViewsForAirport: sumLocalDayViewsForAirport,
        resolvedTimeZone: resolvedTimeZone,
        applyLocalCalendarDayViewsToDom: applyLocalCalendarDayViewsToDom
    };

    /* eslint-disable no-undef -- Node runs tests via module.exports; browser has no module */
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        global.AviationWX = global.AviationWX || {};
        global.AviationWX.statusLocalCalendar = api;
    }
    /* eslint-enable no-undef */
})(typeof window !== 'undefined' ? window : globalThis);
