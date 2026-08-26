<?php
/**
 * Configuration cache invalidation tests.
 */

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

class ConfigCacheTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testClearConfigCache_WithoutEndpointLoaded_ClearsWeathercamCatalog(): void
    {
        $this->assertFalse(function_exists('resetWeathercamBulkCatalogCache'));

        $GLOBALS['_aviationwx_weathercam_bulk_sha'] = 'test-sha';
        $GLOBALS['_aviationwx_weathercam_bulk_airports'] = [['id' => 'stale']];
        if (function_exists('apcu_store')) {
            apcu_store('aviationwx_weathercam_bulk', ['sha' => 'test-sha', 'airports' => []]);
        }

        clearConfigCache();

        $this->assertNull($GLOBALS['_aviationwx_weathercam_bulk_sha'] ?? null);
        $this->assertNull($GLOBALS['_aviationwx_weathercam_bulk_airports'] ?? null);
        if (function_exists('apcu_fetch')) {
            $this->assertFalse(apcu_fetch('aviationwx_weathercam_bulk'));
        }
    }
}
