/**
 * Water runway wind compass rendering tests.
 *
 * Water runways (16W/34W) draw as a thin dashed line in a distinct water blue,
 * with labels in the water color and the W idents intact. The palette is the
 * testable contract here: the draw loop reads waterRunway/waterRunwayLabel from
 * the same getWindCompassColors() source the dashboard legend uses.
 *
 * Run with: node tests/js/wind-water-runway.test.js
 */

const { getWindCompassColors, drawWaterRunwayOutline } = require('../../public/js/wind-visual.js');

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

function assertColor(palette, theme) {
    assert(typeof palette.waterRunway === 'string' && palette.waterRunway.length > 0,
        `${theme}: waterRunway color defined`);
    assert(typeof palette.waterRunwayLabel === 'string' && palette.waterRunwayLabel.length > 0,
        `${theme}: waterRunwayLabel color defined`);
    assert(palette.waterRunway !== palette.runway,
        `${theme}: water runway color differs from paved runway color`);
}

console.log('\nWater runway compass palette\n' + '='.repeat(40));

test('light theme has a distinct water runway color', () => {
    const palette = getWindCompassColors({ isDark: false, night: false });
    assertColor(palette, 'light');
});

test('dark theme has a distinct water runway color', () => {
    const palette = getWindCompassColors({ isDark: true, night: false });
    assertColor(palette, 'dark');
});

test('night theme has a distinct water runway color', () => {
    const palette = getWindCompassColors({ isDark: false, night: true });
    assertColor(palette, 'night');
});

test('no options resolves to light palette', () => {
    const palette = getWindCompassColors();
    assertColor(palette, 'light(default)');
});

test('water outline draws a closed path at normal runway width', () => {
    const calls = [];
    const ctx = {
        beginPath: () => calls.push(['beginPath']),
        moveTo: (x, y) => calls.push(['moveTo', x, y]),
        lineTo: (x, y) => calls.push(['lineTo', x, y]),
        closePath: () => calls.push(['closePath']),
        stroke: () => calls.push(['stroke'])
    };
    // Segment along x-axis. Half-width is 4 * scale = 4 at scale 1, matching
    // the paved strip line width (8), so the waterway reads as a normal runway.
    const pts = [[0, 0], [100, 0]];
    drawWaterRunwayOutline(ctx, pts, 1);

    const moves = calls.filter(c => c[0] === 'moveTo');
    assert(moves.length === 1, 'one moveTo');
    assert(Math.abs(moves[0][2] - 4) < 0.001, 'start edge at +4 (half of runway width)');
    const lines = calls.filter(c => c[0] === 'lineTo');
    assert(lines.length === 3, 'three lineTo (outline is a rectangle)');
    assert(lines.some(l => Math.abs(l[2] - (-4)) < 0.001), 'opposite edge at -4');
    assert(calls.some(c => c[0] === 'closePath'), 'outline is closed');
    assert(calls.some(c => c[0] === 'stroke'), 'outline stroked');
});

test('degenerate segment draws nothing', () => {
    const calls = [];
    const ctx = {
        beginPath: () => calls.push(['beginPath']),
        moveTo: () => calls.push(['moveTo']),
        lineTo: () => calls.push(['lineTo']),
        closePath: () => calls.push(['closePath']),
        stroke: () => calls.push(['stroke'])
    };
    drawWaterRunwayOutline(ctx, [[0, 0], [0, 0]], 1);
    assert(calls.length === 0, 'no draw calls for zero-length segment');
});

console.log('\n' + '='.repeat(40));
console.log(`Results: ${passed} passed, ${failed} failed`);
if (failed > 0) {
    process.exit(1);
}
