/**
 * Runway per-end wind arrow semantics (aircraft-relative display).
 *
 * Arrows are from the pilot's perspective on final to that runway end:
 * - Along fuselage: down (↓) = into the wind (headwind); up (↑) = tailwind.
 * - Across fuselage: drift direction (→ right, ← left), not METAR "from".
 *
 * Run with: node tests/js/runway-display-wind-arrows.test.js
 */

const {
    headwindKts,
    signedCrosswindKts,
    alongRunwayWindArrow,
    crosswindDriftArrow,
    alongRunwayWindCssClass,
    windComponentShowsDirectionalArrow,
    formatWindComponentArrow,
    runwayEndWindDisplay,
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

function assertNear(actual, expected, tolerance, message) {
    if (Math.abs(actual - expected) > tolerance) {
        throw new Error(message || `expected ${expected}, got ${actual}`);
    }
}

console.log('\nRunway display wind arrows (aircraft-relative)\n' + '='.repeat(50));

test('along-runway: headwind points down (into the wind)', () => {
    assert(alongRunwayWindArrow(10) === '\u2193', 'positive headwind uses down arrow');
    assert(alongRunwayWindArrow(0) === '\u2193', 'zero along component uses down arrow');
});

test('along-runway: tailwind points up', () => {
    assert(alongRunwayWindArrow(-8) === '\u2191', 'tailwind uses up arrow');
});

test('along-runway: headwind is green class, tailwind is red class', () => {
    assert(alongRunwayWindCssClass(5) === 'rwy-comp-hw', 'headwind class');
    assert(alongRunwayWindCssClass(-5) === 'rwy-comp-tw', 'tailwind class');
});

test('crosswind: drift right when wind is from the left', () => {
    assert(crosswindDriftArrow(7) === '\u2192', 'positive signed crosswind drifts right');
});

test('crosswind: drift left when wind is from the right', () => {
    assert(crosswindDriftArrow(-7) === '\u2190', 'negative signed crosswind drifts left');
});

test('directional arrow suppressed when rounded magnitude is zero', () => {
    assert(!windComponentShowsDirectionalArrow(0.4), 'sub-knot crosswind hides arrow');
    assert(formatWindComponentArrow(0.4, crosswindDriftArrow) === '', 'no drift arrow for 0.4 kt');
    assert(formatWindComponentArrow(0, crosswindDriftArrow) === '', 'no drift arrow for calm');
    assert(formatWindComponentArrow(1, crosswindDriftArrow) === '\u2192', 'arrow shown at 1 kt');
});

test('along-runway arrow suppressed when rounded magnitude is zero', () => {
    assert(formatWindComponentArrow(0.4, alongRunwayWindArrow) === '', 'no along arrow for 0.4 kt');
});

test('36/18 at 045: runway 36 headwind down-green, drift left', () => {
    const display = runwayEndWindDisplay(45, 10, 360);
    assertNear(display.headwindKts, 7.07, 0.05, 'headwind magnitude');
    assertNear(Math.abs(display.crosswindKts), 7.07, 0.05, 'crosswind magnitude');
    assert(display.alongArrow === '\u2193', 'headwind arrow');
    assert(display.alongClass === 'rwy-comp-hw', 'headwind color class');
    assert(display.crosswindArrow === '\u2190', 'drift left');
});

test('36/18 at 045: runway 18 tailwind up-red, drift right', () => {
    const display = runwayEndWindDisplay(45, 10, 180);
    assertNear(display.headwindKts, -7.07, 0.05, 'tailwind magnitude');
    assertNear(Math.abs(display.crosswindKts), 7.07, 0.05, 'crosswind magnitude');
    assert(display.alongArrow === '\u2191', 'tailwind arrow');
    assert(display.alongClass === 'rwy-comp-tw', 'tailwind color class');
    assert(display.crosswindArrow === '\u2192', 'drift right');
});

test('runway 22 at 207/15: headwind down-green, drift right', () => {
    const display = runwayEndWindDisplay(207, 15, 223);
    assert(display.headwindKts > 0, 'headwind on runway 22');
    assertNear(Math.abs(display.crosswindKts), 4.1, 0.2, 'crosswind magnitude');
    assert(display.alongArrow === '\u2193', 'headwind arrow');
    assert(display.alongClass === 'rwy-comp-hw', 'headwind color class');
    assert(display.crosswindArrow === '\u2192', 'drift right from left crosswind');
});

test('runway 04 at 207/15: tailwind up-red, drift left', () => {
    const display = runwayEndWindDisplay(207, 15, 43);
    assert(display.headwindKts < 0, 'tailwind on runway 04');
    assert(display.alongArrow === '\u2191', 'tailwind arrow');
    assert(display.alongClass === 'rwy-comp-tw', 'tailwind color class');
    assert(display.crosswindArrow === '\u2190', 'drift left from right crosswind');
});

test('direct headwind has zero crosswind drift arrow convention', () => {
    const hw = headwindKts(270, 12, 270);
    const xw = signedCrosswindKts(270, 12, 270);
    const display = runwayEndWindDisplay(270, 12, 270);
    assertNear(hw, 12, 0.01, 'headwind');
    assertNear(xw, 0, 0.01, 'crosswind');
    assert(display.alongArrow === '\u2193', 'into the wind');
    assert(display.crosswindArrow === '', 'no crosswind arrow when drift rounds to zero');
});

console.log('\n' + '='.repeat(50));
console.log(`Results: ${passed} passed, ${failed} failed\n`);
if (failed > 0) {
    process.exit(1);
}
