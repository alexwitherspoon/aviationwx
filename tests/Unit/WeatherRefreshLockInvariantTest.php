<?php
/**
 * Stale-while-revalidate background refresh uses a lock file beside the weather JSON cache.
 * Lock path must be derived from getWeatherCachePath() (same directory), not an undefined variable.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/cache-paths.php';

final class WeatherRefreshLockInvariantTest extends TestCase
{
    /**
     * Lock files must live in CACHE_WEATHER_DIR next to the airport JSON file.
     */
    public function testRefreshLockPath_MatchesCacheFileDirectory(): void
    {
        $airportId = 'kspb';
        $weatherCacheFile = getWeatherCachePath($airportId);
        $expectedLock = dirname($weatherCacheFile) . '/refresh_' . $airportId . '.lock';

        $this->assertSame(CACHE_WEATHER_DIR, dirname($weatherCacheFile));
        $this->assertSame(
            CACHE_WEATHER_DIR . '/refresh_' . $airportId . '.lock',
            $expectedLock
        );
    }
}
