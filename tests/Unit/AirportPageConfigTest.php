<?php
/**
 * Unit Tests for AirportPageConfig (browser-safe allowlist)
 *
 * Ensures the JSON serialized into AIRPORT_DATA and the airports-directory
 * page never contains credentials: push_config.username/password,
 * weather_sources.api_key, bridges.api_key, openweathermap_api_key.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';

class AirportPageConfigTest extends TestCase
{
    private function fullAirportWithCreds(): array
    {
        return [
            'id' => 'kspb',
            'name' => 'San Luis County Regional Airport',
            'icao' => 'KSZP',
            'iata' => 'SLO',
            'faa' => 'SLO',
            'country' => 'US',
            'iso_country' => 'US',
            'lat' => 35.2368,
            'lon' => -120.6425,
            'elevation_ft' => 170,
            'timezone' => 'America/Los_Angeles',
            'timezone_abbreviation' => 'PST/PDT',
            'timezone_offset_hours' => -8,
            'access_type' => 'public',
            'permission_required' => false,
            'tower_status' => 'towered',
            'address' => '100 Aviation Drive, San Luis Obispo, CA 93401',
            'weather_refresh_seconds' => 60,
            'webcam_refresh_seconds' => 60,
            'maintenance' => false,
            'limited_availability' => false,
            'weather_sources' => [
                [
                    'type' => 'tempest',
                    'station_id' => '12345',
                    'api_key' => 'leaked-tempest-key-12345',
                    'base_url' => 'https://swd.weatherflow.com',
                ],
                [
                    'type' => 'ambient',
                    'station_id' => 'AM-67890',
                    'api_key' => 'leaked-ambient-key-67890',
                    'application_key' => 'leaked-ambient-app-key',
                ],
                [
                    'type' => 'metar',
                    'station_id' => 'KSBP',
                ],
                [
                    'type' => 'davis_weatherlink_live',
                    'station_id' => 'wx-spb-bridge-davis',
                    'bridge_id' => 'bridge-1',
                    'bridge_source_id' => 'source-1',
                    'api_key' => 'leaked-bridge-key',
                ],
                [
                    'type' => 'dyaconlive',
                    'station_id' => 'DL-001',
                    'username' => 'leaked-dyacon-user',
                    'password' => 'leaked-dyacon-pass',
                ],
            ],
            'webcams' => [
                [
                    'name' => 'Terminal View',
                    'type' => 'push',
                    'refresh_seconds' => 60,
                    'push_config' => [
                        'username' => 'leaked_cam_user_1',
                        'password' => 'leaked_cam_pass_1',
                        'port' => 2222,
                        'max_file_size_mb' => 50,
                        'allowed_extensions' => ['jpg', 'webp'],
                    ],
                    'crop_margins' => ['top' => 0, 'bottom' => 0, 'left' => 0, 'right' => 0],
                ],
                [
                    'name' => 'Ramp Cam',
                    'type' => 'pull',
                    'url' => 'https://example.com/cam.jpg',
                    'rtsp' => 'rtsp://example.com/stream',
                    'mjpeg' => 'http://example.com/mjpeg',
                    'refresh_seconds' => 30,
                    'push_config' => [
                        'username' => 'leaked_cam_user_2',
                        'password' => 'leaked_cam_pass_2',
                    ],
                ],
                [
                    'name' => 'Disabled Camera',
                    'enabled' => false,
                    'type' => 'push',
                    'push_config' => [
                        'username' => 'leaked_cam_user_3',
                        'password' => 'leaked_cam_pass_3',
                    ],
                ],
            ],
            'runways' => [
                [
                    'name' => '14/32',
                    'heading_1' => 140,
                    'heading_2' => 320,
                ],
            ],
            'links' => [
                ['label' => 'Airport Website', 'url' => 'https://example.com'],
            ],
            'services' => ['fuel' => true, 'fbo' => true],
            'partners' => [
                ['name' => 'Example Partner', 'url' => 'https://example.com', 'logo' => '/local.png'],
            ],
            'bridges' => [
                [
                    'id' => 'bridge-1',
                    'label' => 'Camera Bridge',
                    'api_key' => 'leaked_bridge_api_key_abc123',
                ],
            ],
            'config' => [
                'openweathermap_api_key' => 'leaked_owm_key',
            ],
        ];
    }

    /**
     * Recursively check that no credential-shaped value appears in the output.
     */
    private function assertNoCredentialValues(string $path, mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $this->assertNoCredentialValues($path . '.' . $k, $v);
            }
            return;
        }

        if (is_string($value)) {
            $sensitiveValues = [
                'leaked-tempest-key-12345',
                'leaked-ambient-key-67890',
                'leaked-ambient-app-key',
                'leaked-bridge-key',
                'leaked-dyacon-user',
                'leaked-dyacon-pass',
                'leaked_cam_user_1',
                'leaked_cam_pass_1',
                'leaked_cam_user_2',
                'leaked_cam_pass_2',
                'leaked_cam_user_3',
                'leaked_cam_pass_3',
                'leaked_bridge_api_key_abc123',
                'leaked_owm_key',
            ];
            foreach ($sensitiveValues as $secret) {
                $this->assertStringNotContainsString(
                    $secret,
                    $value,
                    "Credential value leaked in getAirportPageConfig output at {$path}"
                );
            }
        }
    }

    public function testGetAirportPageConfig_WithCredentials_StripsAllSecrets(): void
    {
        $airport = $this->fullAirportWithCreds();
        $result = getAirportPageConfig($airport);

        // Recursively scan for any leaked credential values.
        $this->assertNoCredentialValues('', $result);

        // Verify specific credential field names are absent.
        $json = json_encode($result);
        $forbiddenFieldNames = [
            'push_config',
            'api_key',
            'application_key',
            'client_secret',
            'bridge_id',
            'username',
            'password',
            'rtsp',
            'mjpeg',
            'base_url',
            'crop_margins',
        ];
        foreach ($forbiddenFieldNames as $field) {
            $this->assertStringNotContainsString(
                $field,
                $json,
                "Credential field name '{$field}' must not appear in output"
            );
        }
    }

    public function testGetAirportPageConfig_RetainsBrowserSafeFields(): void
    {
        $airport = $this->fullAirportWithCreds();
        $result = getAirportPageConfig($airport);

        // Airport metadata
        $this->assertSame('kspb', $result['id']);
        $this->assertSame('San Luis County Regional Airport', $result['name']);
        $this->assertSame('KSZP', $result['icao']);
        $this->assertSame('SLO', $result['iata']);
        $this->assertSame('SLO', $result['faa']);
        $this->assertSame('US', $result['iso_country']);
        $this->assertSame(35.2368, $result['lat']);
        $this->assertSame(-120.6425, $result['lon']);
        $this->assertSame(170, $result['elevation_ft']);
        $this->assertSame('America/Los_Angeles', $result['timezone']);
        $this->assertSame(60, $result['weather_refresh_seconds']);
        $this->assertSame(60, $result['webcam_refresh_seconds']);
        $this->assertFalse($result['maintenance']);
        $this->assertFalse($result['limited_availability']);
        $this->assertSame('public', $result['access_type']);
        $this->assertFalse($result['permission_required']);
        $this->assertSame('towered', $result['tower_status']);
        $this->assertSame('100 Aviation Drive, San Luis Obispo, CA 93401', $result['address']);

        // Runways
        $this->assertCount(1, $result['runways']);
        $this->assertSame('14/32', $result['runways'][0]['name']);
        $this->assertSame(140, $result['runways'][0]['heading_1']);
        $this->assertSame(320, $result['runways'][0]['heading_2']);

        // Links
        $this->assertCount(1, $result['links']);
        $this->assertSame('Airport Website', $result['links'][0]['label']);
        $this->assertSame('https://example.com', $result['links'][0]['url']);
    }

    public function testGetAirportPageConfig_WeatherSourcesIncludesOnlyTypeAndMetarStationId(): void
    {
        $airport = $this->fullAirportWithCreds();
        $result = getAirportPageConfig($airport);

        $this->assertCount(5, $result['weather_sources']);

        // Tempest: type only, no station_id (provider-internal ID)
        $this->assertSame('tempest', $result['weather_sources'][0]['type']);
        $this->assertArrayNotHasKey('station_id', $result['weather_sources'][0]);
        $this->assertArrayNotHasKey('api_key', $result['weather_sources'][0]);
        $this->assertArrayNotHasKey('bridge_source_id', $result['weather_sources'][0]);

        // Ambient: type only
        $this->assertSame('ambient', $result['weather_sources'][1]['type']);
        $this->assertArrayNotHasKey('station_id', $result['weather_sources'][1]);
        $this->assertArrayNotHasKey('api_key', $result['weather_sources'][1]);
        $this->assertArrayNotHasKey('application_key', $result['weather_sources'][1]);

        // METAR: type + station_id (read by dashboard JS at airport-dashboard.js:1997-1998)
        $this->assertSame('metar', $result['weather_sources'][2]['type']);
        $this->assertSame('KSBP', $result['weather_sources'][2]['station_id']);

        // Davis bridge: only type, no bridge_id/station_id
        $this->assertSame('davis_weatherlink_live', $result['weather_sources'][3]['type']);
        $this->assertArrayNotHasKey('bridge_id', $result['weather_sources'][3]);
        $this->assertArrayNotHasKey('bridge_source_id', $result['weather_sources'][3]);
        $this->assertArrayNotHasKey('station_id', $result['weather_sources'][3]);
        $this->assertArrayNotHasKey('api_key', $result['weather_sources'][3]);

        // Dyacon: type only, no credentials
        $this->assertSame('dyaconlive', $result['weather_sources'][4]['type']);
        $this->assertArrayNotHasKey('station_id', $result['weather_sources'][4]);
        $this->assertArrayNotHasKey('username', $result['weather_sources'][4]);
        $this->assertArrayNotHasKey('password', $result['weather_sources'][4]);
    }

    public function testGetAirportPageConfig_WebcamsStrippedToSafeFields(): void
    {
        $airport = $this->fullAirportWithCreds();
        $result = getAirportPageConfig($airport);

        $this->assertCount(3, $result['webcams']);

        // Push camera: only name, refresh_seconds, enabled
        $this->assertSame('Terminal View', $result['webcams'][0]['name']);
        $this->assertSame(60, $result['webcams'][0]['refresh_seconds']);
        $this->assertTrue($result['webcams'][0]['enabled']);
        $this->assertArrayNotHasKey('push_config', $result['webcams'][0]);
        $this->assertArrayNotHasKey('type', $result['webcams'][0]);
        $this->assertArrayNotHasKey('url', $result['webcams'][0]);
        $this->assertArrayNotHasKey('rtsp', $result['webcams'][0]);
        $this->assertArrayNotHasKey('mjpeg', $result['webcams'][0]);
        $this->assertArrayNotHasKey('crop_margins', $result['webcams'][0]);

        // Pull camera: URL must not leak
        $this->assertSame('Ramp Cam', $result['webcams'][1]['name']);
        $this->assertArrayNotHasKey('url', $result['webcams'][1]);
        $this->assertArrayNotHasKey('push_config', $result['webcams'][1]);

        // Disabled camera: enabled flag is false
        $this->assertSame('Disabled Camera', $result['webcams'][2]['name']);
        $this->assertFalse($result['webcams'][2]['enabled']);
    }

    public function testGetAirportPageConfig_EmptyWebcamsAndWeatherSources_ReturnsEmptyArrays(): void
    {
        $airport = ['name' => 'No Sources', 'timezone' => 'UTC'];
        $result = getAirportPageConfig($airport);

        $this->assertSame([], $result['weather_sources']);
        $this->assertSame([], $result['webcams']);
    }

    public function testGetAirportPageConfig_NoCredentialFields_OmitsConfigAndBridgesKeys(): void
    {
        $airport = $this->fullAirportWithCreds();
        $result = getAirportPageConfig($airport);

        // The top-level 'config' key (containing openweathermap_api_key)
        // and 'bridges' (containing api_key) must never appear in output.
        $this->assertArrayNotHasKey('config', $result);
        $this->assertArrayNotHasKey('bridges', $result);
    }

    public function testGetAirportPageConfig_JsonEncodedOutput_HasNoCredentialPatterns(): void
    {
        $airport = $this->fullAirportWithCreds();
        $result = getAirportPageConfig($airport);

        $json = json_encode($result, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        $forbiddenPatterns = [
            'push_config',
            'api_key',
            'application_key',
            'client_secret',
            'bridge_id',
            'username',
            'password',
            'leaked_',
            'rtsp://',
            'mjpeg',
        ];
        foreach ($forbiddenPatterns as $pattern) {
            $this->assertStringNotContainsString(
                $pattern,
                $json,
                "Credential pattern '{$pattern}' found in JSON-encoded airport page config"
            );
        }
    }

     /**
      * Fixture-based regression: the test fixture (tests/Fixtures/airports.json.test)
      * contains sentinel credential values. Assert that getAirportPageConfig()
      * never passes them through to the serialized output.
      */
     public function testGetAirportPageConfig_WithTestFixture_NoCredentialValues(): void
     {
         $configPath = __DIR__ . '/../Fixtures/airports.json.test';
         $config = json_decode((string) file_get_contents($configPath), true);

         $this->assertIsArray($config, 'Test fixture should be valid JSON');
         $this->assertArrayHasKey('airports', $config);

         $airport = getAirportPageConfig($config['airports']['kspb']);
         $html = json_encode($airport, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

         // Sentinel credential values from the test fixture must never reach
         // the browser via AIRPORT_DATA.
         $this->assertStringNotContainsString('test_api_key_12345', $html, 'Tempest API key must not appear in output');
         $this->assertStringNotContainsString('test_ambient_api_key', $html, 'Ambient API key must not appear in output');
         $this->assertStringNotContainsString('awxb_', $html, 'Bridge API key must not appear in output');
         $this->assertStringNotContainsString('test_owm_sentinel_key', $html, 'OWM API key must not appear in output');
         $this->assertStringNotContainsString('bridge_source_id', $html, 'bridge_source_id must not appear in output');
         $this->assertStringNotContainsString('bridge_id', $html, 'bridge_id must not appear in output');

         // Verify the airport page config includes only safe fields.
         $decoded = json_decode($html, true);
         $this->assertArrayNotHasKey('bridges', $decoded);
         $this->assertArrayNotHasKey('config', $decoded);
         $this->assertArrayNotHasKey('push_config', $decoded['webcams'][0] ?? []);
     }

    /**
     * CI-enforced regression: the airport page template must call
     * getAirportPageConfig() and must not serialize the raw $airport config.
     * This catches regressions even when no live server is available.
     */
    public function testAirportPagePhp_SourceUsesAllowlistNotRawAirport(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../pages/airport.php');
        $this->assertNotFalse($source, 'pages/airport.php must be readable');

        $this->assertStringContainsString('getAirportPageConfig', $source, 'pages/airport.php must call getAirportPageConfig()');
        $this->assertStringNotContainsString(
            'json_encode($airport,',
            $source,
            'pages/airport.php must not serialize the raw $airport config'
        );
    }

     /**
      * CI-enforced regression: the airports directory page must not embed
      * the OpenWeatherMap API key — or any credential value — in client-side
      * JavaScript. Checks both the former variable name and any json_encode
      * of the config credential, so a rename would still be caught.
      */
     public function testAirportsPagePhp_SourceDoesNotEmbedApiKey(): void
     {
         $source = (string) file_get_contents(__DIR__ . '/../../pages/airports.php');
         $this->assertNotFalse($source, 'pages/airports.php must be readable');

         // The former variable name and its json_encode call must not exist.
         $this->assertStringNotContainsString(
             'var openWeatherMapApiKey',
             $source,
             'pages/airports.php must not declare openWeatherMapApiKey in client-side JS'
         );
         $this->assertStringNotContainsString(
             'json_encode($openWeatherMapApiKey)',
             $source,
             'pages/airports.php must not json_encode the OWM key into JS'
         );

         // Guard against a rename: no config credential field should be
         // passed to the browser via json_encode.
         $this->assertStringNotContainsString(
             'json_encode($config[\'config\']',
             $source,
             'pages/airports.php must not json_encode config credentials into JS'
         );
     }
}
