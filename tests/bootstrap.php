<?php
/**
 * PHPUnit Bootstrap
 * Sets up test environment and includes required files
 */

// Ensure Composer autoloader is available (required for PHPUnit)
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} else {
    echo 'Please run "composer install" to install dependencies.' . PHP_EOL;
    exit(1);
}

// Set test environment (use defined() check to avoid redefinition warnings)
// Use temporary directory for test logs to avoid polluting production log directory
if (!defined('AVIATIONWX_LOG_DIR')) {
    define('AVIATIONWX_LOG_DIR', sys_get_temp_dir() . '/aviationwx_test_logs');
}
if (!defined('AVIATIONWX_LOG_FILE')) {
    define('AVIATIONWX_LOG_FILE', AVIATIONWX_LOG_DIR . '/app.log');
}

// Create test log directory and log files
@mkdir(AVIATIONWX_LOG_DIR, 0755, true);
@touch(AVIATIONWX_LOG_DIR . '/user.log');
@touch(AVIATIONWX_LOG_DIR . '/app.log');

// Include core application files for testing (only those that don't have endpoint logic)
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/cache-paths.php';
require_once __DIR__ . '/../lib/rate-limit.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../api/weather.php'; // api/weather.php now has a conditional to prevent endpoint execution
require_once __DIR__ . '/../lib/seo.php'; // SEO utilities for testing

// Load test helpers (must be loaded before test files that use them)
if (file_exists(__DIR__ . '/Helpers/TestHelper.php')) {
    require_once __DIR__ . '/Helpers/TestHelper.php';
}

// Ensure the test airports.json is used if CONFIG_PATH is not set
// Note: airports.json is NOT in repository - only exists on production host
// CI (GitHub Actions) never has access to airports.json, so tests use fixtures
if (!getenv('CONFIG_PATH') && file_exists(__DIR__ . '/Fixtures/airports.json.test')) {
    putenv('CONFIG_PATH=' . __DIR__ . '/Fixtures/airports.json.test');
}

/**
 * Check if we're running in CI (GitHub Actions)
 * 
 * @return bool True if running in CI, false otherwise
 */
function isRunningInCI(): bool {
    return getenv('CI') === 'true' || getenv('GITHUB_ACTIONS') === 'true';
}

/**
 * Clean test-affected cache files
 * 
 * Removes all cache files that tests create or modify:
 * - Circuit breaker state (backoff.json)
 * - Weather cache files (weather_*.json)
 * - Webcam images (webcams/*.jpg, *.webp)
 * - Daily tracking files (peak_gusts.json, temp_extremes.json)
 * - Outage detection files (outage_*.json)
 * - Rate limiting files (rate_limit_*.json)
 * - Test backup files (*.backup)
 * 
 * Preserves reference data files (mappings, ourairports data, push_webcams)
 */
