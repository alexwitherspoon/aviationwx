<?php

/**
 * Unit tests for shared file lock helpers.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/file-locks.php';

class FileLocksTest extends TestCase
{
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
