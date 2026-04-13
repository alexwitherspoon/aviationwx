<?php
/**
 * Station power cache path helper tests.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/cache-paths.php';

final class StationPowerCachePathTest extends TestCase
{
    public function testGetStationPowerCachePathLowercasesAirportId(): void
    {
        $p = getStationPowerCachePath('KSPB');
        $this->assertStringEndsWith('/station-power/kspb.json', $p);
        $this->assertStringContainsString('station-power', $p);
    }
}
