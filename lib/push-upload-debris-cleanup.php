<?php
/**
 * Push upload inbox debris cleanup (FTP/SFTP).
 *
 * Uses {@see getPushUploadAllowedExtensionsForCleanup()} so the keep-list matches
 * `config.push_upload_allowed_extensions` (optional), each push camera's `allowed_extensions`,
 * and defaults to {@see push_upload_master_image_extensions()}.
 *
 * Anything not in that effective allowlist (including extensionless files) is eligible for age-based
 * deletion so disk cannot grow without bound when vendors upload video or sidecars.
 */

require_once __DIR__ . '/cache-paths.php';
require_once __DIR__ . '/config.php';

/**
 * Collect roots to scan: FTP cache tree plus each SFTP user's files/ directory when present.
 *
 * @return array<int, string>
 */
function push_upload_debris_default_roots(): array
{
    $roots = [CACHE_UPLOADS_DIR];

    if (is_dir(CACHE_SFTP_DIR) && is_readable(CACHE_SFTP_DIR)) {
        $filesDirs = glob(rtrim(CACHE_SFTP_DIR, '/') . '/*/files', GLOB_ONLYDIR) ?: [];
        foreach ($filesDirs as $dir) {
            if (is_readable($dir)) {
                $roots[] = $dir;
            }
        }
    }

    return $roots;
}

/**
 * Remove stale files that are not in the effective push upload allowlist.
 *
 * @param int               $maxAgeSeconds Minimum age (mtime) before a file is eligible
 * @param array<string, int> $stats Mutated: files_checked, files_deleted, bytes_freed, errors
 * @param bool              $dryRun        When true, count only
 * @param bool              $verbose       Extra per-file output
 * @param array<int,string>|null $rootsOverride For tests; when null, uses {@see push_upload_debris_default_roots()}
 * @param array<int,string>|null $allowedExtensionsOverride When non-null, extensions to keep (lowercase); when null, uses {@see getPushUploadAllowedExtensionsForCleanup()}
 */
function cleanupPushUploadDebris(
    int $maxAgeSeconds,
    array &$stats,
    bool $dryRun,
    bool $verbose,
    ?array $rootsOverride = null,
    ?array $allowedExtensionsOverride = null
): void {
    $roots = $rootsOverride ?? push_upload_debris_default_roots();
    $allowedList = $allowedExtensionsOverride ?? getPushUploadAllowedExtensionsForCleanup();
    $allowedFlip = array_flip($allowedList);

    $now = time();
    $phaseDeleted = 0;
    $phaseBytes = 0;

    foreach ($roots as $root) {
        $root = rtrim($root, '/');
        if ($root === '' || !is_dir($root) || !is_readable($root)) {
            continue;
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
        } catch (Exception $e) {
            $stats['errors']++;
            aviationwx_log('warning', 'push upload debris cleanup: cannot iterate directory', [
                'root' => $root,
                'error' => $e->getMessage(),
            ], 'app');
            continue;
        }

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $path = $fileInfo->getPathname();
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if ($ext !== '' && isset($allowedFlip[$ext])) {
                continue;
            }

            $stats['files_checked']++;
            $mtime = @filemtime($path);
            if ($mtime === false) {
                continue;
            }

            $age = $now - $mtime;
            if ($age <= $maxAgeSeconds) {
                continue;
            }

            $size = @filesize($path);
            if ($size === false) {
                $size = 0;
            }

            if ($verbose) {
                echo '  ' . ($dryRun ? 'Would delete' : 'Deleting') . ' ' . $path
                    . ' (age: ' . formatPushUploadDebrisAge($age) . ", size: {$size} B)\n";
            }

            if ($dryRun) {
                $stats['files_deleted']++;
                $stats['bytes_freed'] += $size;
                $phaseDeleted++;
                $phaseBytes += $size;
                continue;
            }

            if (@unlink($path)) {
                $stats['files_deleted']++;
                $stats['bytes_freed'] += $size;
                $phaseDeleted++;
                $phaseBytes += $size;
            } else {
                $stats['errors']++;
                echo "  Failed to delete: {$path}\n";
            }
        }
    }

    if ($phaseDeleted > 0 || $verbose) {
        $action = $dryRun ? 'Would delete' : 'Deleted';
        $mb = round($phaseBytes / (1024 * 1024), 2);
        echo "  Push upload debris (FTP/SFTP non-image files): {$action} {$phaseDeleted} files ({$mb} MB)\n";
    }
}

/**
 * Human-readable age for verbose debris cleanup output.
 *
 * @param int $ageSeconds Age in seconds
 * @return string Short human-readable duration
 */
function formatPushUploadDebrisAge(int $ageSeconds): string
{
    if ($ageSeconds < 3600) {
        return round($ageSeconds / 60, 1) . ' min';
    }
    if ($ageSeconds < 86400) {
        return round($ageSeconds / 3600, 1) . ' h';
    }

    return round($ageSeconds / 86400, 1) . ' d';
}
