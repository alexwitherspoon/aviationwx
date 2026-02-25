<?php
/**
 * Unit Tests for Timezone Display Helper
 *
 * Safety-critical: Ensures airport local time and timezone abbreviation
 * are displayed correctly. Uses server-side PHP (reliable IANA data) instead
 * of browser Intl API which can return wrong abbreviations (e.g. PST for MST).
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/weather/utils.php';
require_once __DIR__ . '/../../lib/config.php';

class TimezoneDisplayTest extends TestCase
{
    /**
     * getTimezoneDisplayForAirport returns correct structure
     */
    public function testGetTimezoneDisplayForAirport_ReturnsCorrectStructure(): void
    {
        $airport = ['timezone' => 'America/Denver'];
        $result = getTimezoneDisplayForAirport($airport);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('abbreviation', $result);
        $this->assertArrayHasKey('offset_hours', $result);
        $this->assertArrayHasKey('offset_display', $result);
        $this->assertArrayHasKey('timezone', $result);
        $this->assertIsString($result['abbreviation']);
        $this->assertIsInt($result['offset_hours']);
        $this->assertMatchesRegularExpression('/^\(UTC[+-]\d+\)$/', $result['offset_display']);
    }

    /**
     * America/Denver returns MST or MDT (Mountain) - never PST
     */
    public function testGetTimezoneDisplayForAirport_AmericaDenver_ReturnsMountainAbbreviation(): void
    {
        $airport = ['timezone' => 'America/Denver'];
        $result = getTimezoneDisplayForAirport($airport);

        $this->assertContains($result['abbreviation'], ['MST', 'MDT'], 'America/Denver must return MST or MDT, not PST');
        $this->assertGreaterThanOrEqual(-7, $result['offset_hours']);
        $this->assertLessThanOrEqual(-6, $result['offset_hours']);
    }

    /**
     * America/Los_Angeles returns PST or PDT (Pacific)
     */
    public function testGetTimezoneDisplayForAirport_AmericaLosAngeles_ReturnsPacificAbbreviation(): void
    {
        $airport = ['timezone' => 'America/Los_Angeles'];
        $result = getTimezoneDisplayForAirport($airport);

        $this->assertContains($result['abbreviation'], ['PST', 'PDT'], 'America/Los_Angeles must return PST or PDT');
        $this->assertGreaterThanOrEqual(-8, $result['offset_hours']);
        $this->assertLessThanOrEqual(-7, $result['offset_hours']);
    }

    /**
     * America/New_York returns EST or EDT
     */
    public function testGetTimezoneDisplayForAirport_AmericaNewYork_ReturnsEasternAbbreviation(): void
    {
        $airport = ['timezone' => 'America/New_York'];
        $result = getTimezoneDisplayForAirport($airport);

        $this->assertContains($result['abbreviation'], ['EST', 'EDT'], 'America/New_York must return EST or EDT');
        $this->assertGreaterThanOrEqual(-5, $result['offset_hours']);
        $this->assertLessThanOrEqual(-4, $result['offset_hours']);
    }

    /**
     * UTC returns UTC with zero offset
     */
    public function testGetTimezoneDisplayForAirport_UTC_ReturnsUtc(): void
    {
        $airport = ['timezone' => 'UTC'];
        $result = getTimezoneDisplayForAirport($airport);

        $this->assertEquals('UTC', $result['abbreviation']);
        $this->assertEquals(0, $result['offset_hours']);
        $this->assertEquals('(UTC+0)', $result['offset_display']);
    }

    /**
     * Airport without timezone uses default
     */
    public function testGetTimezoneDisplayForAirport_NoTimezone_UsesDefault(): void
    {
        $airport = [];
        $result = getTimezoneDisplayForAirport($airport);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('abbreviation', $result);
        $this->assertArrayHasKey('offset_hours', $result);
        $this->assertNotEmpty($result['timezone']);
    }

    /**
     * Invalid timezone returns safe fallback
     */
    public function testGetTimezoneDisplayForAirport_InvalidTimezone_ReturnsFallback(): void
    {
        $airport = ['timezone' => 'Invalid/Timezone'];
        $result = getTimezoneDisplayForAirport($airport);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result['abbreviation'], 'Must have fallback abbreviation');
        $this->assertArrayHasKey('offset_hours', $result);
    }

    /**
     * Offset display format matches expected pattern
     */
    public function testGetTimezoneDisplayForAirport_OffsetDisplay_MatchesPattern(): void
    {
        $airports = [
            ['timezone' => 'America/Denver'],
            ['timezone' => 'America/Los_Angeles'],
            ['timezone' => 'UTC'],
            ['timezone' => 'Europe/London'],
        ];

        foreach ($airports as $airport) {
            $result = getTimezoneDisplayForAirport($airport);
            $this->assertMatchesRegularExpression(
                '/^\(UTC[+-]\d+\)$|^\(UTC\+0\)$/',
                $result['offset_display'],
                "Offset display for {$airport['timezone']} must match (UTC±N) pattern"
            );
        }
    }

    /**
     * Abbreviation and offset are consistent (MST=-7, MDT=-6, PST=-8, PDT=-7)
     */
    public function testGetTimezoneDisplayForAirport_AbbreviationMatchesOffset(): void
    {
        $airport = ['timezone' => 'America/Denver'];
        $result = getTimezoneDisplayForAirport($airport);

        if ($result['abbreviation'] === 'MST') {
            $this->assertEquals(-7, $result['offset_hours'], 'MST must be UTC-7');
        } elseif ($result['abbreviation'] === 'MDT') {
            $this->assertEquals(-6, $result['offset_hours'], 'MDT must be UTC-6');
        }
        // PST=-8, PDT=-7 for Pacific - similar check if we add Pacific test
    }
}
