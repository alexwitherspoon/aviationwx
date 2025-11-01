<?php
/**
 * PHPUnit Bootstrap
 * Sets up test environment and includes required files
 */

// Set test environment (use defined() check to avoid redefinition warnings)
if (!defined('AVIATIONWX_LOG_DIR')) {
    define('AVIATIONWX_LOG_DIR', sys_get_temp_dir() . '/aviationwx_test_logs');
}
if (!defined('AVIATIONWX_LOG_FILE')) {
    define('AVIATIONWX_LOG_FILE', AVIATIONWX_LOG_DIR . '/app.log');
}

// Create test log directory
@mkdir(AVIATIONWX_LOG_DIR, 0755, true);

// Load required files
require_once __DIR__ . '/../config-utils.php';
require_once __DIR__ . '/../rate-limit.php';
require_once __DIR__ . '/../logger.php';
require_once __DIR__ . '/../weather.php';

// Load test helpers (must be loaded before test files that use them)
if (file_exists(__DIR__ . '/Helpers/TestHelper.php')) {
    require_once __DIR__ . '/Helpers/TestHelper.php';
}

