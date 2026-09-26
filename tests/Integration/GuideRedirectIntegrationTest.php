<?php

use PHPUnit\Framework\TestCase;

class GuideRedirectIntegrationTest extends TestCase
{
    private function fetch(string $url, string $host = "guides.aviationwx.org"): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => ["Host: " . $host],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $location = curl_getinfo($curl, CURLINFO_REDIRECT_URL);
        if ($location === false) {
            $location = $this->parseLocationHeader($response);
        }
        curl_close($curl);
        return [$status, $location ?? false];
    }

    private function parseLocationHeader(string $response): ?string
    {
        $headerSize = strpos($response, "\r\n\r\n");
        $headers = substr($response, 0, $headerSize !== false ? $headerSize : 0);
        if (preg_match("/^Location:\s*(.+)$/mi", $headers, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    public function testGuideVariantsRedirectToCanonical(): void
    {
        $baseUrl = getenv("NGINX_TEST_URL");
        if (!$baseUrl) {
            $this->markTestSkipped("Set NGINX_TEST_URL to the isolated nginx proxy.");
        }
        $canonical = "https://guides.aviationwx.org/08-camera-configuration";
        $variants = ["/08-camera-configuration.md", "/08-camera-configuration/", "/08-camera-configuration.md/"];
        foreach ($variants as $variant) {
            [$status, $location] = $this->fetch(rtrim($baseUrl, "/") . $variant);
            $this->assertSame(301, $status, "Variant {$variant} should return 301");
            $this->assertSame($canonical, $location, "Variant {$variant} should redirect to canonical");
        }
    }

    public function testGuideVariantPreservesQueryState(): void
    {
        $baseUrl = getenv("NGINX_TEST_URL");
        if (!$baseUrl) {
            $this->markTestSkipped("Set NGINX_TEST_URL to the isolated nginx proxy.");
        }
        [$status, $location] = $this->fetch(rtrim($baseUrl, "/") . "/08-camera-configuration.md?cam=0&period=day");
        $this->assertSame(301, $status);
        $this->assertSame("https://guides.aviationwx.org/08-camera-configuration?cam=0&period=day", $location);
    }

    public function testCanonicalGuideUrlServes200(): void
    {
        $baseUrl = getenv("NGINX_TEST_URL");
        if (!$baseUrl) {
            $this->markTestSkipped("Set NGINX_TEST_URL to the isolated nginx proxy.");
        }
        $curl = curl_init(rtrim($baseUrl, "/") . "/08-camera-configuration");
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Host: guides.aviationwx.org"],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 15,
        ]);
        $html = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        $this->assertSame(200, $status);
        $this->assertSame(1, preg_match("/<link rel=\"canonical\" href=\"([^\"]+)\"/", $html, $matches));
        $this->assertSame("https://guides.aviationwx.org/08-camera-configuration", $matches[1]);
    }

    public function testUnknownGuideSlugReturns404WithoutRedirect(): void
    {
        $baseUrl = getenv("NGINX_TEST_URL");
        if (!$baseUrl) {
            $this->markTestSkipped("Set NGINX_TEST_URL to the isolated nginx proxy.");
        }
        [$status, $location] = $this->fetch(rtrim($baseUrl, "/") . "/no-such-guide");
        $this->assertSame(404, $status);
        $this->assertFalse($location, "Unknown guide should not redirect");
    }

    public function testUnknownGuideMdReturns404WithoutRedirect(): void
    {
        $baseUrl = getenv("NGINX_TEST_URL");
        if (!$baseUrl) {
            $this->markTestSkipped("Set NGINX_TEST_URL to the isolated nginx proxy.");
        }
        [$status, $location] = $this->fetch(rtrim($baseUrl, "/") . "/no-such-guide.md");
        $this->assertSame(404, $status);
        $this->assertFalse($location, "Unknown .md guide should not redirect");
    }

    public function testIndexVariantsRedirectToGuidesSubdomain(): void
    {
        $baseUrl = getenv("NGINX_TEST_URL");
        if (!$baseUrl) {
            $this->markTestSkipped("Set NGINX_TEST_URL to the isolated nginx proxy.");
        }
        $variants = ["/README.md", "/readme.md"];
        foreach ($variants as $variant) {
            [$status, $location] = $this->fetch(rtrim($baseUrl, "/") . $variant);
            $this->assertSame(301, $status, "Variant {$variant} should return 301");
            $this->assertSame("https://guides.aviationwx.org/", $location, "Variant {$variant} should redirect to index");
        }
    }

    public function testPathBasedGuideMdRedirectsToCanonical(): void
    {
        $baseUrl = getenv("NGINX_TEST_URL");
        if (!$baseUrl) {
            $this->markTestSkipped("Set NGINX_TEST_URL to the isolated nginx proxy.");
        }

        // Path-based .md variant on the apex domain. The apex-to-guides 301
        // from redirectProductionApexToCanonicalSubdomain only fires in
        // production, but guides.php still redirects the .md variant to the
        // canonical extensionless URL on the guides subdomain regardless.
        [$status, $location] = $this->fetch(
            rtrim($baseUrl, "/") . "/guides/13-using-the-airport-dashboard.md",
            "aviationwx.org"
        );
        $this->assertSame(301, $status, "Path-based .md should redirect");
        $this->assertSame(
            "https://guides.aviationwx.org/13-using-the-airport-dashboard",
            $location
        );
    }
}
