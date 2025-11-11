// @ts-check
const { defineConfig, devices } = require('@playwright/test');

/**
 * Playwright Configuration for Browser Compatibility Tests
 * Tests across Chrome, Firefox, Safari/WebKit, and Edge
 */
module.exports = defineConfig({
  testDir: './tests',
  
  // Output directory for test results
  outputDir: './test-results',
  
  // Test timeout (increased to 30s for CI stability - network requests can be slow)
  timeout: 30000,
  
  // Run tests in parallel
  fullyParallel: true,
  
  // Fail the build on CI if you accidentally left test.only in the source code
  forbidOnly: !!process.env.CI,
  
  // Retry on CI only (disabled for speed - failures are caught by other test suites)
  retries: 0,
  
  // Run tests in parallel in CI (increased from 1 to 4 workers for speed)
  // Use 4 workers in CI - Ubuntu runners have enough resources
  workers: process.env.CI ? 4 : undefined,
  
  // Reporter configuration
  reporter: [
    ['list'],
    ['html', { outputFolder: 'playwright-report' }],
    ['json', { outputFile: 'playwright-results.json' }]
  ],
  
  // Shared settings for all projects
  use: {
    baseURL: process.env.TEST_BASE_URL || 'http://localhost:8080',
    trace: 'off', // Disable trace for speed
    screenshot: 'only-on-failure',
    video: 'off', // Disable video for speed
  },

  // Configure projects for major browsers
  // In CI: Test critical browsers only (Chromium desktop + mobile for speed)
  // In local dev: Test all browsers including Edge and mobile/tablet
  projects: process.env.CI ? [
    // Core browser for desktop (Chromium only - fastest and most compatible)
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
    // Mobile viewport for responsive testing (most important)
    {
      name: 'Mobile Chrome',
      use: { ...devices['Pixel 5'] },
    },
    // Optionally add Firefox if time allows (commented out for speed)
    // {
    //   name: 'firefox',
    //   use: { ...devices['Desktop Firefox'] },
    // },
  ] : [
    // All browsers in local development
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'firefox',
      use: { ...devices['Desktop Firefox'] },
    },
    {
      name: 'webkit',
      use: { ...devices['Desktop Safari'] },
    },
    {
      name: 'Microsoft Edge',
      use: { ...devices['Desktop Edge'], channel: 'msedge' },
    },
    // Mobile viewports
    {
      name: 'Mobile Chrome',
      use: { ...devices['Pixel 5'] },
    },
    {
      name: 'Mobile Safari',
      use: { ...devices['iPhone 12'] },
    },
    // Tablet viewports
    {
      name: 'Tablet',
      use: { ...devices['iPad Pro'] },
    },
  ],

  // Run your local dev server before starting the tests (disabled - handled by CI)
  // webServer: {
  //   command: 'docker compose up -d && sleep 10',
  //   url: 'http://localhost:8080',
  //   reuseExistingServer: !process.env.CI,
  //   timeout: 120000,
  // },
});

