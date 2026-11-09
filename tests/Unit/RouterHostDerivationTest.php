<?php

declare(strict_types=1);

namespace AviationWx\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Source-level guard for the router host derivation.
 *
 * A production regression shipped when $hostWithoutPort was computed from
 * itself instead of $host, which made it an empty string and dropped every
 * feature subdomain into the airport 404. These assertions keep the
 * derivation and the port-stripped subdomain matches wired together.
 */
final class RouterHostDerivationTest extends TestCase
{
    private string $router;

    /**
     * Load the router source once per test.
     */
    protected function setUp(): void
    {
        $this->router = (string) file_get_contents(dirname(__DIR__, 2) . '/index.php');
    }

    /**
     * The port stripping reads $host, never the variable being assigned.
     */
    public function testHostStrippingReadsHostNotItself(): void
    {
        $this->assertStringContainsString(
            "preg_replace('/:\d+$/', '', \$host)",
            $this->router,
            'The port-stripped host must be derived from $host'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\$hostWithoutPort\s*=\s*preg_replace\([^;]*\$hostWithoutPort\s*\)/',
            $this->router,
            'Assigning $hostWithoutPort from itself leaves it empty and breaks subdomain routing'
        );
    }

    /**
     * Each feature subdomain must match the port-stripped host.
     */
    public function testFeatureSubdomainsMatchThePortStrippedHost(): void
    {
        foreach (['guides', 'terms', 'embed', 'discover', 'airports'] as $label) {
            $line = $this->lineContaining("preg_match('/^" . $label . "\\.'");
            $this->assertNotNull($line, "No subdomain match found for {$label}");
            $this->assertStringContainsString(
                '$hostWithoutPort',
                $line,
                "The {$label} subdomain must match the port-stripped host"
            );
        }
    }

    /**
     * The airport subdomain pattern matches the port-stripped host.
     */
    public function testAirportSubdomainMatchesThePortStrippedHost(): void
    {
        $line = $this->lineContaining('preg_match($pattern,');
        $this->assertNotNull($line, 'No airport subdomain pattern match found');
        $this->assertStringContainsString(
            '$hostWithoutPort',
            $line,
            'The airport subdomain must match the port-stripped host'
        );
    }

    /**
     * Return the first router line containing the needle, or null.
     */
    private function lineContaining(string $needle): ?string
    {
        foreach (explode("\n", $this->router) as $line) {
            if (str_contains($line, $needle)) {
                return $line;
            }
        }

        return null;
    }
}
