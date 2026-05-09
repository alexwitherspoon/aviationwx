<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Locks the contract between api/weather.php (503 + JSON error) and
 * scripts/fetch-weather.php (treat as success for process pool).
 */
final class WeatherInternalApiNoSourcesContractTest extends TestCase
{
    /**
     * Error text must be defined once in lib/constants.php.
     */
    public function testConstantMatchesHistoricalMessage(): void
    {
        require_once __DIR__ . '/../../lib/constants.php';
        $this->assertSame(
            'Weather source not configured',
            WEATHER_INTERNAL_API_ERROR_SOURCE_NOT_CONFIGURED
        );
    }

    /**
     * Both call sites must use the constant so message changes cannot drift.
     */
    public function testApiAndFetcherUseConstantNotDuplicateLiteral(): void
    {
        $apiPath = __DIR__ . '/../../api/weather.php';
        $fetchPath = __DIR__ . '/../../scripts/fetch-weather.php';
        $this->assertFileExists($apiPath);
        $this->assertFileExists($fetchPath);

        $apiSrc = (string) file_get_contents($apiPath);
        $fetchSrc = (string) file_get_contents($fetchPath);

        $this->assertStringContainsString(
            'WEATHER_INTERNAL_API_ERROR_SOURCE_NOT_CONFIGURED',
            $apiSrc,
            'api/weather.php must emit JSON error via WEATHER_INTERNAL_API_ERROR_SOURCE_NOT_CONFIGURED'
        );
        $this->assertStringContainsString(
            'WEATHER_INTERNAL_API_ERROR_SOURCE_NOT_CONFIGURED',
            $fetchSrc,
            'fetch-weather.php must compare 503 body using WEATHER_INTERNAL_API_ERROR_SOURCE_NOT_CONFIGURED'
        );

        $literalInQuotes = "'Weather source not configured'";
        $this->assertStringNotContainsString(
            $literalInQuotes,
            $apiSrc,
            'Duplicate literal in api/weather.php risks drift from lib/constants.php'
        );
        $this->assertStringNotContainsString(
            $literalInQuotes,
            $fetchSrc,
            'Duplicate literal in fetch-weather.php risks drift from lib/constants.php'
        );
    }
}
