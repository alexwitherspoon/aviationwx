<?php
/**
 * Prepend for CLI worker tests: isolate CACHE_BASE_DIR before cache-paths.php defines it.
 */
declare(strict_types=1);

if (!defined('CACHE_BASE_DIR')) {
    $dir = sys_get_temp_dir() . '/aviationwx_cli_worker_test_' . getmypid() . '_' . bin2hex(random_bytes(3));
    @mkdir($dir . '/metrics/hourly', 0700, true);
    @mkdir($dir . '/metrics/daily', 0700, true);
    @mkdir($dir . '/metrics/weekly', 0700, true);
    @mkdir($dir . '/metrics/spill', 0700, true);
    define('CACHE_BASE_DIR', $dir);

    register_shutdown_function(static function () use ($dir): void {
        if (!is_dir($dir) || !str_contains($dir, 'aviationwx_cli_worker_test_')) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            if ($item->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    });
}
