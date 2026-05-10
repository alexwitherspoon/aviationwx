/**
 * Status page local calendar view sums (JavaScript)
 *
 * Run: node tests/js/status-local-calendar.test.js
 */

const {
    hourIdToUtcRangeMs,
    startOfLocalCalendarDayMs,
    utcHourOverlapsLocalWindow,
    sumLocalDayViewsForAirport,
    resolvedTimeZone
} = require('../../public/js/status-local-calendar.js');

let passed = 0;
let failed = 0;

function test(name, fn) {
    try {
        fn();
        console.log(`  ✓ ${name}`);
        passed++;
    } catch (e) {
        console.error(`  ✗ ${name}`);
        console.error(`    ${e.message}`);
        failed++;
    }
}

function assertStrictEqual(actual, expected, message) {
    if (actual !== expected) {
        throw new Error(`${message}: expected ${expected}, got ${actual}`);
    }
}

function runTests() {
    console.log('\nStatus local calendar (hour buckets → local day)\n' + '='.repeat(50));

    test('hourIdToUtcRangeMs: parses metrics hour id', () => {
        const r = hourIdToUtcRangeMs('2025-06-15-14');
        assertStrictEqual(r.end - r.start, 3600000, 'one hour');
        assertStrictEqual(r.start, Date.UTC(2025, 5, 15, 14, 0, 0), 'start');
    });

    test('hourIdToUtcRangeMs: invalid id yields NaN start', () => {
        const r = hourIdToUtcRangeMs('bad');
        assertStrictEqual(Number.isNaN(r.start), true, 'nan');
    });

    test('startOfLocalCalendarDayMs: America/Los_Angeles is consistent with en-CA day', () => {
        const tz = 'America/Los_Angeles';
        const nowMs = Date.UTC(2025, 5, 15, 20, 0, 0);
        const start = startOfLocalCalendarDayMs(tz, nowMs);
        const fmt = new Intl.DateTimeFormat('en-CA', {
            timeZone: tz,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit'
        });
        assertStrictEqual(fmt.format(new Date(start)), fmt.format(new Date(nowMs)), 'same calendar day');
        assertStrictEqual(start <= nowMs, true, 'start not after now');
    });

    test('utcHourOverlapsLocalWindow: overlapping boundary', () => {
        const hourStart = Date.UTC(2025, 5, 15, 10, 0, 0);
        const ok = utcHourOverlapsLocalWindow(
            '2025-06-15-10',
            hourStart + 1000,
            hourStart + 7200000
        );
        assertStrictEqual(ok, true, 'overlap');
    });

    test('sumLocalDayViewsForAirport: sums overlapping buckets only', () => {
        const tz = 'UTC';
        const fixedNow = Date.UTC(2025, 5, 15, 14, 30, 0);
        const profile = {
            hours: [
                { hour_id: '2025-06-15-13', complete: true, views: { kspb: 2 } },
                { hour_id: '2025-06-15-14', complete: false, views: { kspb: 5 } }
            ]
        };
        const sum = sumLocalDayViewsForAirport(profile, 'kspb', tz, fixedNow);
        assertStrictEqual(sum, 7, '2+5');
    });

    test('sumLocalDayViewsForAirport: precomputed dayStartMs matches one-call path', () => {
        const tz = 'UTC';
        const fixedNow = Date.UTC(2025, 5, 15, 14, 30, 0);
        const profile = {
            hours: [
                { hour_id: '2025-06-15-13', complete: true, views: { kspb: 2 } },
                { hour_id: '2025-06-15-14', complete: false, views: { kspb: 5 } }
            ]
        };
        const dayStartMs = startOfLocalCalendarDayMs(tz, fixedNow);
        const implicit = sumLocalDayViewsForAirport(profile, 'kspb', tz, fixedNow);
        const explicit = sumLocalDayViewsForAirport(profile, 'kspb', tz, fixedNow, dayStartMs);
        assertStrictEqual(explicit, implicit, 'batch path equals single-call');
        assertStrictEqual(explicit, 7, 'total unchanged');
    });

    test('resolvedTimeZone: returns a non-empty string', () => {
        const z = resolvedTimeZone();
        if (typeof z !== 'string' || z.length < 2) {
            throw new Error('expected IANA or UTC string');
        }
    });

    console.log('\n' + (failed === 0 ? 'All passed' : 'Some failed') + ` (${passed} ok, ${failed} failed)\n`);
    if (failed > 0) {
        process.exit(1);
    }
}

runTests();
