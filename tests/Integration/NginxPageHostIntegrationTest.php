<?php

use PHPUnit\Framework\TestCase;

class NginxPageHostIntegrationTest extends TestCase
{
    public function testExplicitPhpPageKeepsThePublicCanonicalHost(): void
    {
        $baseUrl = getenv("NGINX_TEST_URL");
        if (!$baseUrl) {
            $this->markTestSkipped("Set NGINX_TEST_URL to an isolated production-config proxy.");
        }
        foreach (["aviationwx.org", "kspb.aviationwx.org"] as $host) {
            $curl = curl_init(rtrim($baseUrl, "/") . "/index.php");
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_HTTPHEADER => ["Host: " . $host],
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_TIMEOUT => 15,
            ]);
            $html = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            $this->assertSame(200, $status, $host);
            $this->assertIsString($html);
            $this->assertSame(1, preg_match("/<link rel=\"canonical\" href=\"([^\"]+)\"/", $html, $matches));
            $this->assertSame($host, parse_url($matches[1], PHP_URL_HOST));
            $this->assertSame("https", parse_url($matches[1], PHP_URL_SCHEME));
        }
    }
}
