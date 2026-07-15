<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/runways.php';
require_once __DIR__ . '/../../scripts/fetch-runways.php';

class FetchRunwaysTest extends TestCase
{
    public function testResolveOurAirportsRunwaysForCacheIdent_UsesFaaToIcaoMapping(): void
    {
        $ourairports = [
            'KHIO' => [
                [
                    'length_ft' => 6600,
                    'surface' => 'ASPH',
                    'le_ident' => '03',
                    'he_ident' => '21',
                ],
            ],
        ];

        $resolved = resolveOurAirportsRunwaysForCacheIdent('HIO', $ourairports, ['HIO' => 'KHIO']);

        $this->assertNotNull($resolved);
        $this->assertSame(6600, $resolved[0]['length_ft']);
    }

    public function testMergeRunwaySources_AttachesPerformanceRunwaysForFaaCoveredAirport(): void
    {
        $faa = [
            'HIO' => [
                [
                    'lat1' => 45.54,
                    'lon1' => -122.95,
                    'lat2' => 45.53,
                    'lon2' => -122.94,
                    'le_ident' => '03',
                    'he_ident' => '21',
                    'source' => 'faa',
                ],
            ],
        ];
        $ourairports = [
            'KHIO' => [
                [
                    'lat1' => 45.54,
                    'lon1' => -122.95,
                    'lat2' => 45.53,
                    'lon2' => -122.94,
                    'le_ident' => '03',
                    'he_ident' => '21',
                    'length_ft' => 6600,
                    'surface' => 'ASPH',
                    'le_displaced_threshold_ft' => 0,
                    'he_displaced_threshold_ft' => 0,
                    'source' => 'ourairports',
                ],
            ],
        ];
        $centers = ['HIO' => ['lat' => 45.535, 'lon' => -122.945]];

        $merged = mergeRunwaySources($faa, $ourairports, $centers, ['HIO' => 'KHIO']);

        $this->assertArrayHasKey('performance_runways', $merged['HIO']);
        $this->assertArrayHasKey('performance_runways', $merged['KHIO']);
        $this->assertSame(6600, $merged['KHIO']['performance_runways'][0]['length_ft']);
    }
}
