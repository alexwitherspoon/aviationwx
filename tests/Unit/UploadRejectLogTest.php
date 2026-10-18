<?php
/**
 * Unit tests for the per-unit rejected-upload log.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/upload/reject-log.php';
require_once __DIR__ . '/../../lib/cache-paths.php';

class UploadRejectLogTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/aviationwx_upload_rejects_' . uniqid('', true);
        mkdir($this->tmpRoot, 0755, true);
        $GLOBALS['uploadRejectTestRoot'] = $this->tmpRoot;
        $GLOBALS['uploadRejectUnitsMax'] = 500;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['uploadRejectTestRoot']);
        unset($GLOBALS['uploadRejectUnitsMax']);
        unset($GLOBALS['uploadRejectPayloadMaxBytes']);
        unset($GLOBALS['uploadRejectWritesMinuteMax']);
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function readUnits(): array
    {
        $data = bridgeReadJsonFile(getUploadRejectUnitsPath());
        return $data['units'] ?? [];
    }

    public function testRecordReject_CreatesEntryKeyedByMac(): void
    {
        $this->assertTrue(uploadRecordReject(
            'MAC_MISMATCH',
            'station-other',
            'aa:bb:cc:dd:ee:ff',
            null,
            'kspb',
            'station-davis-01',
            '1.2.3.4',
            ['type' => 'rtd', 'tempout' => 678],
            1531754005
        ));

        $units = $this->readUnits();
        $this->assertCount(1, $units);
        $entry = $units['AA:BB:CC:DD:EE:FF'];
        $this->assertSame('MAC_MISMATCH', $entry['last_reason']);
        $this->assertSame(1, $entry['attempts']);
        $this->assertSame('station-other', $entry['last_wid']);
        $this->assertSame('kspb', $entry['last_airport']);
        $this->assertSame('station-davis-01', $entry['expected_wid']);
        $this->assertSame('1.2.3.4', $entry['last_ip']);
        $this->assertSame(678, $entry['last_payload']['tempout']);
    }

    public function testRecordReject_SameUnitUpdatesSingleSlot(): void
    {
        uploadRecordReject('MAC_MISMATCH', 'station-other', 'aa:bb:cc:dd:ee:ff', null, null, null, null, null, 1531754005);
        uploadRecordReject('WID_MISMATCH', 'station-other', 'aa:bb:cc:dd:ee:ff', null, null, null, null, null, 1531754010);

        $units = $this->readUnits();
        $this->assertCount(1, $units);
        $entry = $units['AA:BB:CC:DD:EE:FF'];
        $this->assertSame(2, $entry['attempts']);
        $this->assertSame('WID_MISMATCH', $entry['last_reason']);
        $this->assertSame(gmdate('c', 1531754005), $entry['first_seen_at']);
        $this->assertSame(gmdate('c', 1531754010), $entry['last_seen_at']);
    }

    public function testRecordReject_DistinctUnitsSeparateEntries(): void
    {
        uploadRecordReject('MAC_MISMATCH', null, 'aa:bb:cc:dd:ee:ff', null, null, null, null, null, 1531754005);
        uploadRecordReject('MAC_MISMATCH', null, '11:22:33:44:55:66', null, null, null, null, null, 1531754006);

        $this->assertCount(2, $this->readUnits());
    }

    public function testRecordReject_NoisyUnitDoesNotCrowdOthers(): void
    {
        for ($i = 0; $i < 50; $i++) {
            uploadRecordReject('MAC_MISMATCH', null, 'aa:bb:cc:dd:ee:ff', null, null, null, null, null, 1531754000 + $i);
        }
        uploadRecordReject('MAC_MISMATCH', null, '11:22:33:44:55:66', null, null, null, null, null, 1531754100);

        $units = $this->readUnits();
        $this->assertCount(2, $units);
        $this->assertSame(50, $units['AA:BB:CC:DD:EE:FF']['attempts']);
        $this->assertSame(1, $units['11:22:33:44:55:66']['attempts']);
    }

    public function testRecordReject_FallsBackToWidWhenNoMac(): void
    {
        uploadRecordReject('MAC_MISMATCH', 'station-x', null, null, null, null, null, null, 1531754005);
        $this->assertArrayHasKey('wid:station-x', $this->readUnits());
    }

    public function testRecordReject_FallsBackToTokenWhenNoMacOrWid(): void
    {
        $token = 'awxu_000000000000000000000000000000000000000000000001';
        uploadRecordReject('INVALID_UPLOAD_TOKEN', null, null, $token, null, null, null, null, 1531754005);
        $this->assertArrayHasKey('token:' . uploadTokenHash($token), $this->readUnits());
    }

    public function testRecordReject_StoresFullToken(): void
    {
        $token = 'awxu_000000000000000000000000000000000000000000000001';
        uploadRecordReject('INVALID_UPLOAD_TOKEN', null, null, $token, null, null, null, null, 1531754005);

        $entry = $this->readUnits()['token:' . uploadTokenHash($token)];
        $this->assertSame($token, $entry['last_token']);
        $this->assertArrayNotHasKey('last_token_hash', $entry);
    }

    public function testRecordReject_EvictsLeastRecentlySeen(): void
    {
        $GLOBALS['uploadRejectUnitsMax'] = 2;
        uploadRecordReject('MAC_MISMATCH', null, 'aa:aa:aa:aa:aa:aa', null, null, null, null, null, 1000);
        uploadRecordReject('MAC_MISMATCH', null, 'bb:bb:bb:bb:bb:bb', null, null, null, null, null, 2000);
        uploadRecordReject('MAC_MISMATCH', null, 'cc:cc:cc:cc:cc:cc', null, null, null, null, null, 3000);

        $units = $this->readUnits();
        $this->assertCount(2, $units);
        $this->assertArrayNotHasKey('AA:AA:AA:AA:AA:AA', $units);
        $this->assertArrayHasKey('BB:BB:BB:BB:BB:BB', $units);
        $this->assertArrayHasKey('CC:CC:CC:CC:CC:CC', $units);
    }

    public function testRecordReject_TruncatesHugePayload(): void
    {
        $GLOBALS['uploadRejectPayloadMaxBytes'] = 50;
        uploadRecordReject(
            'MAC_MISMATCH',
            null,
            'aa:bb:cc:dd:ee:ff',
            null,
            null,
            null,
            null,
            ['type' => 'rtd', 'blob' => str_repeat('x', 2000)],
            1531754005
        );

        $entry = $this->readUnits()['AA:BB:CC:DD:EE:FF'];
        $this->assertTrue($entry['last_payload']['_truncated']);
        $this->assertArrayHasKey('_preview', $entry['last_payload']);
    }

    public function testRejectWriteGuard_ThrottlesPerIp(): void
    {
        $GLOBALS['uploadRejectWritesMinuteMax'] = 2;
        $ip = 'guard-' . uniqid('', true);
        $this->assertTrue(uploadRejectWriteAllowed($ip));
        $this->assertTrue(uploadRejectWriteAllowed($ip));
        $this->assertFalse(uploadRejectWriteAllowed($ip));
    }

    public function testRecordReject_SkipsWriteWhenIpThrottled(): void
    {
        $GLOBALS['uploadRejectWritesMinuteMax'] = 1;
        $ip = 'guard-' . uniqid('', true);
        $this->assertTrue(uploadRecordReject('MAC_MISMATCH', null, 'aa:aa:aa:aa:aa:01', null, null, null, $ip, null, 1000));
        $this->assertFalse(uploadRecordReject('MAC_MISMATCH', null, 'aa:aa:aa:aa:aa:02', null, null, null, $ip, null, 2000));

        $units = $this->readUnits();
        $this->assertArrayHasKey('AA:AA:AA:AA:AA:01', $units);
        $this->assertArrayNotHasKey('AA:AA:AA:AA:AA:02', $units);
    }
}