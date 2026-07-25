<?php

declare(strict_types=1);

namespace AviationWx\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Metrics disk health must use free-space floors so large volumes with ample free
 * bytes are not marked critical solely by used-percent.
 */
final class MetricsDiskSpaceInfoTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/constants.php';
        require_once dirname(__DIR__, 2) . '/lib/metrics.php';
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: bool, 3: bool}>
     */
    public static function diskCasesProvider(): array
    {
        $gib = 1024 * 1024 * 1024;

        return [
            'large_volume_40gib_free_high_percent_is_warning_not_critical' => [
                (int) (1.9 * 1024 * $gib), // ~1.9 TiB
                40 * $gib,
                true,  // is_low
                false, // is_critical
            ],
            'small_volume_500mib_total_250mib_free_not_critical' => [
                (int) (0.5 * $gib),
                (int) (0.25 * $gib),
                false,
                false,
            ],
            'small_volume_800mib_total_50mib_free_critical_by_ratio' => [
                (int) (0.8 * $gib),
                (int) (0.05 * $gib),
                false,
                true,
            ],
            'under_1gib_free_is_critical' => [
                100 * $gib,
                (int) (0.5 * $gib),
                false,
                true,
            ],
            'exactly_1gib_free_high_percent_is_critical' => [
                100 * $gib,
                1 * $gib - 1,
                false,
                true,
            ],
            '5gib_free_on_small_disk_ok' => [
                20 * $gib,
                5 * $gib,
                false,
                false,
            ],
            '4gib_free_is_low_not_critical' => [
                50 * $gib,
                4 * $gib,
                true,
                false,
            ],
            'plenty_free_low_percent' => [
                100 * $gib,
                50 * $gib,
                false,
                false,
            ],
        ];
    }

    #[DataProvider('diskCasesProvider')]
    public function testEvaluateDiskSpace_UsesFreeSpaceFloors(
        int $totalBytes,
        int $freeBytes,
        bool $expectLow,
        bool $expectCritical
    ): void {
        $info = metrics_evaluate_disk_space($totalBytes, $freeBytes);
        $this->assertSame($expectCritical, $info['is_critical'], 'is_critical');
        $this->assertSame($expectLow, $info['is_low'], 'is_low');
        $this->assertFalse($info['is_critical'] && $info['is_low'], 'critical and low must be mutually exclusive');
    }

    public function testGetDiskSpaceInfo_ReturnsEvaluateShape(): void
    {
        $info = metrics_get_disk_space_info();
        $this->assertArrayHasKey('total_bytes', $info);
        $this->assertArrayHasKey('free_bytes', $info);
        $this->assertArrayHasKey('used_percent', $info);
        $this->assertArrayHasKey('is_low', $info);
        $this->assertArrayHasKey('is_critical', $info);
    }
}
