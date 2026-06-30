/**
 * Weather timestamp utilities -- safety-critical unit tests (JavaScript)
 *
 * Pilots rely on "Last updated" to judge observation recency. The airport UI uses
 * observation times (METAR/sensor obs, field map) rather than fetch/cache times when available.
 *
 * Product policy and field meanings: docs/DATA_FLOW.md#airport-last-updated-observation-vs-fetch-time
 *
 * Run with: node tests/js/weather-timestamp-utils.test.js
 */

const {
    pickFetchUnixTimestamp,
    pickObservationUnixTimestamp,
    pickOnFieldObservationUnixTimestamp,
    pickWeatherUnixTimestamp,
    stripSupplementalMetarTimestampMetadata,
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

    test('pickWeatherUnixTimestamp: null input returns null', () => {
        assertNull(pickWeatherUnixTimestamp(null), 'null');
    });

    test('pickWeatherUnixTimestamp: undefined input returns null', () => {
        assertNull(pickWeatherUnixTimestamp(undefined), 'undefined');
    });

    test('pickWeatherUnixTimestamp: non-object input returns null', () => {
        assertNull(pickWeatherUnixTimestamp('x'), 'string');
        assertNull(pickWeatherUnixTimestamp(42), 'number');
    });

    test('pickWeatherUnixTimestamp: empty object returns null', () => {
        assertNull(pickWeatherUnixTimestamp({}), 'empty');
    });

    test('pickWeatherUnixTimestamp: only last_updated (positive) is returned', () => {
        const t = 1_700_000_000;
        assertStrictEqual(pickWeatherUnixTimestamp({ last_updated: t }), t, 'last_updated');
    });

    test('pickWeatherUnixTimestamp: missing aggregate last_updated uses max of other fields', () => {
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

    test('pickObservationUnixTimestamp ignores newer fetch when observation is older', () => {
        const obs = 1_700_000_000;
        const fetchTs = 1_700_000_900;
        assertStrictEqual(
            pickObservationUnixTimestamp({
                obs_time_metar: obs,
                last_updated_metar: fetchTs,
                last_updated: fetchTs
            }),
            obs,
            'prefer obs over fetch'
        );
    });

    test('pickObservationUnixTimestamp: max across obs_time and field map', () => {
        assertStrictEqual(
            pickObservationUnixTimestamp({
                obs_time_metar: 100,
                _field_obs_time_map: { temperature: 150, wind_speed: 120 }
            }),
            150,
            'max observation'
        );
    });

    test('REGRESSION: pickObservationUnixTimestamp uses _field_obs_time_map without scalar obs_time_*', () => {
        const obsFromMap = 1_700_000_100;
        const fetchNewer = 1_700_000_900;
        assertStrictEqual(
            pickObservationUnixTimestamp({
                obs_time_primary: null,
                obs_time_metar: null,
                _field_obs_time_map: { temperature: obsFromMap },
                last_updated: fetchNewer,
                last_updated_primary: fetchNewer
            }),
            obsFromMap,
            'field map only still observation-first'
        );
    });

    test('REGRESSION: pickObservationUnixTimestamp ignores non-scalar map entries', () => {
        assertStrictEqual(
            pickObservationUnixTimestamp({
                _field_obs_time_map: {
                    nested: { not: 'a unix second' },
                    arr: [1_700_000_000],
                    good: 1_700_000_050
                },
                last_updated: 1_800_000_000
            }),
            1_700_000_050,
            'only coercible scalars count'
        );
    });

    test('REGRESSION: lastUpdatedDateFromWeather matches map-only observation', () => {
        const t = 1_700_000_200;
        const d = lastUpdatedDateFromWeather({
            _field_obs_time_map: { wind_speed: t },
            last_updated: 1_900_000_000
        });
        if (d === null || !Number.isFinite(d.getTime())) {
            throw new Error('expected valid Date');
        }
        assertStrictEqual(d.getTime(), t * 1000, 'Date from field map obs');
    });

    test('pickObservationUnixTimestamp falls back to fetch when no observation metadata', () => {
        assertStrictEqual(
            pickObservationUnixTimestamp({
                last_updated_metar: 1_800_000_000,
                last_updated: 1_800_000_000
            }),
            1_800_000_000,
            'fetch fallback'
        );
    });

    test('pickFetchUnixTimestamp only uses last_updated*', () => {
        assertStrictEqual(
            pickFetchUnixTimestamp({
                last_updated_metar: 50,
                obs_time_metar: 999
            }),
            50,
            'ignores obs'
        );
    });

    test('s33-like: legacy pick max includes fetch; UI uses observation', () => {
        const w = {
            wind_speed: 0,
            last_updated_primary: null,
            last_updated_metar: 1_774_722_052,
            last_updated: 1_774_722_052,
            obs_time_metar: 1_774_721_700
        };
        assertStrictEqual(pickWeatherUnixTimestamp(w), 1_774_722_052, 'max includes fetch');
        assertStrictEqual(pickObservationUnixTimestamp(w), 1_774_721_700, 'observation only');
        const d = lastUpdatedDateFromWeather(w);
        if (d === null || !Number.isFinite(d.getTime())) {
            throw new Error('expected valid Date');
        }
        assertStrictEqual(d.getTime(), 1_774_721_700 * 1000, 'UI matches observation');
    });

    test('numeric string last_updated is coerced (pickWeatherUnixTimestamp)', () => {
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

    test('pickWeatherUnixTimestamp: all scalar candidates returns maximum', () => {
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

    test('pickWeatherUnixTimestamp: includes _field_obs_time_map in max', () => {
        assertStrictEqual(
            pickWeatherUnixTimestamp({
                last_updated: 100,
                obs_time_metar: 200,
                _field_obs_time_map: { temperature: 400 }
            }),
            400,
            'field map max'
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

    test('lastUpdatedDateFromWeather returns null when no timestamps', () => {
        assertNull(lastUpdatedDateFromWeather({}), 'empty');
    });

    test('lastUpdatedDateFromWeather returns Date with finite getTime()', () => {
        const d = lastUpdatedDateFromWeather({ last_updated: 1_700_000_000 });
        if (d === null || typeof d.getTime !== 'function' || !Number.isFinite(d.getTime())) {
            throw new Error('expected finite Date');
        }
    });

    test('pickOnFieldObservationUnixTimestamp ignores supplemental METAR metadata', () => {
        const onField = 1_700_000_000;
        const supplemental = 1_700_000_900;
        const payload = {
            obs_time_primary: onField,
            last_updated_primary: onField,
            obs_time_metar: supplemental,
            last_updated_metar: supplemental,
            _field_obs_time_map: { wind_speed: supplemental, temperature: onField },
            _field_source_map: { wind_speed: 'metar', temperature: 'tempest' },
        };
        stripSupplementalMetarTimestampMetadata(payload);
        assertStrictEqual(
            pickOnFieldObservationUnixTimestamp(payload),
            onField,
            'max on-field after strip'
        );
        assertNull(payload.obs_time_metar, 'metar obs stripped');
        assertNull(payload.last_updated_metar, 'metar fetch stripped');
    });

    test('stripSupplementalMetarTimestampMetadata fail-closed when _field_source_map is missing', () => {
        const supplemental = 1_700_000_900;
        const payload = {
            obs_time_metar: supplemental,
            last_updated_metar: supplemental,
            _field_obs_time_map: { wind_speed: supplemental },
        };
        stripSupplementalMetarTimestampMetadata(payload);
        assertStrictEqual(
            Object.keys(payload._field_obs_time_map).length,
            0,
            'per-field map cleared when sources unknown'
        );
        assertNull(payload.obs_time_metar, 'metar obs stripped');
        assertStrictEqual(
            pickObservationUnixTimestamp(payload),
            null,
            'scrubbed payload must not use supplemental per-field time'
        );
    });

    test('stripSupplementalMetarTimestampMetadata drops fields with missing source attribution', () => {
        const supplemental = 1_700_000_900;
        const onField = 1_700_000_000;
        const payload = {
            obs_time_primary: onField,
            _field_obs_time_map: { wind_speed: supplemental, temperature: onField },
            _field_source_map: { temperature: 'tempest' },
        };
        stripSupplementalMetarTimestampMetadata(payload);
        assertStrictEqual(
            pickOnFieldObservationUnixTimestamp(payload),
            onField,
            'only attributed on-field entries remain'
        );
        assertStrictEqual(
            Object.keys(payload._field_obs_time_map).length,
            1,
            'unattributed field removed'
        );
    });

    test('pickObservationUnixTimestamp uses on-field time when supplemental METAR metadata is scrubbed', () => {
        const onField = 1_700_000_000;
        const supplemental = 1_700_000_900;
        assertStrictEqual(
            pickObservationUnixTimestamp({
                obs_time_primary: onField,
                last_updated_primary: onField,
                last_updated: onField,
                obs_time_metar: null,
                last_updated_metar: null,
                _field_obs_time_map: {},
            }),
            onField,
            'on-field only after supplemental scrub'
        );
        assertStrictEqual(
            pickObservationUnixTimestamp({
                obs_time_primary: onField,
                last_updated_primary: onField,
                obs_time_metar: supplemental,
                _field_obs_time_map: { wind_speed: supplemental },
                _field_source_map: { wind_speed: 'metar' },
            }),
            supplemental,
            'unscrubbed still prefers supplemental obs'
        );
    });

    console.log('\n' + '='.repeat(50));
    console.log(`Results: ${passed} passed, ${failed} failed\n`);
    if (failed > 0) {
        process.exit(1);
    }
}

runTests();
