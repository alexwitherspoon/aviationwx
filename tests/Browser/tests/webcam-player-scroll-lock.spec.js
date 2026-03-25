const { test, expect } = require('@playwright/test');

/**
 * Regression: scroll lock must fully apply/release on html + body (mobile viewport / text scaling).
 * Uses the same AviationWX.webcamPlayerScrollLock API as the dashboard.
 */
test.describe('Webcam player scroll lock', () => {
    const baseUrl = process.env.TEST_API_URL || process.env.TEST_BASE_URL || 'http://localhost:9080';
    const testAirport = 'kspb';

    test.beforeEach(async ({ page }) => {
        await page.goto(`${baseUrl}/?airport=${testAirport}`);
        await page.waitForLoadState('load', { timeout: 30000 });
        await page.waitForSelector('body', { state: 'visible' });
        await page.waitForFunction(
            () => typeof AviationWX !== 'undefined'
                && AviationWX.webcamPlayerScrollLock
                && typeof AviationWX.webcamPlayerScrollLock.apply === 'function',
            null,
            { timeout: 15000 }
        );
    });

    test('apply/release clears html and body inline styles', async ({ page }) => {
        await page.evaluate(() => window.scrollTo(0, 88));

        const locked = await page.evaluate(() => {
            const y = AviationWX.webcamPlayerScrollLock.apply();
            return {
                y,
                htmlOverflow: document.documentElement.style.overflow,
                bodyOverflow: document.body.style.overflow,
                bodyPosition: document.body.style.position,
                bodyLeft: document.body.style.left,
                bodyRight: document.body.style.right,
                bodyTop: document.body.style.top,
            };
        });

        expect(locked.htmlOverflow).toBe('hidden');
        expect(locked.bodyOverflow).toBe('hidden');
        expect(locked.bodyPosition).toBe('fixed');
        expect(['0', '0px']).toContain(locked.bodyLeft);
        expect(['0', '0px']).toContain(locked.bodyRight);
        expect(locked.bodyTop).toBe(`-${locked.y}px`);

        const cleared = await page.evaluate(() => {
            AviationWX.webcamPlayerScrollLock.release();
            return {
                htmlOverflow: document.documentElement.style.overflow,
                bodyOverflow: document.body.style.overflow,
                bodyPosition: document.body.style.position,
                bodyLeft: document.body.style.left,
                bodyRight: document.body.style.right,
                bodyWidth: document.body.style.width,
                bodyTop: document.body.style.top,
            };
        });

        expect(cleared.htmlOverflow).toBe('');
        expect(cleared.bodyOverflow).toBe('');
        expect(cleared.bodyPosition).toBe('');
        expect(cleared.bodyLeft).toBe('');
        expect(cleared.bodyRight).toBe('');
        expect(cleared.bodyWidth).toBe('');
        expect(cleared.bodyTop).toBe('');
    });

    test('closing history player leaves document scroll styles empty', async ({ page }) => {
        const camCount = await page.locator('.webcam-image').count();
        const hasWebcam = camCount > 0;
        const historyOn = await page.evaluate(() => !!(window.AIRPORT_NAV_DATA && window.AIRPORT_NAV_DATA.webcamHistoryEnabled));
        if (!hasWebcam || !historyOn) {
            test.skip();
            return;
        }

        await page.locator('.webcam-image').first().click();
        await page.waitForSelector('#webcam-player.active', { timeout: 15000 });

        await page.locator('.webcam-player-back').click();
        await page.waitForFunction(() => {
            const el = document.getElementById('webcam-player');
            return el && !el.classList.contains('active');
        }, { timeout: 15000 });

        const styles = await page.evaluate(() => ({
            htmlOverflow: document.documentElement.style.overflow,
            bodyOverflow: document.body.style.overflow,
            bodyPosition: document.body.style.position,
            bodyLeft: document.body.style.left,
            bodyRight: document.body.style.right,
            bodyTop: document.body.style.top,
        }));

        expect(styles.htmlOverflow).toBe('');
        expect(styles.bodyOverflow).toBe('');
        expect(styles.bodyPosition).toBe('');
        expect(styles.bodyLeft).toBe('');
        expect(styles.bodyRight).toBe('');
        expect(styles.bodyTop).toBe('');
    });
});
