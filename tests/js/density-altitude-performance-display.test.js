/**
 * Density altitude performance display (shared JS module)
 *
 * Run with: node tests/js/density-altitude-performance-display.test.js
 */

const da = require('../../public/js/density-altitude-performance-display.js');

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

function assertIncludes(haystack, needle, message) {
    if (!String(haystack).includes(needle)) {
        throw new Error(message || `Expected "${haystack}" to include "${needle}"`);
    }
}

const cautionPerformance = {
    tier: 'caution',
    fallback: false,
    selection_basis: 'best_performance',
    best_end: { end_id: '31L', rwy_id: '13R/31L', total_risk: 1.65, tier: 'caution' },
};

const warningPerformance = {
    tier: 'warning',
    fallback: false,
    selection_basis: 'best_performance',
    best_end: { end_id: '08', rwy_id: '08/26', total_risk: 2.45, tier: 'warning' },
};

console.log('\nDensity altitude performance display (JS)\n' + '='.repeat(50));

test('normal tier has no emoji or warning class', () => {
    assert(da.emoji('normal') === '', 'emoji empty');
    assert(da.valueClass('normal') === '', 'class empty');
    assert(da.tooltip('normal', cautionPerformance) === '', 'tooltip empty');
});

test('caution tier uses warning styling class', () => {
    assert(da.valueClass('caution') === 'density-altitude-warning');
    assert(da.emoji('caution') === '⚠️');
});

test('warning tier uses flag emoji', () => {
    assert(da.emoji('warning') === '🚩');
});

test('operational end label uses best_end only', () => {
    assert(da.operationalEndLabel(cautionPerformance) === 'RWY 31L (13R/31L)');
    assert(da.operationalEndLabel({ best_end: { end_id: '17', rwy_id: 'config' } }) === 'RWY 17');
    assert(da.operationalEndLabel({ worst_end: { end_id: '08' } }) === '');
});

test('selection basis note names best runway', () => {
    const note = da.selectionBasisNote(cautionPerformance);
    assertIncludes(note, 'RWY 31L (13R/31L)');
    assertIncludes(note, 'best option among runways on file');
});

test('fallback tooltip copy', () => {
    const tip = da.tooltip('warning', { fallback: true, tier: 'warning' });
    assertIncludes(tip, 'Runway data unavailable');
    assertIncludes(tip, 'AFM');
});

test('dashboard display separates value and emoji', () => {
    const display = da.formatDashboardDisplay(5342, warningPerformance, {
        distUnit: 'ft',
        formatValue: (ft) => Math.round(ft),
    });
    assert(display.value === 5342);
    assert(display.emoji === '🚩');
    assert(display.className === 'density-altitude-warning');
    assertIncludes(display.title, 'dangerously high');
    assertIncludes(display.ariaLabel, 'Density altitude 5,342 feet');
});

test('dashboard normal tier omits emoji even when worst_end would alarm', () => {
    const display = da.formatDashboardDisplay(2000, { tier: 'normal', best_end: { end_id: '26' } }, {
        distUnit: 'ft',
        formatValue: (ft) => Math.round(ft),
    });
    assert(display.emoji === '');
    assert(display.className === '');
});

test('embed display combines distance text and emoji', () => {
    const formatEmbedDist = (ft, unit, commas) => {
        if (ft === null) {
            return '--';
        }
        return (commas ? '5,342' : '5342') + (unit === 'm' ? ' m' : ' ft');
    };
    const display = da.formatEmbedDisplay(5342, warningPerformance, 'ft', formatEmbedDist);
    assertIncludes(display.text, '5,342 ft');
    assertIncludes(display.text, '🚩');
    assert(display.className === 'density-altitude-warning');
});

test('embed meters aria label', () => {
    const display = da.formatEmbedDisplay(1000, cautionPerformance, 'm', (ft) => `${Math.round(ft * 0.3048)} m`);
    assertIncludes(display.ariaLabel, 'meters');
});

test('performanceTier defaults to normal', () => {
    assert(da.performanceTier(null) === 'normal');
    assert(da.performanceTier({}) === 'normal');
});

console.log('\n' + '='.repeat(50));
console.log(`Results: ${passed} passed, ${failed} failed\n`);
if (failed > 0) {
    process.exit(1);
}
