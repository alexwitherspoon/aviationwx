/**
 * Runway display render invariants (safety-critical)
 *
 * Run with: node tests/js/runway-display-render.test.js
 */

const { JSDOM } = require('jsdom');
const fs = require('fs');
const path = require('path');

const runwayDisplayJs = fs.readFileSync(
    path.join(__dirname, '../../public/js/runway-display.js'),
    'utf8'
);

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

function buildDom(weather) {
    const dom = new JSDOM(`<!DOCTYPE html><html><body>
        <section id="runway-display-section" class="frequencies runway-style-e" hidden>
            <div id="runway-display-list" class="runway-hybrid-list"></div>
        </section>
    </body></html>`, { runScripts: 'outside-only' });

    const { window } = dom;
    window.formatWindSpeed = (kts) => String(Math.round(kts));
    window.getWindSpeedUnitLabel = () => 'kts';
    window.getDistanceUnit = () => 'ft';
    window.formatAltitude = (v) => String(Math.round(v));

    window.eval(runwayDisplayJs);
    window.AviationWX.renderRunwayDisplay(weather);

    return dom;
}

const sampleWeather = {
    wind_direction: 270,
    wind_direction_magnetic: 270,
    wind_speed: 6,
    runway_display: {
        runway_source: 'nasr',
        runways: [{
            rwy_id: '09/27',
            length_ft: 2260,
            width_ft: 35,
            surface: 'Gravel',
            lights: null,
            closed: false,
            ends: [
                { end_id: '09', heading_mag: 90, calm_wind_arrival: false, calm_wind_departure: false },
                { end_id: '27', heading_mag: 270, calm_wind_arrival: false, calm_wind_departure: false }
            ]
        }]
    }
};

console.log('\nRunway display render (safety-critical)\n' + '='.repeat(50));

test('renders one card with desktop and mobile blocks in markup', () => {
    const dom = buildDom(sampleWeather);
    const doc = dom.window.document;
    assert(doc.querySelectorAll('.runway-hybrid-card').length === 1, 'expected one card');
    assert(doc.querySelectorAll('.runway-hybrid-desktop-only').length === 1, 'expected one desktop block');
    assert(doc.querySelectorAll('.runway-hybrid-mobile-only').length === 1, 'expected one mobile block');
    assert(!doc.getElementById('runway-display-section').hidden, 'section should be visible');
});

test('missing wind shows --- not zero kts', () => {
    const dom = buildDom({
        runway_display: sampleWeather.runway_display
    });
    const html = dom.window.document.getElementById('runway-display-list').innerHTML;
    assert(html.includes('--- kts'), 'missing wind must render ---');
    assert(!/\b0 kts\b/.test(html), 'must not show zero wind components when wind missing');
    assert(!/rwy-comp-(hw|tw|xw)">\s*[↓↑←→]?\s*0 kts/.test(html), 'must not show zero runway wind rows when wind missing');
});

test('helipad card omits per-end wind components', () => {
    const dom = buildDom({
        wind_direction_magnetic: 270,
        wind_speed: 12,
        runway_display: {
            runway_source: 'nasr',
            runways: [{
                rwy_id: 'H1',
                length_ft: 50,
                width_ft: 50,
                surface: 'Asphalt',
                lights: null,
                closed: false,
                is_helipad: true,
                ends: [
                    { end_id: 'H1', heading_mag: null, calm_wind_arrival: false, calm_wind_departure: false },
                ],
            }],
        },
    });
    const html = dom.window.document.getElementById('runway-display-list').innerHTML;
    assert(html.includes('H1'), 'helipad headline must render');
    assert(html.includes('50'), 'dimensions must render');
    assert(html.includes('Asphalt'), 'surface must render');
    assert(!html.includes('rwy-comp-hw'), 'helipad must not render headwind row');
    assert(!html.includes('rwy-comp-xw'), 'helipad must not render crosswind row');
    assert(!html.includes('--- kts'), 'helipad must not render placeholder wind components');
    assert(dom.window.document.querySelectorAll('.runway-hybrid-wind-row').length === 0, 'no wind rows');
});

test('non-helipad runway still renders wind components', () => {
    const dom = buildDom(sampleWeather);
    const html = dom.window.document.getElementById('runway-display-list').innerHTML;
    assert(/rwy-comp-hw">\u2193 \d+ kts/.test(html), 'expected headwind down arrow with numeric speed');
    assert(/rwy-comp-tw">\u2191 \d+ kts/.test(html), 'expected tailwind up arrow with numeric speed');
    assert(/rwy-comp-xw">\d+ kts/.test(html), 'expected crosswind magnitude when drift rounds to zero');
    assert(!/rwy-comp-xw">[←→] 0 kts/.test(html), 'must not show drift arrow on zero crosswind');
});

test('calm wind with missing heading shows --- not zero kts', () => {
    const dom = buildDom({
        wind_speed: 1,
        runway_display: {
            runway_source: 'config',
            runways: [{
                rwy_id: '09/27',
                length_ft: 2000,
                width_ft: 40,
                surface: 'Turf',
                lights: null,
                closed: false,
                ends: [
                    { end_id: '09', heading_mag: null, calm_wind_arrival: true, calm_wind_departure: true },
                ],
            }],
        },
    });
    const html = dom.window.document.getElementById('runway-display-list').innerHTML;
    assert(html.includes('--- kts'), 'missing heading must render --- even when calm');
    assert(!/\b0 kts\b/.test(html), 'must not show zero wind components without heading');
    assert(!/rwy-comp-(hw|tw|xw)">\s*[↓↑←→]?\s*0 kts/.test(html), 'must not show zero runway wind rows without heading');
});

test('dashboard CSS hides mobile block on desktop', () => {
    const css = fs.readFileSync(
        path.join(__dirname, '../../public/css/styles.css'),
        'utf8'
    );
    assert(css.includes('.runway-style-e .runway-hybrid-mobile-only'), 'mobile hide rule missing');
    assert(css.includes('display: none'), 'display none rule missing');
    assert(css.includes('@media (max-width: 768px)'), 'mobile breakpoint missing');
    assert(css.includes('body.dark-mode .runway-style-e'), 'dark mode runway tokens missing');
    assert(css.includes('body.night-mode .runway-style-e'), 'night mode runway tokens missing');
});

console.log('\n' + '='.repeat(50));
console.log(`Results: ${passed} passed, ${failed} failed\n`);
if (failed > 0) {
    process.exit(1);
}
