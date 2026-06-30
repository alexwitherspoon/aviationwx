/**
 * Wind calm vs unavailable (safety-critical display)
 *
 * Null/missing wind must not render as CALM on the compass. Matches embed card policy.
 *
 * Run with: node tests/js/wind-calm-policy.test.js
 */

const { isCalmWindSpeed, CALM_WIND_THRESHOLD } = require('../../public/js/wind-visual.js');

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

console.log('\nWind calm policy (safety-critical)\n' + '='.repeat(50));

test('null wind speed is not calm', () => {
    assert(!isCalmWindSpeed(null), 'null must not be calm');
});

test('undefined wind speed is not calm', () => {
    assert(!isCalmWindSpeed(undefined), 'undefined must not be calm');
});

test('zero knots is calm when present', () => {
    assert(isCalmWindSpeed(0), '0 kts is calm');
});

test('below threshold is calm', () => {
    assert(isCalmWindSpeed(CALM_WIND_THRESHOLD - 0.1), '2.9 kts is calm');
});

test('at or above threshold is not calm', () => {
    assert(!isCalmWindSpeed(CALM_WIND_THRESHOLD), '3 kts is not calm');
    assert(!isCalmWindSpeed(10), '10 kts is not calm');
});

console.log('\n' + '='.repeat(50));
console.log(`Results: ${passed} passed, ${failed} failed\n`);
if (failed > 0) {
    process.exit(1);
}
