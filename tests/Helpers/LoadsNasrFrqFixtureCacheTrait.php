<?php
/**
 * Shared NASR FRQ CSV fixture cache loading for unit tests.
 */

trait LoadsNasrFrqFixtureCacheTrait
{
    protected function nasrFrqFixtureDirectory(): string
    {
        return dirname(__DIR__) . '/Fixtures/nasr';
    }

    protected function loadNasrFrqFixtureCache(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/nasr/frequencies-cache.php';

        resetNasrFrqCacheMemo();

        $built = nasrBuildFrqCacheFromCsvDirectory($this->nasrFrqFixtureDirectory());
        setNasrFrqCacheForTesting([
            'schema_version' => NASR_FRQ_SCHEMA_VERSION,
            'airports' => $built['airports'],
        ]);
    }

    protected function tearDownNasrFrqFixtureCache(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/nasr/frequencies-cache.php';

        resetNasrFrqCacheMemo();
    }
}
