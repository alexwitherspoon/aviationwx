<?php
/**
 * Tests shouldFetchStationPowerForAirport(): scheduler and worker use the same rules.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';

final class StationPowerFetchEligibilityTest extends TestCase
{
    private function vrmStationPowerBlock(): array
    {
        return [
            'provider' => 'vrm',
            'config' => [
                'installation_id' => 1,
                'access_token' => 'test_token_for_eligibility',
            ],
        ];
    }

    public function testMaintenanceDoesNotSuppressFetch(): void
    {
        $airport = [
            'enabled' => true,
            'maintenance' => true,
            'limited_availability' => true,
            'station_power' => $this->vrmStationPowerBlock(),
        ];
        $this->assertTrue(shouldFetchStationPowerForAirport($airport));
    }

    public function testDisabledAirportDoesNotFetch(): void
    {
        $airport = [
            'enabled' => false,
            'maintenance' => false,
            'limited_availability' => true,
            'station_power' => $this->vrmStationPowerBlock(),
        ];
        $this->assertFalse(shouldFetchStationPowerForAirport($airport));
    }

    public function testWithoutLimitedAvailabilityDoesNotFetch(): void
    {
        $airport = [
            'enabled' => true,
            'maintenance' => false,
            'limited_availability' => false,
            'station_power' => $this->vrmStationPowerBlock(),
        ];
        $this->assertFalse(shouldFetchStationPowerForAirport($airport));
    }

    public function testWithoutStationPowerDoesNotFetch(): void
    {
        $airport = [
            'enabled' => true,
            'maintenance' => false,
            'limited_availability' => true,
        ];
        $this->assertFalse(shouldFetchStationPowerForAirport($airport));
    }
}
