<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/status-checks.php';
require_once __DIR__ . '/../../lib/runway-fusion.php';

/**
 * Unit tests for checkRunwayFusionHealth() (status page inventory sanity).
 *
 * The test environment's cache dir may hold a leftover runways merge cache
 * (gitignored); tests that pin "input absent" semantics move it aside.
 */
final class StatusChecksRunwayFusionHealthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $base = dirname(CACHE_RUNWAY_FUSION_FILE);
        if (!is_dir($base)) {
            @mkdir($base, 0755, true);
        }
        if (is_file(CACHE_RUNWAY_FUSION_FILE)) {
            @unlink(CACHE_RUNWAY_FUSION_FILE);
        }
    }

    protected function tearDown(): void
    {
        if (is_file(CACHE_RUNWAY_FUSION_FILE)) {
            @unlink(CACHE_RUNWAY_FUSION_FILE);
        }
        parent::tearDown();
    }

    public function testHealth_MissingFile_ReturnsDegraded(): void
    {
        $h = checkRunwayFusionHealth();
        $this->assertSame('degraded', $h['status']);
        $this->assertStringContainsString('not present', $h['message']);
    }

    public function testHealth_InvalidJson_ReturnsDegraded(): void
    {
        file_put_contents(CACHE_RUNWAY_FUSION_FILE, '{not json');
        $h = checkRunwayFusionHealth();
        $this->assertSame('degraded', $h['status']);
        $this->assertStringContainsString('valid JSON', $h['message']);
    }

    public function testHealth_JsonScalarRoot_ReturnsDegraded(): void
    {
        file_put_contents(CACHE_RUNWAY_FUSION_FILE, '"not-an-object"');
        $h = checkRunwayFusionHealth();
        $this->assertSame('degraded', $h['status']);
        $this->assertStringContainsString('not an object', $h['message']);
    }

    public function testHealth_SchemaMismatch_ReturnsDegraded(): void
    {
        $this->writeArtifact(['schema_version' => 99]);
        $h = checkRunwayFusionHealth();
        $this->assertSame('degraded', $h['status']);
        $this->assertStringContainsString('schema version', $h['message']);
    }

    public function testHealth_MissingAirportsMap_ReturnsDegraded(): void
    {
        file_put_contents(
            CACHE_RUNWAY_FUSION_FILE,
            json_encode(['schema_version' => RUNWAY_FUSION_SCHEMA_VERSION])
        );
        $h = checkRunwayFusionHealth();
        $this->assertSame('degraded', $h['status']);
        $this->assertStringContainsString('airports map', $h['message']);
    }

    public function testHealth_MissingBuiltAt_ReturnsDegraded(): void
    {
        file_put_contents(
            CACHE_RUNWAY_FUSION_FILE,
            json_encode([
                'schema_version' => RUNWAY_FUSION_SCHEMA_VERSION,
                'airports' => [],
            ])
        );
        $h = checkRunwayFusionHealth();
        $this->assertSame('degraded', $h['status']);
        $this->assertStringContainsString('built_at', $h['message']);
    }

    public function testHealth_FreshArtifact_ReturnsOperational(): void
    {
        $this->writeArtifact([
            'schema_version' => RUNWAY_FUSION_SCHEMA_VERSION,
            'built_at' => time() - 60,
            'inputs' => $this->truthStamps(),
            'nasr_effective_date' => '2026-10-01',
            'airports' => ['42b' => ['rows' => [], 'segments' => [], 'calm_wind' => null]],
        ]);
        $h = checkRunwayFusionHealth();
        $this->assertSame('operational', $h['status']);
        $this->assertStringContainsString('1 airports fused', $h['message']);
        $this->assertStringContainsString('2026-10-01', $h['message']);
        $this->assertSame('2026-10-01', $h['details']['effective_date']);
        $this->assertSame(1, $h['details']['airport_count']);
        // The reference-data health consumer reads local_age_seconds; keep
        // age_seconds for existing consumers.
        $this->assertSame($h['details']['age_seconds'], $h['details']['local_age_seconds']);
        $this->assertGreaterThanOrEqual(60, $h['details']['local_age_seconds']);
    }

    public function testHealth_ExpiredArtifact_ReturnsDegraded(): void
    {
        $this->writeArtifact([
            'schema_version' => RUNWAY_FUSION_SCHEMA_VERSION,
            'built_at' => time() - RUNWAY_FUSION_MAX_AGE - 60,
            'inputs' => $this->truthStamps(),
            'airports' => [],
        ]);
        $h = checkRunwayFusionHealth();
        $this->assertSame('degraded', $h['status']);
        $this->assertStringContainsString('rebuild pending', $h['message']);
    }

    public function testHealth_NasrFileNewerThanStamp_ReturnsDegraded(): void
    {
        // Preserve a developer's existing NASR cache; the fixture moves the
        // mtime, so only a file the test created may be deleted. The hide
        // sits inside the try so the finally restores the original file
        // even when the hide itself fails.
        $hiddenNasr = null;
        try {
            $hiddenNasr = $this->hideFileIfPresent(CACHE_NASR_APT_DATA_FILE);
            $nasrDir = CACHE_NASR_DIR;
            if (!is_dir($nasrDir)) {
                @mkdir($nasrDir, 0755, true);
            }
            touch(CACHE_NASR_APT_DATA_FILE);
            $inputs = $this->truthStamps();
            $inputs['nasr_apt_mtime'] = 1000;
            $this->writeArtifact([
                'schema_version' => RUNWAY_FUSION_SCHEMA_VERSION,
                'built_at' => time() - 60,
                'inputs' => $inputs,
                'airports' => [],
            ]);
            $h = checkRunwayFusionHealth();
            $this->assertSame('degraded', $h['status']);
            $this->assertStringContainsString('NASR cache newer', $h['message']);
        } finally {
            if (is_file(CACHE_NASR_APT_DATA_FILE)) {
                @unlink(CACHE_NASR_APT_DATA_FILE);
            }
            $this->restoreHiddenFile($hiddenNasr);
        }
    }

    public function testHealth_ConfigNewerThanStamp_ReturnsDegraded(): void
    {
        $inputs = $this->truthStamps();
        $inputs['config_mtime'] = 1000;
        $this->writeArtifact([
            'schema_version' => RUNWAY_FUSION_SCHEMA_VERSION,
            'built_at' => time() - 60,
            'inputs' => $inputs,
            'airports' => [],
        ]);
        $h = checkRunwayFusionHealth();
        $this->assertSame('degraded', $h['status']);
        $this->assertStringContainsString('config newer', $h['message']);
    }

    public function testHealth_ConfigUnresolvable_ReturnsDegraded(): void
    {
        // The read-path gate falls back to the live resolver when the config
        // path cannot be resolved, so the status page must not claim the
        // inventory is servable either.
        $prior = getenv('CONFIG_PATH');
        putenv('CONFIG_PATH=/nonexistent/airports.json');
        try {
            $this->writeArtifact([
                'schema_version' => RUNWAY_FUSION_SCHEMA_VERSION,
                'built_at' => time() - 60,
                'inputs' => $this->truthStamps(),
                'airports' => [],
            ]);
            $h = checkRunwayFusionHealth();
            $this->assertSame('degraded', $h['status']);
            $this->assertStringContainsString('unresolvable', $h['message']);
        } finally {
            if ($prior === false) {
                putenv('CONFIG_PATH');
            } else {
                putenv('CONFIG_PATH=' . $prior);
            }
        }
    }

    public function testHealth_RunwaysCacheBuiltInThenGone_ReturnsDegraded(): void
    {
        // The merge retention gate deletes runways_data.json on failed
        // fetches; an inventory that assumed its geometry must show it.
        // The NASR cache goes inside the try so a failed hide still runs
        // the finally and restores the runways cache.
        $hidden = $this->hideFileIfPresent(CACHE_RUNWAYS_DATA_FILE);
        $hiddenNasr = null;
        try {
            $hiddenNasr = $this->hideFileIfPresent(CACHE_NASR_APT_DATA_FILE);
            $this->writeArtifact([
                'schema_version' => RUNWAY_FUSION_SCHEMA_VERSION,
                'built_at' => time() - 60,
                'inputs' => ['nasr_apt_mtime' => 0, 'runways_cache_mtime' => 1000, 'config_mtime' => PHP_INT_MAX],
                'airports' => [],
            ]);
            $h = checkRunwayFusionHealth();
            $this->assertSame('degraded', $h['status']);
            $this->assertStringContainsString('removed since build', $h['message']);
        } finally {
            $this->restoreHiddenFile($hidden);
            $this->restoreHiddenFile($hiddenNasr);
        }
    }

    public function testHealth_NasrBuiltOutAndStillGone_ReturnsOperational(): void
    {
        // Built without a NASR cache (stamp 0) and it is still gone: the
        // inventory holds every row the sources had, so it stays servable.
        // Same try placement as the sibling test: a failed hide must not
        // leave the runways cache hidden.
        $hiddenRunways = $this->hideFileIfPresent(CACHE_RUNWAYS_DATA_FILE);
        $hiddenNasr = null;
        try {
            $hiddenNasr = $this->hideFileIfPresent(CACHE_NASR_APT_DATA_FILE);
            $this->writeArtifact([
                'schema_version' => RUNWAY_FUSION_SCHEMA_VERSION,
                'built_at' => time() - 60,
                'inputs' => ['nasr_apt_mtime' => 0, 'runways_cache_mtime' => 0, 'config_mtime' => PHP_INT_MAX],
                'airports' => [],
            ]);
            $h = checkRunwayFusionHealth();
            $this->assertSame('operational', $h['status']);
        } finally {
            $this->restoreHiddenFile($hiddenRunways);
            $this->restoreHiddenFile($hiddenNasr);
        }
    }

    // --- Fixtures and helpers ------------------------------------------------

    /**
     * @param array<string, mixed> $overrides Payload overrides
     */
    private function writeArtifact(array $overrides): void
    {
        $payload = [
            'schema_version' => RUNWAY_FUSION_SCHEMA_VERSION,
            'built_at' => time() - 60,
            'inputs' => $this->truthStamps(),
            'airports' => [],
        ];
        foreach ($overrides as $key => $value) {
            $payload[$key] = $value;
        }
        file_put_contents(CACHE_RUNWAY_FUSION_FILE, json_encode($payload));
    }

    /**
     * Stamps that tell the truth about on-disk inputs, so a fresh artifact
     * passes the gate no matter which leftovers the cache dir holds.
     *
     * @return array<string, int>
     */
    private function truthStamps(): array
    {
        clearstatcache();

        $configPath = getConfigFilePath();

        return [
            'nasr_apt_mtime' => is_readable(CACHE_NASR_APT_DATA_FILE) ? (int) filemtime(CACHE_NASR_APT_DATA_FILE) : 0,
            'runways_cache_mtime' => is_readable(CACHE_RUNWAYS_DATA_FILE) ? (int) filemtime(CACHE_RUNWAYS_DATA_FILE) : 0,
            'config_mtime' => is_string($configPath) && $configPath !== '' && is_file($configPath)
                ? (int) filemtime($configPath)
                : 0,
        ];
    }

    private function hideFileIfPresent(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $hidden = $path . '.hidden.' . getmypid();
        if (!@rename($path, $hidden)) {
            $this->fail('could not move ' . $path . ' aside');
        }

        return $hidden;
    }

    private function restoreHiddenFile(?string $hiddenPath): void
    {
        if ($hiddenPath === null || !is_file($hiddenPath)) {
            return;
        }
        if (!@rename($hiddenPath, substr($hiddenPath, 0, -strlen('.hidden.' . getmypid())))) {
            $this->fail('could not restore ' . $hiddenPath);
        }
    }
}
