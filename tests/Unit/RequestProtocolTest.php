<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';

class RequestProtocolTest extends TestCase
{
    protected function setUp(): void
    {
        unset($_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['HTTPS']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['HTTPS']);
    }

    public function testGetRequestProtocol_ForwardedHttpsWins(): void
    {
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $_SERVER['HTTPS'] = 'off';
        $this->assertSame('https', getRequestProtocol());
    }

    public function testGetRequestProtocol_ForwardedHttpOverridesHttpsServerVar(): void
    {
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http';
        $_SERVER['HTTPS'] = 'on';
        $this->assertSame('http', getRequestProtocol());
    }

    public function testGetRequestProtocol_HttpsServerVar(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $this->assertSame('https', getRequestProtocol());
    }

    public function testGetRequestProtocol_FallsBackToHttp(): void
    {
        $this->assertSame('http', getRequestProtocol());
    }

    public function testGetRequestProtocol_ForwardedCaseInsensitive(): void
    {
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'HTTPS';
        $this->assertSame('https', getRequestProtocol());
    }
}