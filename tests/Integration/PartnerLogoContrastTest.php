<?php
/**
 * Integration tests: partner logo luminance is embedded for contrast tiles.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/partner-logo-luminance.php';

class PartnerLogoContrastTest extends TestCase
{
    private string $baseUrl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseUrl = getenv('TEST_API_URL')
            ?: getenv('TEST_BASE_URL')
            ?: 'http://localhost:8080';
    }

    public function testGetPartnerLogoMeanLuminance_AirportPageWithFixtureLogo_EmbedsDataLogoLumAttribute(): void
    {
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }

        $configPath = __DIR__ . '/../Fixtures/airports.json.test';
        if (!file_exists($configPath)) {
            $this->markTestSkipped('Test configuration not found');
        }

        $config = json_decode((string) file_get_contents($configPath), true);
        $partners = $config['airports']['kspb']['partners'] ?? [];
        if ($partners === [] || empty($partners[0]['logo'])) {
            $this->markTestSkipped('Test fixture has no partner logo');
        }

        $fixtureLogo = (string) $partners[0]['logo'];
        $expectedLum = getPartnerLogoMeanLuminance($fixtureLogo);
        if ($expectedLum === null) {
            $this->markTestSkipped('Fixture partner logo luminance unavailable');
        }

        $url = rtrim($this->baseUrl, '/') . '/?airport=kspb';
        $ch = curl_init($url);
        if ($ch === false) {
            $this->markTestSkipped('cURL could not initialize request');
        }

        try {
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, getenv('CI') ? 15 : 10);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, getenv('CI') ? 10 : 5);
            $html = curl_exec($ch);
            if ($html === false) {
                $this->markTestSkipped('cURL request failed: ' . curl_error($ch));
            }
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($httpCode === 0) {
                $this->markTestSkipped('Airport page not available');
            }

            $this->assertSame(200, $httpCode);
            $this->assertIsString($html);

            $fixtureLogoSrc = '/api/partner-logo.php?url=' . urlencode($fixtureLogo);
            if (!str_contains($html, $fixtureLogoSrc)) {
                $this->markTestSkipped(
                    'Server is not using test fixture partner logo config (run test-up on :9080 or CONFIG_PATH fixture)'
                );
            }

            $rounded = number_format(round($expectedLum, 4), 4, '.', '');
            $this->assertStringContainsString(
                'data-logo-lum="' . $rounded . '"',
                $html,
                'Partner link should carry server-computed logo luminance'
            );
            $this->assertStringContainsString(
                'PARTNER_LOGO_LUM_LIGHT',
                $html,
                'Bootstrap should expose contrast thresholds to the dashboard script'
            );
        } finally {
            unset($ch);
        }
    }
}
