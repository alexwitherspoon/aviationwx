<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Drives index.php with the Host header for each feature subdomain.
 *
 * The router picks the page by host before airport detection, so a regression
 * there surfaces as a 404 from the airport handler. Each case also checks a
 * page title so a fallback to the homepage cannot pass on status alone. Runs
 * against the isolated test stack (TEST_API_URL), whose fixture base_domain is
 * aviationwx.org.
 */
final class SubdomainRoutingTest extends TestCase
{
    private string $baseUrl;

    /**
     * Skips unless the isolated test stack URL is configured.
     */
    protected function setUp(): void
    {
        $url = getenv('TEST_API_URL');
        if (!is_string($url) || $url === '') {
            $this->markTestSkipped('Set TEST_API_URL to the isolated test stack.');
        }
        $this->baseUrl = rtrim($url, '/');
    }

    /**
     * A feature subdomain serves its own page, not an airport 404 or the homepage.
     */
    #[DataProvider('featureSubdomainProvider')]
    public function testFeatureSubdomainServesItsPage(string $host, string $marker): void
    {
        [$status, $body] = $this->request('/', $host);
        $this->assertSame(200, $status, "{$host}/ should serve its page, not an airport 404");
        $this->assertStringContainsString(
            $marker,
            $body,
            "{$host}/ should serve its own page, not the homepage fallback"
        );
    }

    /**
     * Feature subdomains routed by index.php and a marker unique to each page.
     */
    public static function featureSubdomainProvider(): array
    {
        return [
            'apex' => ['aviationwx.org', 'AviationWX.org - Live Airport Webcams'],
            'airports' => ['airports.aviationwx.org', 'Airport Network Map - AviationWX.org'],
            'guides' => ['guides.aviationwx.org', 'AviationWX Airport Installation Guides'],
            'terms' => ['terms.aviationwx.org', 'Terms of Service - AviationWX.org'],
            'embed' => ['embed.aviationwx.org', 'Embed Generator - AviationWX.org'],
            'status' => ['status.aviationwx.org', 'AviationWX Status'],
        ];
    }

    /**
     * The discover host serves the health endpoint, which proves the shell route.
     */
    public function testDiscoverSubdomainServesHealthEndpoint(): void
    {
        // The bare shell runs a per-airport health scan. The single-id health
        // read proves the discover route without that network scan.
        [$status, $body] = $this->request('/?health=__nonexistent__', 'discover.aviationwx.org');
        $this->assertSame(200, $status);
        $data = json_decode($body, true);
        $this->assertIsArray($data);
        $this->assertSame('__nonexistent__', $data['id'] ?? null);
        $this->assertSame('down', $data['status'] ?? null);
    }

    /**
     * The discover host renders the shell, not an airport 404 or the homepage.
     */
    public function testDiscoverShellRenders(): void
    {
        [$status, $body] = $this->request('/', 'discover.aviationwx.org');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('id="discover-map"', $body);
        $this->assertStringContainsString('DISCOVER_DECK', $body);
    }

    /**
     * Routing ignores a port on the Host header.
     */
    #[DataProvider('portedHostProvider')]
    public function testSubdomainRoutingIgnoresPort(string $host, string $marker): void
    {
        [$status, $body] = $this->request('/', $host);
        $this->assertSame(200, $status, "{$host} should route the same with a port");
        $this->assertStringContainsString($marker, $body, "{$host} should serve its own page with a port");
    }

    /**
     * Hosts with ports used by the port-insensitivity cases, and their markers.
     */
    public static function portedHostProvider(): array
    {
        return [
            'airports with port' => ['airports.aviationwx.org:8443', 'Airport Network Map - AviationWX.org'],
            'guides with port' => ['guides.aviationwx.org:8443', 'AviationWX Airport Installation Guides'],
            'discover with port' => ['discover.aviationwx.org:8443', 'Discover - AviationWX.org'],
        ];
    }

    /**
     * www is a homepage redirect, never an airport id.
     */
    #[DataProvider('wwwHostProvider')]
    public function testWwwHostServesHomepageNotAirport404(string $host, string $marker): void
    {
        [$status, $body] = $this->request('/', $host);
        $this->assertSame(200, $status, "{$host} should serve the homepage, not an airport 404");
        $this->assertStringContainsString($marker, $body, "{$host} should serve the homepage");
    }

    /**
     * Hosts that must land on the homepage, and the homepage marker.
     */
    public static function wwwHostProvider(): array
    {
        return [
            'www' => ['www.aviationwx.org', 'AviationWX.org - Live Airport Webcams'],
            'www with port' => ['www.aviationwx.org:8443', 'AviationWX.org - Live Airport Webcams'],
        ];
    }

    /**
     * An unknown single-label subdomain stays a 404.
     */
    public function testUnknownSubdomainReturns404(): void
    {
        [$status] = $this->request('/', 'nope.aviationwx.org');
        $this->assertSame(404, $status, 'An unknown subdomain must stay a 404');
    }

    /**
     * Send a GET request with an explicit Host header.
     *
     * @return array{0:int,1:string}
     */
    private function request(string $path, string $host): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Host: ' . $host],
            CURLOPT_TIMEOUT => 15,
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$status, is_string($body) ? $body : ''];
    }
}
