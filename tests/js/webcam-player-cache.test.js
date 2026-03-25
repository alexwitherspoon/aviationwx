/**
 * Webcam history player preload cache — safety tests
 *
 * Preload keys MUST include airport + camera + timestamp so co-scheduled cameras
 * never share a cache entry. Pruning must preserve other cameras' entries.
 *
 * Run with: node tests/js/webcam-player-cache.test.js
 */

const {
    makeWebcamPreloadKey,
    pruneWebcamPreloadForCameraPeriod,
} = require('../../public/js/webcam-player-utils.js');

let passed = 0;
let failed = 0;

function test(name, fn) {
    try {
        fn();
        console.log(`  ✓ ${name}`);
        passed++;
        return true;
    } catch (e) {
        console.error(`  ✗ ${name}`);
        console.error(`    ${e.message}`);
        failed++;
        return false;
    }
}

function runTests() {
    console.log('\nWebcam player preload cache tests\n' + '='.repeat(50));

    test('same timestamp, different cam → different keys', () => {
        const a = makeWebcamPreloadKey('4s9', 0, 1735084200);
        const b = makeWebcamPreloadKey('4s9', 1, 1735084200);
        if (a === b) {
            throw new Error(`keys collided: ${a}`);
        }
    });

    test('same airport, cam, timestamp → stable key', () => {
        const k = makeWebcamPreloadKey('ksea', 2, 1700000000);
        if (k !== makeWebcamPreloadKey('ksea', 2, 1700000000)) {
            throw new Error('key not stable');
        }
    });

    test('prune removes only stale timestamps for that camera', () => {
        const pre = {};
        const loading = new Set();
        const kKeep = makeWebcamPreloadKey('4s9', 1, 100);
        const kDrop = makeWebcamPreloadKey('4s9', 1, 200);
        const kOtherCam = makeWebcamPreloadKey('4s9', 0, 200);
        pre[kKeep] = 'url100';
        pre[kDrop] = 'url200';
        pre[kOtherCam] = 'otherCam';
        loading.add(kDrop);

        pruneWebcamPreloadForCameraPeriod(pre, loading, '4s9', 1, new Set([100]));

        if (pre[kDrop] !== undefined) {
            throw new Error('expected dropped key removed');
        }
        if (pre[kKeep] !== 'url100') {
            throw new Error('expected kept key');
        }
        if (pre[kOtherCam] !== 'otherCam') {
            throw new Error('other camera cache must be preserved');
        }
        if (loading.has(kDrop)) {
            throw new Error('loading set should drop pruned key');
        }
    });

    test('prune empty valid set clears all keys for that camera', () => {
        const pre = {};
        const loading = new Set();
        const k = makeWebcamPreloadKey('x', 0, 1);
        pre[k] = 'u';
        loading.add(k);
        pruneWebcamPreloadForCameraPeriod(pre, loading, 'x', 0, new Set());
        if (pre[k] !== undefined) {
            throw new Error('expected all evicted');
        }
        if (loading.size !== 0) {
            throw new Error('loading should be empty');
        }
    });

    console.log('\n' + '='.repeat(50));
    console.log(`Results: ${passed} passed, ${failed} failed\n`);
    if (failed > 0) {
        process.exit(1);
    }
}

runTests();
