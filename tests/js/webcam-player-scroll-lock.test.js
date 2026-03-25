/**
 * Webcam player scroll lock — DOM contract tests (jsdom)
 *
 * Run with: node tests/js/webcam-player-scroll-lock.test.js
 */

const { JSDOM } = require('jsdom');

const {
    applyWebcamScrollLock,
    releaseWebcamScrollLock,
} = require('../../public/js/webcam-player-scroll-lock.js');

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
    console.log('\nWebcam player scroll lock (DOM contract)\n' + '='.repeat(50));

    test('apply sets html overflow and body fixed + left/right', () => {
        const dom = new JSDOM('<!DOCTYPE html><html><body><div style="height:2000px">x</div></body></html>', {
            url: 'http://localhost/',
            pretendToBeVisual: true,
        });
        const { window } = dom;
        Object.defineProperty(window, 'scrollY', { value: 42, writable: true });
        global.window = window;
        global.document = window.document;

        const y = applyWebcamScrollLock();
        if (y !== 42) {
            throw new Error(`expected saved y 42, got ${y}`);
        }
        if (window.document.documentElement.style.overflow !== 'hidden') {
            throw new Error('html overflow should be hidden');
        }
        if (window.document.body.style.overflow !== 'hidden') {
            throw new Error('body overflow should be hidden');
        }
        if (window.document.body.style.position !== 'fixed') {
            throw new Error('body position should be fixed');
        }
        const left = window.document.body.style.left;
        const right = window.document.body.style.right;
        if (!['0', '0px'].includes(left) || !['0', '0px'].includes(right)) {
            throw new Error(`body should have left/right 0 (got left=${left}, right=${right})`);
        }
        if (window.document.body.style.top !== '-42px') {
            throw new Error(`body top should be -42px, got ${window.document.body.style.top}`);
        }
    });

    test('release clears all inline lock styles', () => {
        const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
            url: 'http://localhost/',
            pretendToBeVisual: true,
        });
        const { window } = dom;
        Object.defineProperty(window, 'scrollY', { value: 0, writable: true });
        global.window = window;
        global.document = window.document;

        applyWebcamScrollLock();
        releaseWebcamScrollLock();

        const html = window.document.documentElement;
        const body = window.document.body;
        if (html.style.overflow !== '') {
            throw new Error('html overflow should be cleared');
        }
        if (body.style.overflow !== '' || body.style.position !== '') {
            throw new Error('body overflow/position should be cleared');
        }
        if (body.style.left !== '' || body.style.right !== '' || body.style.width !== '' || body.style.top !== '') {
            throw new Error('body box styles should be cleared');
        }
    });

    console.log('\n' + '='.repeat(50));
    console.log(`Results: ${passed} passed, ${failed} failed\n`);
    if (failed > 0) {
        process.exit(1);
    }
}

runTests();
