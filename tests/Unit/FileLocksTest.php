<?php

/**
 * Unit tests for shared file lock helpers.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/file-locks.php';

class FileLocksTest extends TestCase
{
    public function testModuleLoadsWithoutPreRequiringCachePaths(): void
    {
        $loaded = get_included_files();
        $this->assertContains(
            realpath(__DIR__ . '/../../lib/cache-paths.php'),
            array_map('realpath', $loaded)
        );
    }

    public function testExclusiveLockIsHeldWhenLockAcquired(): void
    {
        $path = sys_get_temp_dir() . '/awx_lock_test_' . bin2hex(random_bytes(4)) . '.lock';
        $fp = acquireExclusiveFileLock($path);
        $this->assertIsResource($fp);

        try {
            $this->assertTrue(exclusiveFileLockIsHeld($path));
        } finally {
            releaseExclusiveFileLock($fp, $path);
            @unlink($path);
        }
    }
}
