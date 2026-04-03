<?php
/**
 * Unit tests for getInternalApacheBaseUrl() (scheduler internal HTTP base).
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/internal-http-url.php';

class InternalHttpUrlTest extends TestCase
{
    /** @var mixed Prior WEATHER_REFRESH_URL; false means unset */
    private mixed $savedWeatherRefreshUrl = null;

    protected function setUp(): void
    {
        parent::setUp();
        $v = getenv('WEATHER_REFRESH_URL');
        $this->savedWeatherRefreshUrl = $v === false ? false : $v;
    }

    protected function tearDown(): void
    {
        if ($this->savedWeatherRefreshUrl === false) {
            putenv('WEATHER_REFRESH_URL');
        } else {
            putenv('WEATHER_REFRESH_URL=' . $this->savedWeatherRefreshUrl);
        }
        parent::tearDown();
    }

    public function testGetInternalApacheBaseUrl_UsesWeatherRefreshUrlWhenSet(): void
    {
        putenv('WEATHER_REFRESH_URL=http://127.0.0.1:9999');
        $this->assertSame('http://127.0.0.1:9999', getInternalApacheBaseUrl());
    }

    public function testGetInternalApacheBaseUrl_TrimsTrailingSlash(): void
    {
        putenv('WEATHER_REFRESH_URL=http://localhost:8080/');
        $this->assertSame('http://localhost:8080', getInternalApacheBaseUrl());
    }
}
