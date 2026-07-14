<?php
/**
 * Unit tests for NASR APT parsing and runway selection.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/nasr/parse.php';
require_once __DIR__ . '/../../lib/nasr/runway-selection.php';
require_once __DIR__ . '/../../lib/nasr/cache.php';

class NasrParseTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        $this->fixtureDir = __DIR__ . '/../Fixtures/nasr';
        resetNasrAptCacheMemo();
    }

    public function testParseFixtureContainsExpectedAirports(): void
    {
        $parsed = nasrParseAptCsvDirectory($this->fixtureDir);
        $this->assertArrayHasKey('03S', $parsed['airports']);
        $this->assertArrayHasKey('ID76', $parsed['airports']);
        $this->assertArrayHasKey('C80', $parsed['airports']);
    }

    public function testSelectLongestRunwayExcludesFailedSurface(): void
    {
        $parsed = nasrParseAptCsvDirectory($this->fixtureDir);
        $selected = nasrSelectLongestActiveLandRunway($parsed['airports']['C80']);
        $this->assertNotNull($selected);
        $this->assertSame(5000, $selected['length_ft']);
        $this->assertSame('12/30', $selected['rwy_id']);
    }

    public function testSelectLongestRunwayUsesTurfRunwayForId76(): void
    {
        $parsed = nasrParseAptCsvDirectory($this->fixtureDir);
        $selected = nasrSelectLongestActiveLandRunway($parsed['airports']['ID76']);
        $this->assertNotNull($selected);
        $this->assertSame(2260, $selected['length_ft']);
        $this->assertTrue(nasrIsNonPavedSurface($selected['surface']));
    }
}
