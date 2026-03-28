/**
 * Weather timestamp utilities -- safety-critical unit tests (JavaScript)
 *
 * Pilots rely on "Last updated" to judge observation recency. pickWeatherUnixTimestamp
 * must return a consistent Unix second from cache/API payloads that may omit
 * aggregate last_updated but include source-specific times. lastUpdatedDateFromWeather
 * must never yield an invalid Date for UI formatting.
 *
 * Regression: embedded PHP cache and API JSON may use numeric strings; init order must
 * not depend on wind canvas (covered by lastUpdatedDateFromWeather contract tests).
 *
 * Run with: node tests/js/weather-timestamp-utils.test.js
 */

const {
    pickWeatherUnixTimestamp,
    lastUpdatedDateFromWeather
} = require('../../public/js/weather-timestamp-utils.js');

let passed = 0;
let failed = 0;

function test(name, fn) {
    try {
        fn();
        console.log(`  ✓ ${name}`);
        passed++;
        return true;
    } catch (e) {
        console.error(`  ✗ ${name}`);
        console.error(`    ${e.message}`);
        failed++;
        return false;
    }
}

function assertStrictEqual(actual, expected, message) {
    if (actual !== expected) {
        throw new Error(`${message}: expected ${expected}, got ${actual}`);
    }
}

function assertNull(actual, message) {
    if (actual !== null) {
        throw new Error(`${message}: expected null, got ${actual}`);
    }
}

function runTests() {
    console.log('\nWeather timestamp utilities (safety-critical)\n' + '='.repeat(50));

    test('null input returns null', () => {
        assertNull(pickWeatherUnixTimestamp(null), 'null');
    });

    test('undefined input returns null', () => {
        assertNull(pickWeatherUnixTimestamp(undefined), 'undefined');
    });

    test('non-object input returns null', () => {
        assertNull(pickWeatherUnixTimestamp('x'), 'string');
        assertNull(pickWeatherUnixTimestamp(42), 'number');
    });

    test('empty object returns null', () => {
        assertNull(pickWeatherUnixTimestamp({}), 'empty');
    });

    test('only last_updated (positive) is returned', () => {
        const t = 1_700_000_000;
        assertStrictEqual(pickWeatherUnixTimestamp({ last_updated: t }), t, 'last_updated');
    });

    test('only last_updated_primary is returned', () => {
        const t = 1_700_000_100;
        assertStrictEqual(pickWeatherUnixTimestamp({ last_updated_primary: t }), t, 'primary');
    });

    test('only last_updated_metar is returned', () => {
        const t = 1_700_000_200;
        assertStrictEqual(pickWeatherUnixTimestamp({ last_updated_metar: t }), t, 'metar');
    });

    test('only obs_time_metar is returned', () => {
        const t = 1_700_000_300;
        assertStrictEqual(pickWeatherUnixTimestamp({ obs_time_metar: t }), t, 'obs metar');
    });

    test('only obs_time_primary is returned', () => {
        const t = 1_700_000_400;
        assertStrictEqual(pickWeatherUnixTimestamp({ obs_time_primary: t }), t, 'obs primary');
    });

    test('missing aggregate last_updated uses max of other fields (s33-style cache)', () => {
        const a = 1_700_000_500;
        const b = 1_700_000_800;
        assertStrictEqual(
            pickWeatherUnixTimestamp({
                last_updated_primary: a,
                last_updated_metar: b
            }),
            b,
            'max of primary vs metar'
        );
    });

    test('s33-like embedded cache: numbers only still resolves', () => {
        const w = {
            wind_speed: 0,
            last_updated_primary: null,
            last_updated_metar: 1_774_722_052,
            last_updated: 1_774_722_052,
            obs_time_metar: 1_774_721_700
        };
        assertStrictEqual(pickWeatherUnixTimestamp(w), 1_774_722_052, 'max timestamp');
        const d = lastUpdatedDateFromWeather(w);
        if (d === null || !Number.isFinite(d.getTime())) {
            throw new Error('expected valid Date from s33-like payload');
        }
    });

    test('numeric string last_updated is coerced (JSON/PHP edge shapes)', () => {
        assertStrictEqual(
            pickWeatherUnixTimestamp({ last_updated: '1700000000' }),
            1_700_000_000,
            'string coerced'
        );
    });

    test('whitespace around numeric string is trimmed', () => {
        assertStrictEqual(
            pickWeatherUnixTimestamp({ last_updated_metar: '  1700000000  ' }),
            1_700_000_000,
            'trim'
        );
    });

    test('non-numeric string timestamps are ignored', () => {
        assertNull(
            pickWeatherUnixTimestamp({ last_updated: 'not-a-number' }),
            'text'
        );
        assertNull(
            pickWeatherUnixTimestamp({ last_updated: '1700000000.5' }),
            'decimal string'
        );
    });

    test('absurd string magnitude is ignored', () => {
        assertNull(
            pickWeatherUnixTimestamp({ last_updated: '50000000000000000000' }),
            'overflow string'
        );
    });

    test('all candidates present: returns maximum (freshest)', () => {
        assertStrictEqual(
            pickWeatherUnixTimestamp({
                last_updated: 100,
                last_updated_primary: 300,
                last_updated_metar: 200,
                obs_time_metar: 250,
                obs_time_primary: 150
            }),
            300,
            'max'
        );
    });

    test('zeros and negatives are ignored', () => {
        assertStrictEqual(
            pickWeatherUnixTimestamp({
                last_updated: 0,
                last_updated_primary: -1,
                last_updated_metar: 1_800_000_000
            }),
            1_800_000_000,
            'only positive counts'
        );
    });

    test('NaN and Infinity are ignored', () => {
        assertStrictEqual(
            pickWeatherUnixTimestamp({
                last_updated: NaN,
                last_updated_primary: Infinity,
                last_updated_metar: 1_900_000_000
            }),
            1_900_000_000,
            'finite only'
        );
    });

    test('lastUpdatedDateFromWeather returns null when pick returns null', () => {
        assertNull(lastUpdatedDateFromWeather({}), 'empty');
    });

    test('lastUpdatedDateFromWeather returns Date with finite getTime()', () => {
        const d = lastUpdatedDateFromWeather({ last_updated: 1_700_000_000 });
        if (d === null || typeof d.getTime !== 'function' || !Number.isFinite(d.getTime())) {
            throw new Error('expected finite Date');
        }
    });

    console.log('\n' + '='.repeat(50));
    console.log(`Results: ${passed} passed, ${failed} failed\n`);
    if (failed > 0) {
        process.exit(1);
    }
}

runTests();
