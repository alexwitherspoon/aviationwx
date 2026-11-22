/**
 * Runway compass segment point mapping (curved + straight runway rendering)
 *
 * A curved runway carries sampled Bezier points and must map every point into
 * compass space; a straight runway maps just its two ends. Missing or
 * degenerate curve data must fall back to the two-end line, never a mangled
 * draw. This is display logic, so be precise about the y-flip (canvas y grows
 * downward, compass north is up).
 *
 * Run with: node tests/js/wind-runway-curve.test.js
 */

const { runwaySegmentCompassPoints } = require('../../public/js/wind-visual.js');

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

const CX = 150;
const CY = 150;
const RW = 90;

console.log('\nRunway compass segment points (curved + straight)\n' + '='.repeat(55));

test('straight segment maps to its two ends', () => {
    const seg = { start: [-0.5, 0.3], end: [0.5, -0.3] };
    const pts = runwaySegmentCompassPoints(seg, CX, CY, RW);

    assert(pts.length === 2, 'straight segment maps to exactly two points');
    assert(Math.abs(pts[0][0] - (CX + RW * -0.5)) < 1e-9, 'start x wrong');
    assert(Math.abs(pts[0][1] - (CY - RW * 0.3)) < 1e-9, 'start y wrong (y-flip)');
    assert(Math.abs(pts[1][0] - (CX + RW * 0.5)) < 1e-9, 'end x wrong');
    assert(Math.abs(pts[1][1] - (CY - RW * -0.3)) < 1e-9, 'end y wrong (y-flip)');
});

test('curved segment maps every Bezier point', () => {
    const seg = {
        start: [-0.9, 0.0],
        end: [0.9, 0.0],
        curve_points: [
            [-0.9, 0.0],
            [-0.4, 0.3],
            [0.4, 0.3],
            [0.9, 0.0],
        ],
    };
    const pts = runwaySegmentCompassPoints(seg, CX, CY, RW);

    assert(pts.length === 4, 'curved segment maps to curve point count');
    assert(Math.abs(pts[0][0] - (CX + RW * -0.9)) < 1e-9, 'curve start x wrong');
    assert(Math.abs(pts[1][1] - (CY - RW * 0.3)) < 1e-9, 'interior point y wrong (y-flip)');
    assert(Math.abs(pts[3][0] - (CX + RW * 0.9)) < 1e-9, 'curve end x wrong');
});

test('curve with fewer than two points falls back to straight ends', () => {
    const seg = { start: [-0.5, 0.0], end: [0.5, 0.0], curve_points: [[0.0, 0.0]] };
    const pts = runwaySegmentCompassPoints(seg, CX, CY, RW);

    assert(pts.length === 2, 'degenerate curve falls back to two-end line');
    assert(Math.abs(pts[0][0] - (CX + RW * -0.5)) < 1e-9, 'fallback start x wrong');
    assert(Math.abs(pts[1][0] - (CX + RW * 0.5)) < 1e-9, 'fallback end x wrong');
});

test('missing fields default to origin defensively', () => {
    const pts = runwaySegmentCompassPoints({}, CX, CY, RW);

    assert(pts.length === 2, 'empty segment maps to two points at origin');
    assert(pts[0][0] === CX && pts[0][1] === CY, 'start defaults to canvas center');
    assert(pts[1][0] === CX && pts[1][1] === CY, 'end defaults to canvas center');
});

if (failed > 0) {
    console.error(`\n❌ ${failed} failed, ${passed} passed\n`);
    process.exit(1);
} else {
    console.log(`\n✔ ${passed} passed\n`);
}