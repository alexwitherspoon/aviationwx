<?php
/**
 * Regression: the status page Public API probe set must keep its bulk
 * weathercam entry so a bulk weathercam outage stays visible.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/status-checks.php';

final class PublicApiHealthEndpointsTest extends TestCase
{
    public function testGetPublicApiHealthEndpoints_IncludesBulkWeatherAndBulkWeathercams(): void
    {
        $endpoints = getPublicApiHealthEndpoints();

        $this->assertSame(
            'Bulk Weather',
            $endpoints['/api/v1/weather/bulk?airports=kspb'] ?? null,
            'Bulk Weather probe must remain in the endpoint set'
        );
        $this->assertSame(
            'Bulk Weathercams',
            $endpoints['/api/v1/weathercam/bulk?operator=aviationwx'] ?? null,
            'Bulk Weathercams probe must remain in the endpoint set'
        );
    }
}
