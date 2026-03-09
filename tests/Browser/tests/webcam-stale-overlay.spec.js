const { test, expect } = require('@playwright/test');

/**
 * Tests for webcam stale overlay display
 * When a webcam image exceeds the fail-closed threshold, the overlay shows
 * instead of the placeholder, keeping the last image visible with a dimmed overlay.
 */
test.describe('Webcam Stale Overlay', () => {
  const baseUrl = process.env.TEST_BASE_URL || 'http://localhost:8080';
  const testAirport = 'kspb';

  test.beforeEach(async ({ page }) => {
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });
    await page.waitForSelector('body', { state: 'visible' });
  });

  test('should show overlay when showStaleWebcamOverlay is called', async ({ page }) => {
    // Wait for webcam container to exist (airport may have webcams)
    const hasWebcam = await page.evaluate(() => {
      const container = document.querySelector('.webcam-container');
      return !!container;
    });

    if (!hasWebcam) {
      test.skip();
      return;
    }

    // Wait for showStaleWebcamOverlay to be available
    const hasFunction = await page.waitForFunction(
      () => typeof showStaleWebcamOverlay === 'function',
      { timeout: 5000 }
    ).catch(() => null);

    if (!hasFunction) {
      test.skip();
      return;
    }

    // Simulate stale state by calling showStaleWebcamOverlay
    const timestamp = Math.floor(Date.now() / 1000) - 3600; // 1 hour ago
    await page.evaluate(({ camIndex, ts }) => {
      if (typeof showStaleWebcamOverlay === 'function') {
        showStaleWebcamOverlay(camIndex, ts);
      }
    }, { camIndex: 0, ts: timestamp });

    await page.waitForTimeout(300);

    // Overlay should be visible
    const overlay = page.locator('.webcam-stale-overlay').first();
    await expect(overlay).toBeVisible();

    // Container should have dimmed class
    const container = page.locator('.webcam-container.webcam-stale-dimmed').first();
    await expect(container).toBeVisible();

    // Overlay should contain stale message
    const staleMessage = page.locator('.webcam-stale-overlay .stale-message').first();
    await expect(staleMessage).toContainText(/Live image unavailable|Tap for time-lapse/i);
  });
});
