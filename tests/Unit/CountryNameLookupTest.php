<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for the bundled ISO 3166-1 country-name lookup.
 */
class CountryNameLookupTest extends TestCase
{
    public function testKnownCodeReturnsName(): void
    {
        $this->assertSame('United States of America', countryNameFromIso3166Alpha2('US'));
    }

    public function testLowerCaseIsNormalized(): void
    {
        $this->assertSame('Canada', countryNameFromIso3166Alpha2('ca'));
    }

    public function testCommaNameIsQuoted(): void
    {
        $this->assertSame('Korea, Republic of', countryNameFromIso3166Alpha2('KR'));
    }

    public function testUnknownOrInvalidReturnsNull(): void
    {
        $this->assertNull(countryNameFromIso3166Alpha2('ZZ'));
        $this->assertNull(countryNameFromIso3166Alpha2('USA'));
        $this->assertNull(countryNameFromIso3166Alpha2(''));
        $this->assertNull(countryNameFromIso3166Alpha2(null));
    }

    public function testSubdivisionNameFromIso3166(): void
    {
        $this->assertSame('Oregon', subdivisionNameFromIso3166('US-OR'));
        $this->assertSame('Ontario', subdivisionNameFromIso3166('ca-on'));
        $this->assertNull(subdivisionNameFromIso3166('ZZ-ZZ'));
        $this->assertNull(subdivisionNameFromIso3166(null));
    }
}