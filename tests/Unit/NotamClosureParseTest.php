<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/notam/closure-parse.php';

/**
 * Subject-aware NOTAM closure phrase parsing (runway vs taxiway vs aerodrome).
 */
final class NotamClosureParseTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function directRunwayClosureProvider(): iterable
    {
        yield 'pair closed' => ['RWY 15/33 CLSD', true];
        yield 'pair with airport prefix' => ['BOI RWY 10L/28R CLSD EXC XNG', true];
        yield 'single end' => ['BOI RWY 10R CLSD', true];
        yield 'segment between taxiways' => ['RWY 10R/28L CLSD BTN TWY A AND TWY B', true];
        yield 'intersection closed' => ['RWY 10R INTERSECTION TWY G CLSD', true];
        yield 'partial wingspan on runway' => [
            'DFW RWY 13L/31R CLSD TO ACFT WINGSPAN MORE THAN 214FT',
            true,
        ];
        yield 'closed spelling' => ['RWY 09/27 CLOSED', true];
    }

    #[DataProvider('directRunwayClosureProvider')]
    public function testNotamTextIndicatesDirectRunwayClosure_AcceptsRunwaySubject(string $text, bool $expected): void
    {
        $this->assertSame($expected, notamTextIndicatesDirectRunwayClosure($text));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function taxiwayOnlyClosureProvider(): iterable
    {
        yield 'kboi construction' => ['TWY G BTN RWY 10R/28L AND TWY A CLSD CONSTRUCTION'];
        yield 'between runway and twy' => ['TWY C BTN RWY 09/27 AND TWY B CLSD'];
        yield 'approach end landmark' => ['TWY D BTN APCH END RWY 28R AND TWY E CLSD'];
        yield 'simple twy' => ['TWY A CLSD'];
        yield 'twy first clsd before rwy landmark' => ['TWY G CLSD BTN RWY 10R/28L AND TWY A'];
        yield 'construction twy at end' => ['CONSTRUCTION ON RWY 10R/28L TWY G CLSD'];
        yield 'gate taxiway partial' => [
            'TWY K BTN GATE D5 AND GATE E3 CLSD TO ACFT WINGSPAN MORE THAN 118FT',
        ];
    }

    #[DataProvider('taxiwayOnlyClosureProvider')]
    public function testNotamTextIndicatesDirectRunwayClosure_RejectsTaxiwaySubject(string $text): void
    {
        $this->assertFalse(notamTextIndicatesDirectRunwayClosure($text));
    }

    public function testNotamTextIndicatesDirectRunwayClosure_RejectsObstacleNearRunwayPhrase(): void
    {
        $this->assertFalse(
            notamTextIndicatesDirectRunwayClosure('OBST CRANE 500FT AGL 200FT NW RWY 10R CLSD')
        );
    }

    public function testNotamTextIndicatesDirectRunwayClosure_RejectsHazardWithoutClosure(): void
    {
        $this->assertFalse(notamTextIndicatesDirectRunwayClosure('RWY 12/30 UNSAFE'));
        $this->assertFalse(notamTextIndicatesDirectRunwayClosure('RWY 12/30 HAZARD BIRDS'));
        $this->assertFalse(notamTextIndicatesDirectRunwayClosure('RWY 10R/28L WIP'));
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function runwayOrAerodromeClosureProvider(): iterable
    {
        yield 'runway pair' => ['RWY 15/33 CLSD', true];
        yield 'aerodrome ad ap' => ['AD AP CLSD', true];
        yield 'airport closed' => ['BOISE AIRPORT CLSD', true];
        yield 'kboi taxiway' => ['TWY G BTN RWY 10R/28L AND TWY A CLSD CONSTRUCTION', false];
        yield 'apron' => ['APRON N CLSD', false];
    }

    #[DataProvider('runwayOrAerodromeClosureProvider')]
    public function testNotamTextIndicatesRunwayOrAerodromeClosure(string $text, bool $expected): void
    {
        $this->assertSame($expected, notamTextIndicatesRunwayOrAerodromeClosure($text));
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function taxiwayOnlyProvider(): iterable
    {
        yield 'kboi' => ['TWY G BTN RWY 10R/28L AND TWY A CLSD CONSTRUCTION', true];
        yield 'simple' => ['TWY A CLSD', true];
        yield 'runway closed' => ['RWY 10L/28R CLSD', false];
    }

    #[DataProvider('taxiwayOnlyProvider')]
    public function testNotamTextIndicatesTaxiwayOnlyClosure(string $text, bool $expected): void
    {
        $this->assertSame($expected, notamTextIndicatesTaxiwayOnlyClosure($text));
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function runwayAffectingPartialProvider(): iterable
    {
        yield 'kboi approach twy wingspan' => [
            'TWY J BTN APCH END RWY 10R AND TWY W CLSD TO ACFT WINGSPAN MORE THAN 118FT',
            true,
        ];
        yield 'direct runway wingspan' => [
            'HIO RWY 13R/31L CLSD TO ACFT WINGSPAN MORE THAN 118FT',
            true,
        ];
        yield 'gate taxiway only' => [
            'TWY K BTN GATE D5 AND GATE E3 CLSD TO ACFT WINGSPAN MORE THAN 118FT',
            false,
        ];
        yield 'full runway closure' => ['RWY 10L/28R CLSD EXC XNG', false];
        yield 'taxiway construction' => ['TWY G BTN RWY 10R/28L AND TWY A CLSD CONSTRUCTION', false];
    }

    #[DataProvider('runwayAffectingPartialProvider')]
    public function testNotamTextIndicatesRunwayAffectingPartialRestriction(
        string $text,
        bool $expected
    ): void {
        $this->assertSame($expected, notamTextIndicatesRunwayAffectingPartialRestriction($text));
    }

    /**
     * @return iterable<string, array{0: string, 1: ?string}>
     */
    public static function closureDesignatorProvider(): iterable
    {
        yield 'pair' => ['BOI RWY 10L/28R CLSD EXC XNG', '10L/28R'];
        yield 'single end' => ['BOI RWY 10R CLSD', '10R'];
        yield 'taxiway landmark' => ['TWY G BTN RWY 10R/28L AND TWY A CLSD', null];
        yield 'approach partial' => [
            'TWY J BTN APCH END RWY 10R AND TWY W CLSD TO ACFT WINGSPAN MORE THAN 118FT',
            '10R',
        ];
    }

    #[DataProvider('closureDesignatorProvider')]
    public function testNotamExtractRunwayDesignatorForDisplay(string $text, ?string $expected): void
    {
        $this->assertSame($expected, notamExtractRunwayDesignatorForDisplay($text));
    }
}
