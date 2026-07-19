<?php

/**
 * Reset OurAirports cache files before each test method.
 */
trait IsolatesOurAirportsCacheTrait
{
    protected function resetOurAirportsTestCacheState(): void
    {
        ensureCacheDir(CACHE_OURAIRPORTS_DIR);
        ensureCacheDir(CACHE_RUNWAYS_DIR);

        file_put_contents(
            CACHE_OURAIRPORTS_META_FILE,
            json_encode(['files' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );

        foreach ([
            CACHE_OURAIRPORTS_AIRPORTS_CSV,
            CACHE_OURAIRPORTS_RUNWAYS_CSV,
            CACHE_OURAIRPORTS_FREQUENCIES_CSV,
            CACHE_OURAIRPORTS_FILE,
            CACHE_OURAIRPORTS_FREQUENCIES_FILE,
            CACHE_FAA_NGDA_RUNWAYS_CSV,
            CACHE_RUNWAYS_DATA_FILE,
            CACHE_OURAIRPORTS_BULK_LOCK,
            CACHE_OURAIRPORTS_PROBE_LOCK,
            CACHE_OURAIRPORTS_META_LOCK,
        ] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
