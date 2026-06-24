<?php
/**
 * Unit Tests for buildFullWindSection()
 *
 * Safety-critical: the full-* embed wind block drives pilot decision-making.
 * Verifies the dashboard-parity facts (Gust Factor, Peak Gust, legend), the
 * fail-closed "---" behavior for stale/missing wind, and that the redundant
 * compass summary line is not rendered.
 *
 * @package AviationWX\Tests\Unit
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/units.php';
require_once __DIR__ . '/../../lib/embed-templates/full.php';

class BuildFullWindSectionTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = ['windUnit' => 'kt'];

    /** @var array<string, mixed> */
    private array $fullModeOptions = ['magneticDeclination' => 13, 'lastHourWind' => []];

    private function render(array $weather, ?array $fullModeOptions = null): string
    {
        return buildFullWindSection(
            $weather,
            $this->options,
            $fullModeOptions ?? $this->fullModeOptions,
            'test-canvas',
            'America/Los_Angeles'
        );
    }

    /**
     * A windy site renders the full dashboard-parity facts and legend.
     */
    public function testWindySite_RendersFactsAndLegend(): void
    {
        $html = $this->render([
            'wind_direction_magnetic' => 290,
            'wind_speed' => 12,
            'gust_speed' => 18,
            'gust_factor' => 6,
            'peak_gust_today' => 20,
            'peak_gust_time' => 1700000000,
        ]);

        $this->assertStringContainsString('💨 Wind', $html);
        $this->assertStringContainsString('Direction', $html);
        $this->assertStringContainsString('290°', $html);
        $this->assertStringContainsString('<span class="sub">Mag</span>', $html);
        $this->assertStringContainsString('Gust Factor', $html);
        $this->assertStringContainsString('Peak Gust', $html);
        $this->assertStringContainsString('wf-legend', $html);
        $this->assertStringContainsString('lg-true', $html);
        $this->assertStringContainsString('True N (13°E)', $html);
    }

    /**
     * The redundant METAR summary line below the compass is not rendered.
     */
    public function testNoSummaryLineBelowCompass(): void
    {
        $html = $this->render([
            'wind_direction_magnetic' => 290,
            'wind_speed' => 12,
            'gust_speed' => 18,
        ]);

        $this->assertStringNotContainsString('wind-summary', $html);
    }

    /**
     * Calm wind shows "Calm" for speed and never a summary line.
     */
    public function testCalmWind_ShowsCalm(): void
    {
        $html = $this->render([
            'wind_direction_magnetic' => 240,
            'wind_speed' => 1,
        ]);

        $this->assertStringContainsString('Calm', $html);
        $this->assertStringNotContainsString('wind-summary', $html);
    }

    /**
     * Stale/missing wind fails closed to "---" rather than showing 0 or Calm.
     */
    public function testStaleWind_FailsClosed(): void
    {
        $html = $this->render([
            'wind_direction_magnetic' => null,
            'wind_speed' => null,
            'gust_speed' => null,
            'gust_factor' => null,
        ]);

        // Direction, Speed, Gusting, and Gust Factor all fall back to ---
        $this->assertStringContainsString('---', $html);
        $this->assertStringNotContainsString('Calm', $html);
        $this->assertStringNotContainsString('<span class="sub">Mag</span>', $html);
    }

    /**
     * The "last hr" petal legend appears only when recent wind data exists.
     */
    public function testPetalLegend_OnlyWhenRecentWindPresent(): void
    {
        $weather = ['wind_direction_magnetic' => 290, 'wind_speed' => 12];

        $active = array_fill(0, 16, 0);
        $active[7] = 5;
        $withPetals = $this->render($weather, ['magneticDeclination' => 13, 'lastHourWind' => $active]);
        $this->assertStringContainsString('last hr', $withPetals);
        $this->assertStringContainsString('lg-petal', $withPetals);

        $withoutPetals = $this->render($weather, ['magneticDeclination' => 13, 'lastHourWind' => array_fill(0, 16, 0)]);
        $this->assertStringNotContainsString('last hr', $withoutPetals);
    }

    /**
     * Zero magnetic declination omits the misleading "0°" variation label.
     */
    public function testZeroDeclination_OmitsVariationLabel(): void
    {
        $html = $this->render(
            ['wind_direction_magnetic' => 290, 'wind_speed' => 12],
            ['magneticDeclination' => 0, 'lastHourWind' => []]
        );

        $this->assertStringContainsString('True N', $html);
        $this->assertStringNotContainsString('True N (0°', $html);
    }
}
