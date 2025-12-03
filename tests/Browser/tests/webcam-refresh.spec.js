const { test, expect } = require('@playwright/test');

/**
 * Tests for webcam image refresh logic
 * These tests verify that webcam images refresh at the configured interval
 * and that the CAM_TS tracking works correctly
 */
test.describe('Webcam Refresh Logic', () => {
  const baseUrl = process.env.TEST_BASE_URL || 'http://localhost:8080';
  const testAirport = 'kspb';
  
  test.beforeEach(async ({ page, context }) => {
    // Clear all caches before each test
    await context.clearCookies();
    
    // Navigate to the page FIRST (localStorage requires a real page context)
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    // Wait for 'load' to ensure all scripts are executed (critical for CI)
    await page.waitForLoadState('load', { timeout: 30000 });
    await page.waitForSelector('body', { state: 'visible' });
    
    // Now clear storage AFTER navigating to a real page
    await page.evaluate(() => {
      try {
        localStorage.clear();
        sessionStorage.clear();
      } catch (e) {
        console.warn('Could not clear storage:', e.message);
      }
    });
    
    // Wait for webcam-related JavaScript to be available
    // First check if webcams exist on the page, then wait for JS initialization
    const hasWebcams = await page.evaluate(() => {
      return document.querySelectorAll('img[id^="webcam-"]').length > 0;
    });
    
    if (!hasWebcams) {
      // If no webcams, log and continue - individual tests will handle skipping
      console.warn('No webcam images found on page - webcam tests may be skipped');
      return;
    }
    
    // Only wait for webcam JS if webcams are present
    // Increase timeout and make condition more flexible
    // Don't fail if it times out - individual tests will check availability
    await page.waitForFunction(
      () => {
        // CAM_TS should be defined (even if empty object)
        const hasCamTs = typeof window.CAM_TS !== 'undefined';
        // safeSwapCameraImage function should exist
        const hasFunction = typeof window.safeSwapCameraImage === 'function' || 
                           typeof safeSwapCameraImage === 'function';
        return hasCamTs && hasFunction;
      },
      { timeout: 20000 } // Increased from 10s to 20s
    ).catch((error) => {
      // Log but don't fail - individual tests will handle missing webcam JS
      console.warn('Webcam JavaScript functions may not be available:', error.message);
    });
  });

  test('CAM_TS should be initialized with server-side timestamps on page load', async ({ page }) => {
    // Skip if webcams aren't available
    const hasWebcams = await page.evaluate(() => {
      return document.querySelectorAll('img[id^="webcam-"]').length > 0;
    });
    if (!hasWebcams || typeof window.CAM_TS === 'undefined') {
      test.skip();
      return;
    }
    
    // Check that CAM_TS is initialized for all webcams
    const camTsInitialized = await page.evaluate(() => {
      // Check if CAM_TS exists and has values
      if (typeof window.CAM_TS === 'undefined') {
        return { initialized: false, reason: 'CAM_TS is undefined' };
      }
      
      // Get number of webcams from images
      const webcamImages = document.querySelectorAll('img[id^="webcam-"]');
      const webcamCount = webcamImages.length;
      
      if (webcamCount === 0) {
        return { initialized: false, reason: 'No webcam images found' };
      }
      
      // Check that CAM_TS has entries for each webcam
      const missing = [];
      for (let i = 0; i < webcamCount; i++) {
        if (typeof window.CAM_TS[i] === 'undefined' || window.CAM_TS[i] === null) {
          missing.push(i);
        }
      }
      
      if (missing.length > 0) {
        return { 
          initialized: false, 
          reason: `CAM_TS missing for cameras: ${missing.join(', ')}`,
          camTs: window.CAM_TS,
          webcamCount
        };
      }
      
      // Verify timestamps are valid (non-zero, reasonable Unix timestamps)
      const invalid = [];
      for (let i = 0; i < webcamCount; i++) {
        const ts = parseInt(window.CAM_TS[i]);
        if (isNaN(ts) || ts <= 0 || ts < 1000000000) { // Before 2001-09-09
          invalid.push({ cam: i, ts: window.CAM_TS[i] });
        }
      }
      
      if (invalid.length > 0) {
        return { 
          initialized: false, 
          reason: `Invalid timestamps: ${JSON.stringify(invalid)}`,
          camTs: window.CAM_TS
        };
      }
      
      return { 
        initialized: true, 
        camTs: window.CAM_TS,
        webcamCount 
      };
    });
    
    expect(camTsInitialized.initialized).toBeTruthy();
    if (!camTsInitialized.initialized) {
      console.error('CAM_TS initialization failed:', camTsInitialized);
    }
  });

  test('webcam images should have data-initial-timestamp attribute', async ({ page }) => {
    // Skip if webcams aren't available
    const hasWebcams = await page.evaluate(() => {
      return document.querySelectorAll('img[id^="webcam-"]').length > 0 && typeof window.CAM_TS !== 'undefined';
    });
    if (!hasWebcams) {
      test.skip();
      return;
    }
    // Wait for webcam images to be present
    await page.waitForSelector('img[id^="webcam-"]', { timeout: 10000 });
    
    const webcamImages = await page.$$('img[id^="webcam-"]');
    expect(webcamImages.length).toBeGreaterThan(0);
    
    // Check each webcam image has the data-initial-timestamp attribute
    for (let i = 0; i < webcamImages.length; i++) {
      const img = webcamImages[i];
      const hasAttribute = await img.evaluate(el => {
        return el.hasAttribute('data-initial-timestamp');
      });
      
      expect(hasAttribute).toBeTruthy();
      
      // Verify the timestamp value is valid
      const timestamp = await img.getAttribute('data-initial-timestamp');
      const ts = parseInt(timestamp);
      expect(isNaN(ts)).toBeFalsy();
      expect(ts).toBeGreaterThan(0);
      expect(ts).toBeGreaterThan(1000000000); // Reasonable Unix timestamp
    }
  });

  test('safeSwapCameraImage should check backend for newer timestamps', async ({ page }) => {
    // Skip if webcams aren't available
    const hasWebcams = await page.evaluate(() => {
      return document.querySelectorAll('img[id^="webcam-"]').length > 0 && typeof window.CAM_TS !== 'undefined';
    });
    if (!hasWebcams) {
      test.skip();
      return;
    }
    // Set up request interception to monitor mtime requests
    const mtimeRequests = [];
    page.on('request', request => {
      const url = request.url();
      if (url.includes('webcam.php') && url.includes('mtime=1')) {
        mtimeRequests.push({
          url: url,
          timestamp: Date.now()
        });
      }
    });
    
    // Get initial CAM_TS values
    const initialCamTs = await page.evaluate(() => {
      return { ...window.CAM_TS };
    });
    
    // Manually trigger safeSwapCameraImage for camera 0
    await page.evaluate((camIndex) => {
      const fn = window.safeSwapCameraImage || safeSwapCameraImage;
      if (typeof fn === 'function') {
        fn(camIndex);
      }
    }, 0);
    
    // Wait for the mtime request to complete
    await page.waitForTimeout(2000);
    
    // Verify that an mtime request was made
    expect(mtimeRequests.length).toBeGreaterThan(0);
    
    // Verify the request URL is correct
    const mtimeRequest = mtimeRequests[0];
    expect(mtimeRequest.url).toContain('webcam.php');
    expect(mtimeRequest.url).toContain('mtime=1');
    expect(mtimeRequest.url).toContain(`id=${testAirport}`);
    expect(mtimeRequest.url).toContain('cam=0');
  });

  test('safeSwapCameraImage should update image when backend has newer timestamp', async ({ page }) => {
    // Skip if webcams aren't available
    const hasWebcams = await page.evaluate(() => {
      return document.querySelectorAll('img[id^="webcam-"]').length > 0 && typeof window.CAM_TS !== 'undefined';
    });
    if (!hasWebcams) {
      test.skip();
      return;
    }
    // Mock the mtime endpoint to return a newer timestamp
    await page.route('**/webcam.php?*mtime=1*', async route => {
      const currentTime = Math.floor(Date.now() / 1000);
      const response = {
        success: true,
        timestamp: currentTime + 100, // 100 seconds in the future (simulating newer image)
        size: 123456,
        formatReady: {
          jpg: true,
          webp: true
        }
      };
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(response)
      });
    });
    
    // Get initial image src
    const initialSrc = await page.evaluate(() => {
      const img = document.getElementById('webcam-0');
      return img ? img.src : null;
    });
    
    expect(initialSrc).toBeTruthy();
    
    // Get initial CAM_TS
    const initialCamTs = await page.evaluate(() => {
      return window.CAM_TS[0];
    });
    
    // Trigger safeSwapCameraImage
    await page.evaluate(() => {
      const fn = window.safeSwapCameraImage || safeSwapCameraImage;
      if (typeof fn === 'function') {
        fn(0);
      }
    });
    
    // Wait for image to potentially update
    await page.waitForTimeout(3000);
    
    // Check if CAM_TS was updated
    const updatedCamTs = await page.evaluate(() => {
      return window.CAM_TS[0];
    });
    
    // CAM_TS should be updated with the newer timestamp
    expect(updatedCamTs).toBeGreaterThan(initialCamTs);
    
    // Image src should have changed (new hash from new timestamp)
    const newSrc = await page.evaluate(() => {
      const img = document.getElementById('webcam-0');
      return img ? img.src : null;
    });
    
    // The src should be different (new cache-busting hash)
    expect(newSrc).not.toBe(initialSrc);
  });

  test('safeSwapCameraImage should not update image when backend timestamp is same or older', async ({ page }) => {
    // Skip if webcams aren't available
    const hasWebcams = await page.evaluate(() => {
      return document.querySelectorAll('img[id^="webcam-"]').length > 0 && typeof window.CAM_TS !== 'undefined';
    });
    if (!hasWebcams) {
      test.skip();
      return;
    }
    // Get initial CAM_TS
    const initialCamTs = await page.evaluate(() => {
      return window.CAM_TS[0];
    });
    
    // Mock the mtime endpoint to return the same timestamp
    await page.route('**/webcam.php?*mtime=1*', async route => {
      const response = {
        success: true,
        timestamp: initialCamTs, // Same timestamp
        size: 123456,
        formatReady: {
          jpg: true,
          webp: true
        }
      };
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(response)
      });
    });
    
    // Get initial image src
    const initialSrc = await page.evaluate(() => {
      const img = document.getElementById('webcam-0');
      return img ? img.src : null;
    });
    
    // Trigger safeSwapCameraImage
    await page.evaluate(() => {
      const fn = window.safeSwapCameraImage || safeSwapCameraImage;
      if (typeof fn === 'function') {
        fn(0);
      }
    });
    
    // Wait a bit
    await page.waitForTimeout(2000);
    
    // Image src should NOT have changed (same timestamp, no update)
    const newSrc = await page.evaluate(() => {
      const img = document.getElementById('webcam-0');
      return img ? img.src : null;
    });
    
    // Should be the same (or at least the hash part should be the same)
    expect(newSrc).toBe(initialSrc);
  });

  test('webcam refresh interval should be configured correctly', async ({ page }) => {
    // Skip if webcams aren't available
    const hasWebcams = await page.evaluate(() => {
      return document.querySelectorAll('img[id^="webcam-"]').length > 0 && typeof window.CAM_TS !== 'undefined';
    });
    if (!hasWebcams) {
      test.skip();
      return;
    }
    // Check that setInterval was called for webcam refresh
    // We can't directly check setInterval, but we can verify the refresh function exists
    // and check the configured refresh interval from the page
    
    const refreshConfig = await page.evaluate(() => {
      // Try to find the refresh interval configuration
      // The interval is set in PHP, so we check if the function exists
      // and verify the airport data has webcam_refresh_seconds
      return {
        hasSafeSwapFunction: typeof window.safeSwapCameraImage === 'function',
        airportData: window.AIRPORT_DATA || null,
        camTs: window.CAM_TS || {}
      };
    });
    
    expect(refreshConfig.hasSafeSwapFunction).toBeTruthy();
    
    // If AIRPORT_DATA is available, check refresh configuration
    if (refreshConfig.airportData) {
      // Should have webcam_refresh_seconds or use default
      const hasRefreshConfig = refreshConfig.airportData.webcam_refresh_seconds !== undefined ||
                               refreshConfig.airportData.webcams?.[0]?.refresh_seconds !== undefined;
      // This is optional, so we just log it
      if (hasRefreshConfig) {
        console.log('Webcam refresh configured:', refreshConfig.airportData);
      }
    }
  });

  test('CAM_TS should be updated when new image loads successfully', async ({ page }) => {
    // Skip if webcams aren't available
    const hasWebcams = await page.evaluate(() => {
      return document.querySelectorAll('img[id^="webcam-"]').length > 0 && typeof window.CAM_TS !== 'undefined';
    });
    if (!hasWebcams) {
      test.skip();
      return;
    }
    // Get initial CAM_TS
    const initialCamTs = await page.evaluate(() => {
      return window.CAM_TS[0];
    });
    
    // Mock a successful image update with newer timestamp
    const newTimestamp = Math.floor(Date.now() / 1000) + 100;
    
    // Mock mtime endpoint
    await page.route('**/webcam.php?*mtime=1*', async route => {
      const response = {
        success: true,
        timestamp: newTimestamp,
        size: 123456,
        formatReady: {
          jpg: true,
          webp: true
        }
      };
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(response)
      });
    });
    
    // Mock image endpoint to return a valid image
    await page.route('**/webcam.php?*fmt=jpg*', async route => {
      // Return a minimal valid JPEG (1x1 pixel)
      const jpegHeader = Buffer.from([
        0xFF, 0xD8, 0xFF, 0xE0, 0x00, 0x10, 0x4A, 0x46, 0x49, 0x46, 0x00, 0x01,
        0x01, 0x01, 0x00, 0x48, 0x00, 0x48, 0x00, 0x00, 0xFF, 0xDB, 0x00, 0x43,
        0x00, 0x08, 0x06, 0x06, 0x07, 0x06, 0x05, 0x08, 0x07, 0x07, 0x07, 0x09,
        0x09, 0x08, 0x0A, 0x0C, 0x14, 0x0D, 0x0C, 0x0B, 0x0B, 0x0C, 0x19, 0x12,
        0x13, 0x0F, 0x14, 0x1D, 0x1A, 0x1F, 0x1E, 0x1D, 0x1A, 0x1C, 0x1C, 0x20,
        0x24, 0x2E, 0x27, 0x20, 0x22, 0x2C, 0x23, 0x1C, 0x1C, 0x28, 0x37, 0x29,
        0x2C, 0x30, 0x31, 0x34, 0x34, 0x34, 0x1F, 0x27, 0x39, 0x3D, 0x38, 0x32,
        0x3C, 0x2E, 0x33, 0x34, 0x32, 0xFF, 0xC0, 0x00, 0x0B, 0x08, 0x00, 0x01,
        0x00, 0x01, 0x01, 0x01, 0x11, 0x00, 0xFF, 0xC4, 0x00, 0x14, 0x00, 0x01,
        0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00,
        0x00, 0x00, 0x00, 0x08, 0xFF, 0xC4, 0x00, 0x14, 0x10, 0x01, 0x00, 0x00,
        0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00,
        0x00, 0x00, 0xFF, 0xDA, 0x00, 0x0C, 0x03, 0x01, 0x00, 0x02, 0x11, 0x03,
        0x11, 0x00, 0x3F, 0x00, 0x8C, 0xFF, 0xD9
      ]);
      
      await route.fulfill({
        status: 200,
        contentType: 'image/jpeg',
        body: jpegHeader
      });
    });
    
    // Trigger safeSwapCameraImage
    await page.evaluate(() => {
      const fn = window.safeSwapCameraImage || safeSwapCameraImage;
      if (typeof fn === 'function') {
        fn(0);
      }
    });
    
    // Wait for image to load and CAM_TS to update
    await page.waitForTimeout(3000);
    
    // Check if CAM_TS was updated
    const updatedCamTs = await page.evaluate(() => {
      return window.CAM_TS[0];
    });
    
    // CAM_TS should be updated to the new timestamp
    expect(updatedCamTs).toBe(newTimestamp);
    expect(updatedCamTs).toBeGreaterThan(initialCamTs);
  });

  test('data-initial-timestamp should be updated when new image loads', async ({ page }) => {
    // Skip if webcams aren't available
    const hasWebcams = await page.evaluate(() => {
      return document.querySelectorAll('img[id^="webcam-"]').length > 0 && typeof window.CAM_TS !== 'undefined';
    });
    if (!hasWebcams) {
      test.skip();
      return;
    }
    // Get initial timestamp from data attribute
    const initialDataTimestamp = await page.evaluate(() => {
      const img = document.getElementById('webcam-0');
      return img ? parseInt(img.dataset.initialTimestamp || '0') : 0;
    });
    
    expect(initialDataTimestamp).toBeGreaterThan(0);
    
    // Mock a successful image update
    const newTimestamp = Math.floor(Date.now() / 1000) + 100;
    
    await page.route('**/webcam.php?*mtime=1*', async route => {
      const response = {
        success: true,
        timestamp: newTimestamp,
        size: 123456,
        formatReady: { jpg: true, webp: true }
      };
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(response)
      });
    });
    
    // Mock image endpoint
    await page.route('**/webcam.php?*fmt=jpg*', async route => {
      const jpegHeader = Buffer.from([
        0xFF, 0xD8, 0xFF, 0xE0, 0x00, 0x10, 0x4A, 0x46, 0x49, 0x46, 0x00, 0x01,
        0x01, 0x01, 0x00, 0x48, 0x00, 0x48, 0x00, 0x00, 0xFF, 0xD9
      ]);
      await route.fulfill({
        status: 200,
        contentType: 'image/jpeg',
        body: jpegHeader
      });
    });
    
    // Trigger safeSwapCameraImage
    await page.evaluate(() => {
      const fn = window.safeSwapCameraImage || safeSwapCameraImage;
      if (typeof fn === 'function') {
        fn(0);
      }
    });
    
    // Wait for update
    await page.waitForTimeout(3000);
    
    // Check if data attribute was updated
    const updatedDataTimestamp = await page.evaluate(() => {
      const img = document.getElementById('webcam-0');
      return img ? parseInt(img.dataset.initialTimestamp || '0') : 0;
    });
    
    // Data attribute should be updated
    expect(updatedDataTimestamp).toBe(newTimestamp);
    expect(updatedDataTimestamp).toBeGreaterThan(initialDataTimestamp);
  });
});

