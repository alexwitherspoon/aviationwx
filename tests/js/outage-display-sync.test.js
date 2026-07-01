/**
 * Outage display sync (safety-critical)
 *
 * Server and client paths must both trigger supplemental fail-closed re-render.
 *
 * Run with: node tests/js/outage-display-sync.test.js
 */

const { applyOutageDisplayState } = require('../../public/js/outage-display-sync.js');

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

console.log('\nOutage display sync (safety-critical)\n' + '='.repeat(50));

test('applyOutageDisplayState syncs banner and supplemental hide when in outage', () => {
    const calls = { banner: null, hide: null };
    applyOutageDisplayState(
        {
            maintenance: false,
            in_outage: true,
            limited_availability: false,
            newest_timestamp: 1_700_000_000,
        },
        {
            syncBannerState: (state) => { calls.banner = state; },
            hideSupplementalRemoteFieldsIfOutage: (inOutage) => { calls.hide = inOutage; },
        }
    );
    assert(calls.banner !== null, 'banner sync called');
    assert(calls.banner.in_outage === true, 'banner state preserved');
    assert(calls.hide === true, 'supplemental hide called with in_outage true');
});

test('applyOutageDisplayState clears supplemental hide when not in outage', () => {
    let hideArg = undefined;
    applyOutageDisplayState(
        {
            maintenance: false,
            in_outage: false,
            limited_availability: false,
            newest_timestamp: 0,
        },
        {
            syncBannerState: () => {},
            hideSupplementalRemoteFieldsIfOutage: (inOutage) => { hideArg = inOutage; },
        }
    );
    assert(hideArg === false, 'hide called with false when recovered');
});

test('applyOutageDisplayState passes maintenance through to banner sync', () => {
    let bannerState = null;
    applyOutageDisplayState(
        {
            maintenance: true,
            in_outage: true,
            limited_availability: false,
            newest_timestamp: 1_700_000_000,
        },
        {
            syncBannerState: (state) => { bannerState = state; },
            hideSupplementalRemoteFieldsIfOutage: () => {},
        }
    );
    assert(bannerState.maintenance === true, 'maintenance forwarded');
});

console.log('\n' + '='.repeat(50));
console.log(`Results: ${passed} passed, ${failed} failed\n`);
if (failed > 0) {
    process.exit(1);
}
