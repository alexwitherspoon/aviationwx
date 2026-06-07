<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class NotamFetcherLocationTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/notam/fetcher.php';
    }

    public function testResolveLocationQueryCode_PrefersIcaoOverFaa(): void
    {
        $code = notamResolveLocationQueryCode([
            'icao' => 'KPDX',
            'faa' => 'PDX',
        ]);

        self::assertSame('KPDX', $code);
    }

    public function testResolveLocationQueryCode_UsesFaaWhenIcaoAbsent(): void
    {
        $code = notamResolveLocationQueryCode([
            'icao' => null,
            'iata' => null,
            'faa' => '03S',
        ]);

        self::assertSame('03S', $code);
    }

    public function testResolveLocationQueryCode_ReturnsNullWhenNoIdentifier(): void
    {
        self::assertNull(notamResolveLocationQueryCode([
            'icao' => null,
            'iata' => null,
            'faa' => null,
        ]));
    }

    public function testResolveLocationQueryCode_UsesIataWhenIcaoAbsent(): void
    {
        $code = notamResolveLocationQueryCode([
            'icao' => null,
            'iata' => 'PDX',
            'faa' => '03S',
        ]);

        self::assertSame('PDX', $code);
    }

    public function testResolveLocationQueryCode_TrimsWhitespace(): void
    {
        $code = notamResolveLocationQueryCode([
            'icao' => '  KPDX  ',
        ]);

        self::assertSame('KPDX', $code);
    }

    public function testResolveLocationQueryCode_IgnoresEmptyString(): void
    {
        self::assertSame('03S', notamResolveLocationQueryCode([
            'icao' => '',
            'iata' => '',
            'faa' => '03S',
        ]));
    }
}
