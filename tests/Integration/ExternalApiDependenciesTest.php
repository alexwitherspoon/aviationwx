<?php
/**
 * Non-network dependency checks (bundled map assets, etc.).
 *
 * Live upstream HTTPS probes live in UpstreamApiProbeTest.php and run via `make test-external-apis`.
 *
 * @see docs/TESTING.md
 */

use PHPUnit\Framework\TestCase;

class ExternalApiDependenciesTest extends TestCase
{
    /**
     * Test Leaflet.js local file exists
     */
    public function testLeafletJsLocal_Exists()
    {
        $path = __DIR__ . '/../../public/js/leaflet.js';

        $this->assertFileExists(
            $path,
            'Leaflet.js should exist locally at public/js/leaflet.js'
        );

        $this->assertGreaterThan(
            1000,
            filesize($path),
            'Leaflet.js should have substantial content'
        );
    }

    /**
     * Test Leaflet MarkerCluster plugin local file exists
     */
    public function testLeafletMarkerClusterLocal_Exists()
    {
        $jsPath = __DIR__ . '/../../public/js/leaflet.markercluster.js';
        $cssPath1 = __DIR__ . '/../../public/css/MarkerCluster.css';
        $cssPath2 = __DIR__ . '/../../public/css/MarkerCluster.Default.css';

        $this->assertFileExists(
            $jsPath,
            'MarkerCluster JS should exist locally at public/js/leaflet.markercluster.js'
        );

        $this->assertFileExists(
            $cssPath1,
            'MarkerCluster CSS should exist locally at public/css/MarkerCluster.css'
        );

        $this->assertFileExists(
            $cssPath2,
            'MarkerCluster Default CSS should exist locally at public/css/MarkerCluster.Default.css'
        );

        $this->assertGreaterThan(
            1000,
            filesize($jsPath),
            'MarkerCluster JS should have substantial content'
        );
    }
}
