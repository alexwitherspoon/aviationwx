<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/status-checks.php';

/**
 * Unit tests for checkAirportCountryResolutionHealth() (status page aggregate sanity).
 */
final class StatusChecksCountryResolutionHealthTest extends TestCase
{
    private string $aggregatePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->aggregatePath = CACHE_AIRPORT_COUNTRY_RESOLUTION_FILE;
        $base = dirname($this->aggregatePath);
        if (!is_dir($base)) {
            mkdir($base, 0755, true);
        }
        if (is_file($this->aggregatePath)) {
            unlink($this->aggregatePath);
        }
    }

    protected function tearDown(): void
    {
        if (is_file($this->aggregatePath)) {
            unlink($this->aggregatePath);
        }
        parent::tearDown();
    }

    public function testCheckAirportCountryResolutionHealth_MissingFile_ReturnsDegraded(): void
    {
        $h = checkAirportCountryResolutionHealth(null);
        $this->assertSame('degraded', $h['status']);
        $this->assertStringContainsString('not present', $h['message']);
    }

    public function testCheckAirportCountryResolutionHealth_InvalidJson_ReturnsDegraded(): void
    {
        file_put_contents($this->aggregatePath, '{not json');
        $h = checkAirportCountryResolutionHealth(null);
        $this->assertSame('degraded', $h['status']);
        $this->assertStringContainsString('valid JSON', $h['message']);
    }

    public function testCheckAirportCountryResolutionHealth_JsonScalarRoot_ReturnsDegraded(): void
    {
        file_put_contents($this->aggregatePath, '"not-an-object"');
        $h = checkAirportCountryResolutionHealth(null);
        $this->assertSame('degraded', $h['status']);
        $this->assertStringContainsString('not an object', $h['message']);
    }

    public function testCheckAirportCountryResolutionHealth_SchemaMismatch_ReturnsDegraded(): void
    {
        $this->writeAggregate([
            'version' => 99999,
            'generated_at' => gmdate('c'),
            'config_sha256' => '',
            'airports' => ['kabc' => ['iso_country' => 'US']],
        ]);
        $h = checkAirportCountryResolutionHealth(null);
        $this->assertSame('degraded', $h['status']);
        $this->assertStringContainsString('schema version', $h['message']);
    }

    public function testCheckAirportCountryResolutionHealth_ValidAggregateAndSha_ReturnsOperational(): void
    {
        $cfgPath = getConfigFilePath();
        $this->assertNotFalse($cfgPath);
        $this->assertNotSame('', $cfgPath);
        $raw = file_get_contents($cfgPath);
        $this->assertNotFalse($raw);
        $sha = hash('sha256', $raw);

        $this->writeAggregate([
            'version' => COUNTRY_RESOLUTION_AGGREGATE_SCHEMA_VERSION,
            'generated_at' => gmdate('c'),
            'config_sha256' => $sha,
            'boundary_dataset' => 'test',
            'airports' => [
                'kabc' => ['iso_country' => 'US'],
            ],
        ]);

        $config = loadConfig();
        $h = checkAirportCountryResolutionHealth($config);
        $this->assertSame('operational', $h['status']);
        $this->assertSame(true, $h['details']['config_sha_matches']);
    }

    public function testCheckAirportCountryResolutionHealth_ShaMismatch_ReturnsDegraded(): void
    {
        $this->writeAggregate([
            'version' => COUNTRY_RESOLUTION_AGGREGATE_SCHEMA_VERSION,
            'generated_at' => gmdate('c'),
            'config_sha256' => str_repeat('a', 64),
            'airports' => [
                'kabc' => ['iso_country' => 'US'],
            ],
        ]);

        $cfgPath = getConfigFilePath();
        $raw = file_get_contents($cfgPath);
        $this->assertNotFalse($raw);

        $config = loadConfig();
        $h = checkAirportCountryResolutionHealth($config);
        $this->assertSame('degraded', $h['status']);
        $this->assertStringContainsString('predates', $h['message']);
    }

    public function testCheckAirportCountryResolutionHealth_MissingIsoCountryInSample_ReturnsDegraded(): void
    {
        $this->writeAggregate([
            'version' => COUNTRY_RESOLUTION_AGGREGATE_SCHEMA_VERSION,
            'generated_at' => gmdate('c'),
            'config_sha256' => '',
            'airports' => [
                'kbad' => ['wrong' => true],
                'kabc' => ['iso_country' => 'US'],
            ],
        ]);

        $h = checkAirportCountryResolutionHealth(null);
        $this->assertSame('degraded', $h['status']);
        $this->assertStringContainsString('iso_country', $h['message']);
    }

    public function testCheckAirportCountryResolutionHealth_EmptyAggregateWithConfigAirports_ReturnsDegraded(): void
    {
        $this->writeAggregate([
            'version' => COUNTRY_RESOLUTION_AGGREGATE_SCHEMA_VERSION,
            'generated_at' => gmdate('c'),
            'config_sha256' => '',
            'airports' => [],
        ]);

        $config = loadConfig();
        $this->assertNotEmpty($config['airports'] ?? []);

        $h = checkAirportCountryResolutionHealth($config);
        $this->assertSame('degraded', $h['status']);
        $this->assertStringContainsString('empty', $h['message']);
    }

    public function testCheckAirportCountryResolutionHealth_FilePastMaxAge_ReturnsDegraded(): void
    {
        $cfgPath = getConfigFilePath();
        $raw = file_get_contents($cfgPath);
        $this->assertNotFalse($raw);
        $sha = hash('sha256', $raw);

        $this->writeAggregate([
            'version' => COUNTRY_RESOLUTION_AGGREGATE_SCHEMA_VERSION,
            'generated_at' => gmdate('c', time() - 86400 * 400),
            'config_sha256' => $sha,
            'airports' => [
                'kabc' => ['iso_country' => 'US'],
            ],
        ]);

        $oldMtime = time() - COUNTRY_RESOLUTION_AGGREGATE_MAX_AGE_SECONDS - 3600;
        touch($this->aggregatePath, $oldMtime);

        $config = loadConfig();
        $h = checkAirportCountryResolutionHealth($config);
        $this->assertSame('degraded', $h['status']);
        $this->assertStringContainsString('refresh policy', $h['message']);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeAggregate(array $data): void
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        file_put_contents($this->aggregatePath, $json);
        touch($this->aggregatePath, time());
    }
}
