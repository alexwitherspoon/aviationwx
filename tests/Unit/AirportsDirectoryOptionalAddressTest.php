<?php
/**
 * Regression: directory cards must tolerate airports without address.
 *
 * @see pages/airports.php
 */

use PHPUnit\Framework\TestCase;

class AirportsDirectoryOptionalAddressTest extends TestCase
{
    private string $airportsPhp;

    protected function setUp(): void
    {
        parent::setUp();
        $path = dirname(__DIR__, 2) . '/pages/airports.php';
        $this->assertFileExists($path);
        $raw = file_get_contents($path);
        $this->assertIsString($raw);
        $this->airportsPhp = $raw;
    }

    public function testDirectoryCardToleratesMissingAirportAddress(): void
    {
        $this->assertStringContainsString(
            "\$airportAddress = trim((string) (\$airport['address'] ?? ''));",
            $this->airportsPhp
        );
        $this->assertStringContainsString(
            'if ($airportAddress !== \'\'):',
            $this->airportsPhp
        );
        $this->assertStringContainsString(
            'htmlspecialchars($airportAddress)',
            $this->airportsPhp
        );
        $this->assertStringNotContainsString(
            'htmlspecialchars($airport[\'address\'])',
            $this->airportsPhp
        );
    }
}
