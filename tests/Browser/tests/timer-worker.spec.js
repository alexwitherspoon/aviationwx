const { test, expect } = require('@playwright/test');

/**
 * Timer Worker Tests
 * 
 * Tests for the timer worker system that provides reliable refresh
 * for webcams and weather data.
 */
test.describe('Timer Worker System', () => {
  const baseUrl = process.env.TEST_BASE_URL || 'http://localhost:8080';
  const testAirport = 'kspb';
  
  test('Timer worker should be created', async ({ page }) => {
    const errors = [];
    
    page.on('console', msg => {
      if (msg.type() === 'error') {
        errors.push(msg.text());
      }
    });
    
    page.on('pageerror', error => {
      errors.push(error.message);
    });
    
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });
    
    // Wait for timer worker initialization
    await page.waitForTimeout(1000);
    
    // Check that timer worker exists
    const hasTimerWorker = await page.evaluate(() => {
      return typeof window.aviationwxTimerWorker !== 'undefined' && 
             window.aviationwxTimerWorker !== null;
    });
    
    // If no Worker support, check for fallback
    if (!hasTimerWorker) {
      const hasFallback = await page.evaluate(() => {
        return typeof window.usingFallbackTimer !== 'undefined' ||
               typeof window.aviationwxFallbackTimer !== 'undefined';
      });
      expect(hasFallback).toBe(true);
    } else {
      expect(hasTimerWorker).toBe(true);
    }
    
    // Should have no critical errors
    const criticalErrors = errors.filter(e => 
      e.includes('ReferenceError') || 
      e.includes('TypeError') ||
      e.includes('SyntaxError')
    );
    expect(criticalErrors).toHaveLength(0);
  });

  test('Timer worker should have correct tick interval based on device type', async ({ page }) => {
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });
    
    // Check tick interval configuration
    const tickConfig = await page.evaluate(() => {
      return {
        isMobile: typeof TIMER_IS_MOBILE !== 'undefined' ? TIMER_IS_MOBILE : null,
        tickMs: typeof TIMER_TICK_MS !== 'undefined' ? TIMER_TICK_MS : null
      };
    });
    
    if (tickConfig.tickMs !== null) {
      // Desktop should be 1000ms, mobile should be 10000ms
      expect([1000, 10000]).toContain(tickConfig.tickMs);
    }
  });

  test('registerTimer function should be available', async ({ page }) => {
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });
    
    const hasRegisterTimer = await page.evaluate(() => {
      return typeof registerTimer === 'function';
    });
    
    expect(hasRegisterTimer).toBe(true);
  });

  test('Weather timer should be registered', async ({ page }) => {
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });
    
    // Wait for initialization
    await page.waitForTimeout(2000);
    
    // Check timer callbacks map has weather registered
    const hasWeatherTimer = await page.evaluate(() => {
      return typeof timerCallbacks !== 'undefined' && timerCallbacks.has('weather');
    });
    
    // Weather timer should be registered if airport has weather source
    const hasWeatherSource = await page.evaluate(() => {
      return typeof AIRPORT_DATA !== 'undefined' && 
             (AIRPORT_DATA.weather_source || AIRPORT_DATA.metar_station);
    });
    
    if (hasWeatherSource) {
      expect(hasWeatherTimer).toBe(true);
    }
  });

  test('Webcam timers should be registered', async ({ page }) => {
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });
    
    // Wait for initialization
    await page.waitForTimeout(2000);
    
    // Count webcams on page
    const webcamCount = await page.evaluate(() => {
      return document.querySelectorAll('[id^="webcam-"]').length;
    });
    
    if (webcamCount > 0) {
      // Check that webcam timers are registered
      const webcamTimersRegistered = await page.evaluate(() => {
        if (typeof timerCallbacks === 'undefined') return 0;
        let count = 0;
        for (const key of timerCallbacks.keys()) {
          if (key.startsWith('webcam-')) count++;
        }
        return count;
      });
      
      expect(webcamTimersRegistered).toBeGreaterThan(0);
    }
  });

  test('timer-lifecycle.js should load', async ({ page }) => {
    const lifecycleLoaded = { loaded: false };
    
    page.on('response', response => {
      if (response.url().includes('timer-lifecycle.js')) {
        lifecycleLoaded.loaded = true;
      }
    });
    
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });
    
    // Wait for deferred script to load
    await page.waitForTimeout(1000);
    
    expect(lifecycleLoaded.loaded).toBe(true);
  });

  test('forceRefreshAllTimers function should be available', async ({ page }) => {
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });
    
    const hasForceRefresh = await page.evaluate(() => {
      return typeof window.forceRefreshAllTimers === 'function';
    });
    
    expect(hasForceRefresh).toBe(true);
  });

  test('Timer worker console logs should indicate initialization', async ({ page }) => {
    const timerLogs = [];
    
    page.on('console', msg => {
      if (msg.text().includes('[TimerWorker]') || 
          msg.text().includes('[TimerLifecycle]') ||
          msg.text().includes('[Weather] Registered') ||
          msg.text().includes('[Webcam] Registered')) {
        timerLogs.push(msg.text());
      }
    });
    
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });
    
    // Wait for initialization logs
    await page.waitForTimeout(2000);
    
    // Should have some timer-related console logs
    expect(timerLogs.length).toBeGreaterThan(0);
  });

  test('Version cookie should be set', async ({ page, context }) => {
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });
    
    // Get cookies
    const cookies = await context.cookies();
    const versionCookie = cookies.find(c => c.name === 'aviationwx_v');
    
    expect(versionCookie).toBeDefined();
    
    if (versionCookie) {
      // Cookie value should be in format: hash.timestamp
      expect(versionCookie.value).toMatch(/^[a-f0-9]+\.\d+$/);
      
      // Cookie should be set for base domain (cross-subdomain)
      // In test env may be localhost
    }
  });
});

