/**
 * Wind compass resize utility tests (mocked, CI-safe).
 *
 * Run with: node tests/js/wind-compass-resize.test.js
 */

const {
    MIN_CSS_SIZE,
    MAX_CSS_SIZE,
    resolveWindCompassCssSize,
    computeWindCompassPixelSize,
} = require('../../public/js/wind-compass-resize-utils.js');

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

function assertEqual(actual, expected, message) {
    if (actual !== expected) {
        throw new Error(`${message}: expected ${expected}, got ${actual}`);
    }
}

console.log('\nWind Compass Resize Tests\n' + '='.repeat(50));

test('resolveWindCompassCssSize clamps to min/max', () => {
    assertEqual(resolveWindCompassCssSize(20, 200), MIN_CSS_SIZE, 'min clamp');
    assertEqual(resolveWindCompassCssSize(400, 200), MAX_CSS_SIZE, 'max clamp');
    assertEqual(resolveWindCompassCssSize(180, 200), 180, 'in-range');
});

test('resolveWindCompassCssSize falls back when width is invalid', () => {
    assertEqual(resolveWindCompassCssSize(0, 200), 200, 'zero width');
    assertEqual(resolveWindCompassCssSize(NaN, 220), 220, 'NaN width');
});

test('computeWindCompassPixelSize honors devicePixelRatio', () => {
    const result = computeWindCompassPixelSize(200, 2);
    assertEqual(result.cssSize, 200, 'css size');
    assertEqual(result.pixelSize, 400, 'pixel size');
});

test('computeWindCompassPixelSize defaults DPR to 1', () => {
    const result = computeWindCompassPixelSize(160, 0);
    assertEqual(result.pixelSize, 160, 'pixel size without DPR');
});

console.log('\n' + '='.repeat(50));
console.log(`Results: ${passed} passed, ${failed} failed`);
process.exit(failed > 0 ? 1 : 0);
