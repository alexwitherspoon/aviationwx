/**
 * Runway display wind readiness (safety-critical display)
 *
 * Missing wind must show --- for per-end HW/XW, not zero components.
 *
 * Run with: node tests/js/runway-display-wind.test.js
 */

const {
    isRunwayWindReady,
    isCalmWindSpeed,
    headwindKts,
    signedCrosswindKts,
    MISSING
} = require('../../public/js/runway-display.js');

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

function assert(condition, message) {
    if (!condition) {
        throw new Error(message);
    }
}

console.log('\nRunway display wind (safety-critical)\n' + '='.repeat(50));

test('null weather is not ready', () => {
    assert(!isRunwayWindReady(null), 'null weather must fail closed');
});

test('missing wind direction is not ready', () => {
    assert(!isRunwayWindReady({ wind_speed: 10 }), 'speed alone is insufficient');
});

test('missing wind speed is not ready', () => {
    assert(!isRunwayWindReady({ wind_direction_magnetic: 270 }), 'direction alone is insufficient');
});

test('null wind fields are not ready', () => {
    assert(!isRunwayWindReady({ wind_direction_magnetic: null, wind_speed: null }), 'null fields fail closed');
});

test('valid magnetic wind is ready', () => {
    assert(isRunwayWindReady({
        wind_direction: 285,
        wind_direction_magnetic: 270,
        wind_speed: 12,
    }), 'numeric wind is ready');
});

test('orphaned wind_direction_magnetic is not ready when wind_direction is null', () => {
    assert(!isRunwayWindReady({
        wind_direction: null,
        wind_direction_magnetic: 270,
        wind_speed: 10,
    }), 'stale direction must not use orphaned magnetic field');
});

test('public API wind_direction object is ready', () => {
    assert(isRunwayWindReady({
        wind_direction: { magnetic_north: 90 },
        wind_speed: 5
    }), 'magnetic_north object is ready');
});

test('null wind speed is not calm for tags', () => {
    assert(!isCalmWindSpeed(null), 'null must not trigger calm display');
});

test('headwind uses magnetic runway heading', () => {
    const hw = headwindKts(270, 10, 270);
    assert(Math.abs(hw - 10) < 0.01, 'direct headwind should equal wind speed');
});

test('crosswind is zero on direct headwind', () => {
    const xw = signedCrosswindKts(270, 10, 270);
    assert(Math.abs(xw) < 0.01, 'crosswind should be zero on runway heading');
});

test('missing sentinel is three dashes', () => {
    assert(MISSING === '---', 'missing display uses ---');
});

console.log('\n' + '='.repeat(50));
console.log(`Results: ${passed} passed, ${failed} failed\n`);
if (failed > 0) {
    process.exit(1);
}
