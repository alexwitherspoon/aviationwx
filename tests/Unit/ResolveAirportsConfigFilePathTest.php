<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';

/**
 * Ensures resolveAirportsConfigFilePath() matches loadConfig() / getConfigFilePath() contract.
 */
final class ResolveAirportsConfigFilePathTest extends TestCase
{
    public function testResolve_MatchesGetConfigFilePath(): void
    {
        $a = resolveAirportsConfigFilePath();
        $b = getConfigFilePath();
        $this->assertSame($a, $b);
    }

    public function testResolve_WithPhpunitConfigPath_ReturnsReadableFile(): void
    {
        $resolved = resolveAirportsConfigFilePath();
        $this->assertNotNull($resolved);
        $this->assertFileExists($resolved);
        $this->assertTrue(is_readable($resolved));
    }

    public function testResolve_LoadConfigUsesSameFile(): void
    {
        $resolved = resolveAirportsConfigFilePath();
        $this->assertNotNull($resolved);
        $config = loadConfig(false);
        $this->assertNotNull($config);
        $this->assertSame($resolved, $GLOBALS['AVIATIONWX_CONFIG_FILE_PATH'] ?? null);
    }
}
