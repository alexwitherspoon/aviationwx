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

    /** @var mixed Prior APP_PORT; false means unset */
    private mixed $savedAppPort = null;

    /** @var mixed Prior PORT; false means unset */
    private mixed $savedPort = null;

    protected function setUp(): void
    {
        parent::setUp();
        $v = getenv('WEATHER_REFRESH_URL');
        $this->savedWeatherRefreshUrl = $v === false ? false : $v;
        $a = getenv('APP_PORT');
        $this->savedAppPort = $a === false ? false : $a;
        $p = getenv('PORT');
        $this->savedPort = $p === false ? false : $p;
    }

    protected function tearDown(): void
    {
        if ($this->savedWeatherRefreshUrl === false) {
            putenv('WEATHER_REFRESH_URL');
        } else {
            putenv('WEATHER_REFRESH_URL=' . $this->savedWeatherRefreshUrl);
        }
        if ($this->savedAppPort === false) {
            putenv('APP_PORT');
        } else {
            putenv('APP_PORT=' . $this->savedAppPort);
        }
        if ($this->savedPort === false) {
            putenv('PORT');
        } else {
            putenv('PORT=' . $this->savedPort);
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

    public function testGetInternalApacheBaseUrl_UsesAppPortWhenWeatherRefreshUnset(): void
    {
        putenv('WEATHER_REFRESH_URL');
        putenv('APP_PORT=7777');
        putenv('PORT');
        $this->assertSame('http://localhost:7777', getInternalApacheBaseUrl());
    }

    public function testGetInternalApacheBaseUrl_FallsBackTo8080(): void
    {
        putenv('WEATHER_REFRESH_URL');
        putenv('APP_PORT');
        putenv('PORT');
        $this->assertSame('http://localhost:8080', getInternalApacheBaseUrl());
    }
}
