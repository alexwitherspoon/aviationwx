<?php
/**
 * Shared NASR APT CSV fixture cache loading for unit tests.
 */

trait LoadsNasrAptFixtureCacheTrait
{
    protected function nasrAptFixtureDirectory(): string
    {
        return dirname(__DIR__) . '/Fixtures/nasr';
    }

    protected function loadNasrAptFixtureCache(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/nasr/cache.php';
        require_once dirname(__DIR__, 2) . '/lib/weather/poh-takeoff.php';

        resetNasrAptCacheMemo();
        resetPohTakeoffTables();

        $built = nasrBuildCacheFromCsvDirectory($this->nasrAptFixtureDirectory());
        setNasrAptCacheForTesting([
            'schema_version' => NASR_APT_SCHEMA_VERSION,
            'airports' => $built['airports'],
        ]);
    }

    protected function tearDownNasrAptFixtureCache(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/nasr/cache.php';
        require_once dirname(__DIR__, 2) . '/lib/weather/poh-takeoff.php';

        resetNasrAptCacheMemo();
        resetPohTakeoffTables();
    }
}
