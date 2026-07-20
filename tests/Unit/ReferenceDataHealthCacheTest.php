<?php

/**
 * Unit tests for reference catalog cache basis and diagnostics.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/reference-data-health.php';

class ReferenceDataHealthCacheTest extends TestCase
{
    public function testCacheBasisIncludesNasrMetaPath(): void
    {
        $paths = reference_data_health_cache_paths();

        $this->assertContains(CACHE_NASR_APT_META_FILE, $paths);
        $this->assertContains(CACHE_OURAIRPORTS_AIRPORTS_CSV, $paths);
    }

    public function testCacheBasisChangesWhenNasrMetaTouched(): void
    {
        ensureCacheDir(CACHE_NASR_DIR);
        $metaPath = CACHE_NASR_APT_META_FILE;
        if (!is_file($metaPath)) {
            file_put_contents($metaPath, '{}');
        }

        $before = reference_data_health_cache_basis();
        touch($metaPath, time() + 5);
        $after = reference_data_health_cache_basis();

        $this->assertNotSame($before, $after);
    }

    public function testSourceDiagnosticsTextIncludesFetchError(): void
    {
        $text = reference_data_source_diagnostics_text([
            'details' => [
                'needs_fetch' => true,
                'last_fetch_error' => 'download_failed',
                'effective_date' => '2026-01-01',
            ],
        ]);

        $this->assertStringContainsString('needs fetch', $text);
        $this->assertStringContainsString('download_failed', $text);
        $this->assertStringContainsString('2026-01-01', $text);
    }
}
