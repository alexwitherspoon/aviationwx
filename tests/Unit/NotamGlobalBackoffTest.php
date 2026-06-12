<?php

declare(strict_types=1);

namespace AviationWX\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Shared NMS credential backoff after HTTP 429/503.
 *
 * @covers ::notamGlobalBackoffKey
 * @covers ::checkNotamGlobalBackoff
 * @covers ::recordNotamGlobalRateLimitFailure
 * @covers ::clearNotamGlobalBackoff
 */
final class NotamGlobalBackoffTest extends TestCase
{
    private string $backoffFile;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../../lib/cache-paths.php';
        require_once __DIR__ . '/../../lib/constants.php';
        require_once __DIR__ . '/../../lib/notam/rate-limit.php';
        require_once __DIR__ . '/../../lib/notam/circuit-breaker.php';

        $GLOBALS['notamRateLimitTestClientId'] = 'client-backoff';
        $GLOBALS['notamRateLimitTestClientSecret'] = 'secret-backoff';
        $GLOBALS['notamRateLimitTestBaseUrl'] = 'https://example.test/nms';

        $this->backoffFile = CACHE_BACKOFF_FILE;
        ensureCacheDir(dirname($this->backoffFile));
        $this->clearBackoffKey();
    }

    protected function tearDown(): void
    {
        $this->clearBackoffKey();
        unset(
            $GLOBALS['notamRateLimitTestClientId'],
            $GLOBALS['notamRateLimitTestClientSecret'],
            $GLOBALS['notamRateLimitTestBaseUrl']
        );
        parent::tearDown();
    }

    public function testGlobalBackoffKey_StableForSameCredential(): void
    {
        $this->assertSame(notamGlobalBackoffKey(), notamGlobalBackoffKey());
        $this->assertStringStartsWith('global_notam_', notamGlobalBackoffKey());
    }

    public function testCheck_ReturnsOpenWhenBackoffActive(): void
    {
        $now = 1_700_000_000;
        recordNotamGlobalRateLimitFailure(429, null, $now);

        $result = checkNotamGlobalBackoff($now + 10);
        $this->assertTrue($result['skip']);
        $this->assertSame('global_nms_backoff', $result['reason']);
        $this->assertGreaterThan(0, $result['backoff_remaining']);
    }

    public function testCheck_ReturnsClosedAfterBackoffExpires(): void
    {
        $now = 1_700_000_000;
        recordNotamGlobalRateLimitFailure(429, ['retry-after' => '5'], $now);

        $result = checkNotamGlobalBackoff($now + 6);
        $this->assertFalse($result['skip']);
    }

    public function testRecord_UsesRetryAfterHeaderWhenPresent(): void
    {
        $now = 1_700_000_000;
        recordNotamGlobalRateLimitFailure(429, ['retry-after' => '120'], $now);

        $result = checkNotamGlobalBackoff($now + 30);
        $this->assertTrue($result['skip']);
        $this->assertGreaterThanOrEqual(80, $result['backoff_remaining']);
    }

    public function testRecord_DefaultBackoffWhenHeaderMissing(): void
    {
        $now = 1_700_000_000;
        recordNotamGlobalRateLimitFailure(429, null, $now);

        $result = checkNotamGlobalBackoff($now + 30);
        $this->assertTrue($result['skip']);
        $this->assertGreaterThanOrEqual(
            NOTAM_GLOBAL_BACKOFF_DEFAULT_SECONDS - 30,
            $result['backoff_remaining']
        );
    }

    public function testRecord_ExtendsExistingBackoffToLaterDeadline(): void
    {
        $now = 1_700_000_000;
        recordNotamGlobalRateLimitFailure(429, ['retry-after' => '30'], $now);
        recordNotamGlobalRateLimitFailure(429, ['retry-after' => '120'], $now + 5);

        $result = checkNotamGlobalBackoff($now + 10);
        $this->assertTrue($result['skip']);
        $this->assertGreaterThanOrEqual(100, $result['backoff_remaining']);
    }

    public function testRecord_IgnoresNonRateLimitStatusCodes(): void
    {
        recordNotamGlobalRateLimitFailure(500, null, 1_700_000_000);

        $this->assertFalse(checkNotamGlobalBackoff(1_700_000_000)['skip']);
    }

    public function testClear_RemovesActiveBackoff(): void
    {
        $now = 1_700_000_000;
        recordNotamGlobalRateLimitFailure(429, null, $now);
        clearNotamGlobalBackoff();

        $this->assertFalse(checkNotamGlobalBackoff($now)['skip']);
    }

    public function testCheck_TreatsScalarBackoffFileAsInactive(): void
    {
        file_put_contents($this->backoffFile, '"corrupted"', LOCK_EX);
        clearstatcache();

        $this->assertFalse(checkNotamGlobalBackoff(1_700_000_000)['skip']);
    }

    public function testCheck_TreatsMalformedEntryAsInactive(): void
    {
        file_put_contents(
            $this->backoffFile,
            json_encode([notamGlobalBackoffKey() => 'not-an-array'], JSON_PRETTY_PRINT),
            LOCK_EX
        );
        clearstatcache();

        $this->assertFalse(checkNotamGlobalBackoff(1_700_000_000)['skip']);
    }

    public function testSetUntil_RecoversFromScalarBackoffFile(): void
    {
        file_put_contents($this->backoffFile, '"corrupted"', LOCK_EX);
        clearstatcache();

        $now = 1_700_000_000;
        recordNotamGlobalRateLimitFailure(429, null, $now);

        $result = checkNotamGlobalBackoff($now + 10);
        $this->assertTrue($result['skip']);

        $decoded = json_decode((string) file_get_contents($this->backoffFile), true);
        $this->assertIsArray($decoded);
        $this->assertIsArray($decoded[notamGlobalBackoffKey()] ?? null);
    }

    public function testSetUntil_RecoversFromMalformedExistingEntry(): void
    {
        file_put_contents(
            $this->backoffFile,
            json_encode([notamGlobalBackoffKey() => 'not-an-array'], JSON_PRETTY_PRINT),
            LOCK_EX
        );
        clearstatcache();

        $now = 1_700_000_000;
        recordNotamGlobalRateLimitFailure(429, ['retry-after' => '30'], $now);

        $result = checkNotamGlobalBackoff($now + 10);
        $this->assertTrue($result['skip']);
        $this->assertGreaterThanOrEqual(15, $result['backoff_remaining']);
    }

    private function clearBackoffKey(): void
    {
        clearNotamGlobalBackoff();
        if (!file_exists($this->backoffFile)) {
            return;
        }

        $data = json_decode((string) file_get_contents($this->backoffFile), true);
        if (!is_array($data)) {
            return;
        }

        unset($data[notamGlobalBackoffKey()]);
        if ($data === []) {
            @unlink($this->backoffFile);
        } else {
            file_put_contents($this->backoffFile, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
        }
    }
}
