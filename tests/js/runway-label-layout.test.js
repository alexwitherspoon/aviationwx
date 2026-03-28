/**
 * Runway label layout (1D on segment) unit tests.
 *
 * Labels must stay on runway lines and avoid overlapping the hub when CALM/VRB is shown.
 *
 * Run with: node tests/js/runway-label-layout.test.js
 */

const { computeRunwayLabelPositions } = require('../../public/js/runway-label-layout.js');

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

function assert(cond, msg) {
    if (!cond) {
        throw new Error(msg || 'assertion failed');
    }
}

function runTests() {
    console.log('\nRunway label layout tests\n' + '='.repeat(50));

    test('labels stay on runway segment (vertical segment)', () => {
        const cx = 150;
        const cy = 150;
        const r = 130;
        const segments = [
            {
                start: [0, 1],
                end: [0, -1],
                le_ident: '34',
                he_ident: '16'
            }
        ];
        const out = computeRunwayLabelPositions({
            cx: cx,
            cy: cy,
            r: r,
            segments: segments,
            willShowCenterText: true,
            runwayScale: 0.86,
            labelPosition: 0.93
        });
        assert(out.length === 2, 'two labels');
        const rw = r * 0.86;
        out.forEach(function (lp) {
            const nx = (lp.x - cx) / rw;
            const ny = -(lp.y - cy) / rw;
            assert(Math.abs(nx) < 0.001, 'on vertical line x=0');
            assert(Math.abs(ny) <= 1.001, 'within segment endpoints');
        });
    });

    test('hub step pushes labels past exclusion when default positions sit inside hub', () => {
        const cx = 150;
        const cy = 150;
        const r = 130;
        const segments = [
            {
                start: [0, 1],
                end: [0, -1],
                le_ident: '34',
                he_ident: '16'
            }
        ];
        const rw = r * 0.86;
        const defaultDist = rw * (1 - 2 * 0.07);
        assert(defaultDist < 100, 'precondition: default label radius must be inside 100px hub');
        const out = computeRunwayLabelPositions({
            cx: cx,
            cy: cy,
            r: r,
            segments: segments,
            willShowCenterText: true,
            centerExclusion: 100,
            runwayScale: 0.86,
            labelPosition: 0.93,
            tMin: 0.02,
            tMax: 0.98,
            tNearStartMax: 0.42,
            tNearEndMin: 0.58
        });
        assert(out.length === 2, 'two labels');
        out.forEach(function (lp) {
            const d = Math.hypot(lp.x - cx, lp.y - cy);
            assert(d >= 100 - 1, 'each label at or beyond hub radius (1px tolerance): d=' + d);
        });
    });

    test('invalid options return empty positions', () => {
        assert(computeRunwayLabelPositions(null).length === 0, 'null');
        assert(computeRunwayLabelPositions({}).length === 0, 'missing geometry');
        assert(computeRunwayLabelPositions({
            cx: 0,
            cy: 0,
            r: -1,
            segments: [],
            willShowCenterText: false
        }).length === 0, 'non-positive r');
    });

    test('no hub adjustment when willShowCenterText is false', () => {
        const cx = 150;
        const cy = 150;
        const r = 130;
        const segments = [
            {
                start: [0, 1],
                end: [0, -1],
                le_ident: '34',
                he_ident: '16'
            }
        ];
        const out = computeRunwayLabelPositions({
            cx: cx,
            cy: cy,
            r: r,
            segments: segments,
            willShowCenterText: false,
            runwayScale: 0.86,
            labelPosition: 0.93
        });
        assert(out.length === 2, 'two labels');
        const rw = r * 0.86;
        const tStart = 0.07;
        const tEnd = 0.93;
        const expectedYTop = cy - rw * ((1 - tStart) * 1 + tStart * (-1));
        const expectedYBottom = cy - rw * ((1 - tEnd) * 1 + tEnd * (-1));
        const ys = out.map(function (p) { return p.y; }).sort(function (a, b) { return a - b; });
        assert(Math.abs(ys[0] - expectedYTop) < 0.5 || Math.abs(ys[1] - expectedYTop) < 0.5, 'near top');
        assert(Math.abs(ys[0] - expectedYBottom) < 0.5 || Math.abs(ys[1] - expectedYBottom) < 0.5, 'near bottom');
    });

    console.log('\n' + '='.repeat(50));
    console.log(`Results: ${passed} passed, ${failed} failed`);
    if (failed > 0) {
        process.exit(1);
    }
}

runTests();