function cleanTestCache(): void {
    $cacheDir = CACHE_BASE_DIR;
    if (!is_dir($cacheDir)) {
        return;
    }
    
    // Files to clean (test-affected)
    $filesToClean = [
        CACHE_BACKOFF_FILE,
        CACHE_PEAK_GUSTS_FILE,
        CACHE_TEMP_EXTREMES_FILE,
    ];
    
    // Patterns to clean in weather directory
    $weatherPatterns = [
        CACHE_WEATHER_DIR . 'weather_*.json',
        CACHE_WEATHER_DIR . 'outage_*.json',
    ];
    
    // Patterns to clean in rate limits directory
    $rateLimitPatterns = [
        CACHE_RATE_LIMITS_DIR . '*.json',
    ];
    
    // Patterns to clean in base cache directory
    $basePatterns = [
        $cacheDir . '/*.backup',
    ];
    
    // Clean specific files
    foreach ($filesToClean as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    
    // Clean files matching weather patterns
    foreach ($weatherPatterns as $pattern) {
        $files = glob($pattern);
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
    
    // Clean files matching rate limit patterns
    foreach ($rateLimitPatterns as $pattern) {
        $files = glob($pattern);
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
    
    // Clean files matching base patterns
    foreach ($basePatterns as $pattern) {
        $files = glob($pattern);
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
    
    // Clean webcams directory (all image formats and staging files)
    $webcamsDir = CACHE_WEBCAMS_DIR;
    if (is_dir($webcamsDir)) {
        $imagePatterns = ['*.jpg', '*.webp', '*.tmp'];
        foreach ($imagePatterns as $pattern) {
            $files = glob($webcamsDir . '/' . $pattern);
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }
    
    // Clean .DS_Store files (macOS system files)
    $dsStore = $cacheDir . '/.DS_Store';
    if (is_file($dsStore)) {
        @unlink($dsStore);
    }
}

/**
 * Clean test result files (local dev only, not in CI)
 * 
 * Removes all test result files generated by PHPUnit and Playwright:
 * - PHPUnit coverage reports (coverage/, *.coverage.xml)
 * - PHPUnit JUnit results (*-results.xml)
 * - PHPUnit cache (.phpunit.cache/, .phpunit.result.cache)
 * - Playwright reports (playwright-report/, playwright-results.json, test-results/)
 * 
 * Does NOT clean in CI - CI needs these files for artifact uploads
 */
function cleanTestResultFiles(): void {
    // Don't clean in CI - CI needs these files for artifact uploads
    if (isRunningInCI()) {
        return;
    }
    
    $baseDir = __DIR__ . '/..';
    
    // PHPUnit coverage and cache directories
    $phpunitDirs = [
        $baseDir . '/coverage',
        $baseDir . '/.phpunit.cache',
    ];
    
    foreach ($phpunitDirs as $dir) {
        if (is_dir($dir)) {
            // Recursively delete directory
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                if ($file->isDir()) {
                    @rmdir($file->getRealPath());
                } else {
                    @unlink($file->getRealPath());
                }
            }
            @rmdir($dir);
        }
    }
    
    // PHPUnit cache file
    $phpunitCacheFile = $baseDir . '/.phpunit.result.cache';
    if (is_file($phpunitCacheFile)) {
        @unlink($phpunitCacheFile);
    }
    
    // PHPUnit XML result files
    $xmlPatterns = [
        $baseDir . '/*-results.xml',
        $baseDir . '/*-coverage.xml',
        $baseDir . '/coverage.xml',
    ];
    
    foreach ($xmlPatterns as $pattern) {
        $files = glob($pattern);
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
    
    // Playwright test results
    $playwrightFiles = [
        $baseDir . '/tests/Browser/playwright-report',
        $baseDir . '/tests/Browser/playwright-results.json',
        $baseDir . '/tests/Browser/test-results',
    ];
    
    foreach ($playwrightFiles as $path) {
        if (is_file($path)) {
            @unlink($path);
        } elseif (is_dir($path)) {
            // Recursively delete directory
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                if ($file->isDir()) {
                    @rmdir($file->getRealPath());
                } else {
                    @unlink($file->getRealPath());
                }
            }
            @rmdir($path);
        }
    }
}

/**
 * Clean temporary test files in system temp directory
 * 
 * Removes temporary files and directories created by tests:
 * - aviationwx_test_* directories
 * - webcam_*_test_* files
 * - test_webcam_fetch_* files
 * - aviationwx_test_logs directory
 */
function cleanTestTempFiles(): void {
    $tempDir = sys_get_temp_dir();
    
    // Clean test log directory
    $testLogDir = $tempDir . '/aviationwx_test_logs';
    if (is_dir($testLogDir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($testLogDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir()) {
                @rmdir($file->getRealPath());
            } else {
                @unlink($file->getRealPath());
            }
        }
        @rmdir($testLogDir);
    }
    
    // Clean test temporary directories (aviationwx_test_*)
    $testDirs = glob($tempDir . '/aviationwx_test_*', GLOB_ONLYDIR);
    foreach ($testDirs as $dir) {
        if (is_dir($dir)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                if ($file->isDir()) {
                    @rmdir($file->getRealPath());
                } else {
                    @unlink($file->getRealPath());
                }
            }
            @rmdir($dir);
        }
    }
    
    // Clean webcam test files
    $webcamPatterns = [
        $tempDir . '/webcam_*_test_*',
        $tempDir . '/test_webcam_fetch_*',
    ];
    
    foreach ($webcamPatterns as $pattern) {
        $files = glob($pattern);
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
}

// Clean before tests start (ensures clean, repeatable test state)
if (getenv('APP_ENV') === 'testing') {
    cleanTestCache();
    cleanTestResultFiles();
    cleanTestTempFiles();
}

// Register cleanup after tests (allows normal operation to resume)
register_shutdown_function(function() {
    if (getenv('APP_ENV') === 'testing') {
        cleanTestCache();
        cleanTestResultFiles();
        cleanTestTempFiles();
    }
});

