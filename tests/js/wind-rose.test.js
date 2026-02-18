/**
 * Wind Rose Safety-Critical Tests (JavaScript)
 *
 * SAFETY OF FLIGHT: Wind rose petals display last-hour wind distribution.
 * Sector-to-canvas angle mapping must be correct for proper cardinal alignment.
 *
 * Reference: WMO/ICAO - wind direction FROM. Canvas: 0 = E, North = -90°.
 * Sectors: N=0, NE=1, E=2, SE=3, S=4, SW=5, W=6, NW=7.
 *
 * Run with: node tests/js/wind-rose.test.js
 */

const { getSectorCanvasAngles, isValidLastHourWind } = require('../../public/js/wind-rose-utils.js');

const DEG2RAD = Math.PI / 180;

function assertEqualsWithDelta(actual, expected, delta, message) {
    if (Math.abs(actual - expected) > delta) {
        throw new Error(
            `${message}: expected ${expected} ± ${delta}, got ${actual} (diff: ${Math.abs(actual - expected)})`
        );
    }
}

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

// ============================================================================
// SECTOR-TO-CANVAS ANGLE - Reference values
// ============================================================================

function runTests() {
    console.log('\nWind Rose Safety Tests\n' + '='.repeat(50));

    // Sector 0 (N): center should be -90° = -Math.PI/2
    test('Sector 0 (N): center = -90° (canvas north)', () => {
        const { center } = getSectorCanvasAngles(0);
        assertEqualsWithDelta(center, -Math.PI / 2, 0.001, 'N sector center');
    });

    // Sector 4 (E): center should be 0° (canvas east)
    test('Sector 4 (E): center = 0° (canvas east)', () => {
        const { center } = getSectorCanvasAngles(4);
        assertEqualsWithDelta(center, 0, 0.001, 'E sector center');
    });

    // Sector 8 (S): center should be 90° = Math.PI/2
    test('Sector 8 (S): center = 90° (canvas south)', () => {
        const { center } = getSectorCanvasAngles(8);
        assertEqualsWithDelta(center, Math.PI / 2, 0.001, 'S sector center');
    });

    // Sector 12 (W): center should be 180° = Math.PI
    test('Sector 12 (W): center = 180° (canvas west)', () => {
        const { center } = getSectorCanvasAngles(12);
        assertEqualsWithDelta(center, Math.PI, 0.001, 'W sector center');
    });

    // Sector 2 (NE): center = 45° met -> canvas -45°
    test('Sector 2 (NE): center = -45°', () => {
        const { center } = getSectorCanvasAngles(2);
        assertEqualsWithDelta(center, -45 * DEG2RAD, 0.001, 'NE sector center');
    });

    // Each sector spans 22.5°
    test('Sector span = 22.5° (11.25° each side of center)', () => {
        const { start, end, center } = getSectorCanvasAngles(0);
        const halfWidth = (22.5 / 2) * DEG2RAD;
        assertEqualsWithDelta(center - start, halfWidth, 0.001, 'Start offset');
        assertEqualsWithDelta(end - center, halfWidth, 0.001, 'End offset');
    });

    // All 16 sectors: start/end continuity
    test('All 16 sectors: valid start < center < end', () => {
        for (let i = 0; i < 16; i++) {
            const { start, center, end } = getSectorCanvasAngles(i);
            if (!(start < center && center < end)) {
                throw new Error(`Sector ${i}: start=${start} center=${center} end=${end}`);
            }
        }
    });

    // Bounds clamping: out-of-range indices
    test('Sector index -1 clamps to 0', () => {
        const { center } = getSectorCanvasAngles(-1);
        assertEqualsWithDelta(center, -Math.PI / 2, 0.001, 'Clamped to N');
    });

    test('Sector index 16 clamps to 15', () => {
        const { center } = getSectorCanvasAngles(16);
        assertEqualsWithDelta(center, (15 * 22.5 - 90) * DEG2RAD, 0.001, 'Clamped to NNW');
    });

    // ============================================================================
    // isValidLastHourWind - API validation
    // ============================================================================

    test('isValidLastHourWind: valid 16-element array', () => {
        const valid = Array(16).fill(0).map((_, i) => i);
        if (!isValidLastHourWind(valid)) throw new Error('Valid array rejected');
    });

    test('isValidLastHourWind: rejects non-array', () => {
        if (isValidLastHourWind(null)) throw new Error('null should be rejected');
        if (isValidLastHourWind({})) throw new Error('object should be rejected');
    });

    test('isValidLastHourWind: rejects wrong length', () => {
        if (isValidLastHourWind([1, 2, 3])) throw new Error('length 3 should be rejected');
        if (isValidLastHourWind(Array(8).fill(0))) throw new Error('length 8 should be rejected');
    });

    test('isValidLastHourWind: rejects negative values', () => {
        const arr = Array(16).fill(0);
        arr[15] = -1;
        if (isValidLastHourWind(arr)) throw new Error('Negative value should be rejected');
    });

    test('isValidLastHourWind: rejects NaN', () => {
        const arr = Array(16).fill(0);
        arr[15] = NaN;
        if (isValidLastHourWind(arr)) throw new Error('NaN should be rejected');
    });
}

// Run and report
runTests();

console.log(`\nResults: ${passed} passed, ${failed} failed`);
console.log('='.repeat(50) + '\n');

if (typeof process !== 'undefined') {
    process.exit(failed > 0 ? 1 : 0);
}
