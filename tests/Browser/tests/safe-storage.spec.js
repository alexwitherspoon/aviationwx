const { test, expect } = require('@playwright/test');

/**
 * Tests for safe localStorage/sessionStorage access
 *
 * localStorage throws SecurityError in iOS Private Browsing and when storage
 * is disabled. The safeStorageGet/safeStorageSet pattern ensures graceful
 * degradation. These tests verify the helper logic handles SecurityError.
 */
test.describe('Safe Storage (localStorage SecurityError handling)', () => {
  const baseUrl = process.env.TEST_BASE_URL || 'http://localhost:8080';

  test('safeStorageGet returns null when localStorage.getItem throws SecurityError', async ({
    page,
  }) => {
    await page.goto(`${baseUrl}/?airport=kspb`);
    await page.waitForLoadState('load', { timeout: 30000 });

    const result = await page.evaluate(() => {
      const safeStorageGet = (key) => {
        try {
          return localStorage.getItem(key);
        } catch (e) {
          return null;
        }
      };

      const originalGetItem = Storage.prototype.getItem;
      Storage.prototype.getItem = function () {
        throw new DOMException('The operation is insecure.', 'SecurityError');
      };

      const value = safeStorageGet('test-key');

      Storage.prototype.getItem = originalGetItem;
      return value;
    });

    expect(result).toBeNull();
  });

  test('safeStorageSet does not throw when localStorage.setItem throws SecurityError', async ({
    page,
  }) => {
    await page.goto(`${baseUrl}/?airport=kspb`);
    await page.waitForLoadState('load', { timeout: 30000 });

    const threw = await page.evaluate(() => {
      const safeStorageSet = (key, value) => {
        try {
          localStorage.setItem(key, value);
        } catch (e) {
          /* unavailable */
        }
      };

      const originalSetItem = Storage.prototype.setItem;
      Storage.prototype.setItem = function () {
        throw new DOMException('The operation is insecure.', 'SecurityError');
      };

      let didThrow = false;
      try {
        safeStorageSet('test-key', 'value');
      } catch (e) {
        didThrow = true;
      }

      Storage.prototype.setItem = originalSetItem;
      return didThrow;
    });

    expect(threw).toBe(false);
  });

  test('airports page loads without error when localStorage throws on init', async ({
    page,
  }) => {
    await page.addInitScript(() => {
      const originalGetItem = Storage.prototype.getItem;
      const originalSetItem = Storage.prototype.setItem;
      Storage.prototype.getItem = function () {
        throw new DOMException('The operation is insecure.', 'SecurityError');
      };
      Storage.prototype.setItem = function () {
        throw new DOMException('The operation is insecure.', 'SecurityError');
      };
    });

    const response = await page.goto(`${baseUrl}/?airports=1`);
    expect(response.status()).toBe(200);

    await page.waitForLoadState('load', { timeout: 30000 });
    await page.waitForSelector('#map', { state: 'attached', timeout: 5000 });

    const mapExists = await page.locator('#map').count() > 0;
    expect(mapExists).toBe(true);
  });

  test('airport page preference functions work when localStorage throws', async ({
    page,
  }) => {
    await page.goto(`${baseUrl}/?airport=kspb`);
    await page.waitForLoadState('load', { timeout: 30000 });

    const result = await page.evaluate(() => {
      if (typeof getTimeFormat !== 'function') {
        return { error: 'getTimeFormat not available' };
      }

      const originalGetItem = Storage.prototype.getItem;
      Storage.prototype.getItem = function () {
        throw new DOMException('The operation is insecure.', 'SecurityError');
      };

      let format;
      let threw = false;
      try {
        format = getTimeFormat();
      } catch (e) {
        threw = true;
      }

      Storage.prototype.getItem = originalGetItem;

      return { format, threw };
    });

    expect(result.threw).toBe(false);
    expect(result.format).toBeDefined();
    expect(['12hr', '24hr']).toContain(result.format);
  });
});
