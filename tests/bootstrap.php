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
// For tests, use file-based logging (not stdout/stderr) so we can verify log output
if (!defined('AVIATIONWX_LOG_TO_STDOUT')) {
    define('AVIATIONWX_LOG_TO_STDOUT', false);
}
if (!defined('AVIATIONWX_LOG_DIR')) {
    define('AVIATIONWX_LOG_DIR', sys_get_temp_dir() . '/aviationwx_test_logs');
}
if (!defined('AVIATIONWX_LOG_FILE')) {
    define('AVIATIONWX_LOG_FILE', AVIATIONWX_LOG_DIR . '/app.log');
}

// Create test log directory and both log files (only needed for file-based logging)
if (!AVIATIONWX_LOG_TO_STDOUT) {
    @mkdir(AVIATIONWX_LOG_DIR, 0755, true);
    @touch(AVIATIONWX_LOG_DIR . '/user.log');
    @touch(AVIATIONWX_LOG_DIR . '/app.log');
}

// Include core application files for testing (only those that don't have endpoint logic)
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/rate-limit.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../api/weather.php'; // api/weather.php now has a conditional to prevent endpoint execution
require_once __DIR__ . '/../lib/seo.php'; // SEO utilities for testing

// Load test helpers (must be loaded before test files that use them)
if (file_exists(__DIR__ . '/Helpers/TestHelper.php')) {
    require_once __DIR__ . '/Helpers/TestHelper.php';
}

// Ensure the test airports.json is used if CONFIG_PATH is not set
if (!getenv('CONFIG_PATH') && file_exists(__DIR__ . '/Fixtures/airports.json.test')) {
    putenv('CONFIG_PATH=' . __DIR__ . '/Fixtures/airports.json.test');
}

