<?php
/**
 * Unit tests for GET /v1/weather/bulk selection and payload construction.
 *
 * Covers the all-airports mode, the weather-source operator filter, the
 * maintenance default, and the null-when-missing payload convention.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/v1/weather-bulk.php';

class PublicApiWeatherBulkTest extends TestCase
{
    private function fixtureConfig(): array
    {
        return json_decode(
            (string) file_get_contents(__DIR__ . '/../Fixtures/airports.json.test'),
            true
        );
    }

    private function seedWeatherCache(string $airportId, array $weather): void
    {
        $path = getWeatherCachePath($airportId);
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, json_encode($weather));
    }

    public function testResolveWeatherBulkCandidates_AllMode_ExcludesUnlistedAndMaintenance(): void
    {
        $errors = [];
        $candidates = resolveWeatherBulkCandidates($this->fixtureConfig(), false, null, $errors);

        $this->assertArrayHasKey('kspb', $candidates);
        $this->assertArrayNotHasKey('test', $candidates, 'Unlisted airports stay out of the all mode');
        $this->assertArrayNotHasKey('pdx', $candidates, 'Maintenance airports are excluded by default');
        $this->assertSame([], $errors);
    }

    public function testResolveWeatherBulkCandidates_AllMode_IncludeMaintenance(): void
    {
        $errors = [];
        $candidates = resolveWeatherBulkCandidates($this->fixtureConfig(), true, null, $errors);

        $this->assertArrayHasKey('pdx', $candidates);
        $this->assertArrayNotHasKey('test', $candidates, 'Unlisted airports stay out of the all mode');
    }

    public function testResolveWeatherBulkCandidates_Explicit_ValidatesAndDedupes(): void
    {
        $errors = [];
        $candidates = resolveWeatherBulkCandidates(
            $this->fixtureConfig(),
            false,
            ['kspb', 'KSPB', 'nope', 'bad!'],
            $errors
        );

        $this->assertArrayHasKey('kspb', $candidates);
        $this->assertCount(1, $candidates, 'Duplicate and case-variant ids resolve to one candidate');
        $this->assertArrayHasKey('nope', $errors, 'Unknown but well-formed id is reported as not found');
        $this->assertArrayHasKey('bad!', $errors, 'Malformed id is reported as invalid');
    }

    public function testResolveWeatherBulkCandidates_Explicit_MaintenanceAirportRequiresOptIn(): void
    {
        $errors = [];
        $without = resolveWeatherBulkCandidates($this->fixtureConfig(), false, ['pdx'], $errors);

        $this->assertSame([], $without);
        $this->assertArrayHasKey('pdx', $errors, 'Maintenance airport needs maintenance=true');

        $errors2 = [];
        $with = resolveWeatherBulkCandidates($this->fixtureConfig(), true, ['pdx'], $errors2);
        $this->assertArrayHasKey('pdx', $with);
        $this->assertSame([], $errors2);
    }

    public function testFilterWeatherBulkCandidates_AviationwxKeepsNonInstitutionalSources(): void
    {
        $errors = [];
        $base = resolveWeatherBulkCandidates($this->fixtureConfig(), false, null, $errors);
        $kept = filterWeatherBulkCandidates($base, 'aviationwx');

        $this->assertArrayHasKey('kspb', $kept, 'Tempest/WiFiLogger/Davis sources are aviationwx');
        $this->assertArrayHasKey('kaoc', $kept, 'DyaconLive is aviationwx');
        $this->assertArrayNotHasKey('03s', $kept, 'Metar-only (faa) stays out');
        $this->assertArrayNotHasKey('spfx', $kept);
        $this->assertArrayNotHasKey('cyav', $kept, 'SWOB (navcanada) stays out');
    }

    public function testFilterWeatherBulkCandidates_FaaKeepsMetarSources(): void
    {
        $errors = [];
        $base = resolveWeatherBulkCandidates($this->fixtureConfig(), false, null, $errors);
        $kept = filterWeatherBulkCandidates($base, 'faa');

        $this->assertArrayHasKey('kspb', $kept);
        $this->assertArrayHasKey('03s', $kept);
        $this->assertArrayHasKey('spfx', $kept);
        $this->assertArrayNotHasKey('kaoc', $kept);
        $this->assertArrayNotHasKey('cyav', $kept);
    }

    public function testBuildWeatherBulkPayload_MissingObservationIsNull(): void
    {
        $errors = [];
        $base = resolveWeatherBulkCandidates($this->fixtureConfig(), false, null, $errors);
        $this->seedWeatherCache('kspb', ['flight_category' => 'VFR', 'temperature' => 10]);

        try {
            [$data, $returned] = buildWeatherBulkPayload([
                'kspb' => $base['kspb'],
                '03s' => $base['03s'],
            ]);

            $this->assertIsArray($data['kspb']);
            $this->assertSame('VFR', $data['kspb']['flight_category']);
            $this->assertNull($data['03s'], 'No current observation is a null row, not an omission');
            $this->assertSame(1, $returned);
        } finally {
            @unlink(getWeatherCachePath('kspb'));
        }
    }
}
