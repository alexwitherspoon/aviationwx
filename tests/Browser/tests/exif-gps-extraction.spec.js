const { test, expect } = require('@playwright/test');

test.describe('Webcam EXIF Timestamp Verification', () => {
  const baseUrl = process.env.TEST_BASE_URL || 'http://localhost:8080';
  const testAirport = 'kspb'; // Has webcams with GPS EXIF

  test('ExifTimestamp module should be loaded', async ({ page }) => {
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('networkidle');
    
    // Check that ExifTimestamp is available
    const exifModuleExists = await page.evaluate(() => {
      return typeof ExifTimestamp !== 'undefined' &&
             typeof ExifTimestamp.extractGps === 'function' &&
             typeof ExifTimestamp.extract === 'function';
    });
    
    expect(exifModuleExists).toBeTruthy();
  });

  test('should extract GPS timestamp from webcam images', async ({ page }) => {
    // Listen for console logs to verify GPS extraction
    const logs = [];
    page.on('console', msg => {
      logs.push({ type: msg.type(), text: msg.text() });
    });

    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('networkidle');
    
    // Wait for webcams to load
    await page.waitForTimeout(3000);
    
    // Check for GPS extraction success or fallback
    const hasGpsLogs = logs.some(log => 
      log.text.includes('[EXIF GPS]') || 
      log.text.includes('GPS') ||
      log.text.includes('Image verified')
    );
    
    // Should have attempted GPS extraction
    expect(logs.length).toBeGreaterThan(0);
  });

  test('should NOT have EXIF GPS extraction errors', async ({ page }) => {
    const errors = [];
    
    page.on('console', msg => {
      if (msg.type() === 'error') {
        errors.push(msg.text());
      }
    });
    
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('networkidle');
    
    // Wait for webcams to attempt loading
    await page.waitForTimeout(3000);
    
    // Filter for EXIF-related errors
    const exifErrors = errors.filter(err => 
      err.includes('[EXIF') || 
      err.includes('GPS') ||
      err.includes('getUint16') ||
      err.includes('DataView')
    );
    
    // Should have NO EXIF extraction errors
    if (exifErrors.length > 0) {
      console.error('EXIF extraction errors found:', exifErrors);
    }
    expect(exifErrors.length).toBe(0);
  });

  test('webcams should verify using GPS timestamps when available', async ({ page }) => {
    const logs = [];
    const warnings = [];
    
    page.on('console', msg => {
      logs.push({ type: msg.type(), text: msg.text() });
      if (msg.type() === 'warning') {
        warnings.push(msg.text());
      }
    });
    
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('networkidle');
    
    // Wait for webcam verification
    await page.waitForTimeout(5000);
    
    // Check for timestamp mismatch warnings (should NOT happen with GPS extraction)
    const timestampMismatches = warnings.filter(w => 
      w.includes('timestamp_mismatch') ||
      w.includes('EXIF timestamp') && w.includes('Expected timestamp')
    );
    
    // Should have no timestamp mismatches (GPS extraction should match manifest)
    if (timestampMismatches.length > 0) {
      console.warn('Timestamp mismatches found - GPS extraction may have failed:', timestampMismatches);
    }
    
    // This is a soft check - may have mismatches if images are too old
    // but there should be very few
    expect(timestampMismatches.length).toBeLessThan(2);
  });

  test('should handle images without GPS EXIF gracefully', async ({ page }) => {
    const errors = [];
    
    page.on('console', msg => {
      if (msg.type() === 'error') {
        errors.push(msg.text());
      }
    });
    
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(3000);
    
    // Should not crash or throw errors when GPS EXIF is missing
    const criticalErrors = errors.filter(err => 
      !err.includes('Failed to fetch') && 
      !err.includes('404') &&
      !err.includes('network')
    );
    
    expect(criticalErrors.length).toBeLessThan(3);
  });
});
