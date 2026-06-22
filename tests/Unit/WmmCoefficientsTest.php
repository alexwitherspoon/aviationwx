<?php

declare(strict_types=1);

namespace AviationWX\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/wmm/WmmCoefficients.php';

class WmmCoefficientsTest extends TestCase
{
    public function testFromBundledPath_ManifestMetadata_MatchesCofFile(): void
    {
        $manifestPath = \WmmCoefficients::getBundledManifestPath();
        $this->assertFileExists($manifestPath);

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $this->assertIsArray($manifest);
        foreach (['cof_sha256', 'model', 'epoch', 'release_date'] as $key) {
            $this->assertArrayHasKey($key, $manifest, "Manifest missing required key: $key");
        }

        $cofPath = \WmmCoefficients::getBundledCofPath();
        $this->assertFileExists($cofPath);

        $actualHash = hash_file('sha256', $cofPath);
        $this->assertSame($manifest['cof_sha256'], $actualHash);

        $coefficients = \WmmCoefficients::fromBundledPath();
        $this->assertSame($manifest['model'], $coefficients->getModelName());
        $this->assertEqualsWithDelta((float) $manifest['epoch'], $coefficients->getEpoch(), 0.0001);
        $this->assertSame($manifest['release_date'], $coefficients->getReleaseDate());
    }

    public function testConstructor_MissingCofFile_ThrowsInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new \WmmCoefficients('/nonexistent/WMM.COF');
    }
}
