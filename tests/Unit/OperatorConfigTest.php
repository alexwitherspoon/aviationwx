<?php
/**
 * Unit tests for weathercam and weather-source operator defaults and matching.
 */

use PHPUnit\Framework\TestCase;

class OperatorConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../../lib/config.php';
        require_once __DIR__ . '/../../lib/weather/utils.php';
        require_once __DIR__ . '/../../api/v1/airports.php';
    }

    public function testWeathercamOperator_DefaultsToAviationwxWhenOmitted(): void
    {
        $this->assertSame('aviationwx', getWeathercamOperator(['name' => 'North']));
    }

    public function testWeathercamOperator_UsesExplicitSlug(): void
    {
        $this->assertSame('wsdot', getWeathercamOperator(['name' => 'South', 'operator' => 'wsdot']));
    }

    public function testWeatherSourceOperator_TypeDefaults(): void
    {
        $this->assertSame('faa', getWeatherSourceOperator(['type' => 'metar']));
        $this->assertSame('nws', getWeatherSourceOperator(['type' => 'nws']));
        $this->assertSame('navcanada', getWeatherSourceOperator(['type' => 'swob_auto']));
        $this->assertSame('navcanada', getWeatherSourceOperator(['type' => 'swob_man']));
        $this->assertSame('awosnet', getWeatherSourceOperator(['type' => 'awosnet']));
        $this->assertSame('synopticdata', getWeatherSourceOperator(['type' => 'synopticdata']));
        $this->assertSame('aviationwx', getWeatherSourceOperator(['type' => 'tempest']));
        $this->assertSame('aviationwx', getWeatherSourceOperator(['type' => 'davis_weatherlink_live']));
        $this->assertSame('aviationwx', getWeatherSourceOperator(['type' => 'aviationwx_api']));
    }

    public function testWeatherSourceOperator_ExplicitOverridesTypeDefault(): void
    {
        $this->assertSame(
            'airport-owned',
            getWeatherSourceOperator(['type' => 'tempest', 'operator' => 'airport-owned'])
        );
        $this->assertSame('aviationwx', getWeatherSourceOperator(['type' => 'metar', 'operator' => 'aviationwx']));
        $this->assertSame(
            '',
            getWeatherSourceOperator(['type' => 'metar', 'operator' => 1]),
            'Invalid explicit operator must not fall through to the metar type default'
        );
    }

    public function testAirportMatchesOperator_UsesConfiguredSourcesNotLiveFusion(): void
    {
        $airport = [
            'webcams' => [
                ['name' => 'North', 'operator' => 'wsdot'],
            ],
            'weather_sources' => [
                ['type' => 'tempest', 'station_id' => '1', 'api_key' => 'k'],
                ['type' => 'metar', 'station_id' => 'KSPB'],
            ],
        ];

        $this->assertTrue(airportMatchesOperator($airport, 'aviationwx'));
        $this->assertTrue(airportMatchesOperator($airport, 'faa'));
        $this->assertTrue(airportMatchesOperator($airport, 'wsdot'));
        $this->assertFalse(airportMatchesOperator($airport, 'nws'));
        $this->assertTrue(airportMatchesOperator($airport, null));
    }

    public function testFormatAirportSummary_OperatorFilterUsesConfig(): void
    {
        $airport = [
            'name' => 'Test Field',
            'icao' => 'KSPB',
            'lat' => 45.0,
            'lon' => -122.0,
            'timezone' => 'UTC',
            'webcams' => [
                ['name' => 'Ours'],
                ['name' => 'WSDOT', 'operator' => 'wsdot'],
            ],
            'weather_sources' => [
                ['type' => 'tempest', 'station_id' => '1', 'api_key' => 'k'],
                ['type' => 'metar', 'station_id' => 'KSPB'],
            ],
        ];

        $all = formatAirportSummary('kspb', $airport);
        $this->assertTrue($all['has_weather']);
        $this->assertTrue($all['has_webcams']);
        $this->assertSame(2, $all['webcam_count']);

        $aviationwx = formatAirportSummary('kspb', $airport, 'aviationwx');
        $this->assertTrue($aviationwx['has_weather']);
        $this->assertTrue($aviationwx['has_webcams']);
        $this->assertSame(1, $aviationwx['webcam_count']);

        $faa = formatAirportSummary('kspb', $airport, 'faa');
        $this->assertTrue($faa['has_weather']);
        $this->assertTrue($faa['has_webcams'], 'has_webcams is not operator-sliced');
        $this->assertSame(0, $faa['webcam_count']);

        $wsdot = formatAirportSummary('kspb', $airport, 'wsdot');
        $this->assertTrue($wsdot['has_weather']);
        $this->assertTrue($wsdot['has_webcams']);
        $this->assertSame(1, $wsdot['webcam_count']);
    }

    public function testParsePublicApiOptionalBooleanQuery_ReadsTrueFalse(): void
    {
        $originalGet = $_GET;
        try {
            $_GET = [];
            $this->assertNull(parsePublicApiOptionalBooleanQuery('has_webcams'));
            $this->assertNull(parsePublicApiOptionalBooleanQuery('has_weather'));

            $_GET = ['has_webcams' => 'true', 'has_weather' => 'FALSE'];
            $this->assertTrue(parsePublicApiOptionalBooleanQuery('has_webcams'));
            $this->assertFalse(parsePublicApiOptionalBooleanQuery('has_weather'));

            $_GET = ['has_webcams' => '1'];
            $this->assertTrue(parsePublicApiOptionalBooleanQuery('has_webcams'));

            $_GET = ['has_webcams' => 'maybe'];
            $this->assertNull(parsePublicApiOptionalBooleanQuery('has_webcams'));

            $_GET = ['has_webcams' => ['true']];
            $this->assertNull(parsePublicApiOptionalBooleanQuery('has_webcams'));
        } finally {
            $_GET = $originalGet;
        }
    }

    public function testOpenApiHasWebcamsAndHasWeatherQuery_AreAirportListFilters(): void
    {
        $spec = json_decode(
            (string) file_get_contents(__DIR__ . '/../../api/docs/openapi.json'),
            true
        );
        $this->assertIsArray($spec);
        $params = $spec['paths']['/airports']['get']['parameters'] ?? [];
        $names = [];
        foreach ($params as $param) {
            if (isset($param['name'])) {
                $names[] = $param['name'];
            }
        }
        $this->assertContains('has_webcams', $names);
        $this->assertContains('has_weather', $names);

        $webcamParamNames = [];
        foreach ($spec['paths']['/airports/{id}/webcams']['get']['parameters'] ?? [] as $param) {
            if (isset($param['name'])) {
                $webcamParamNames[] = $param['name'];
            }
        }
        $this->assertNotContains('has_webcams', $webcamParamNames);
        $this->assertNotContains('has_weather', $webcamParamNames);
    }

    public function testParseOperatorQueryParam_NormalizesAndRejectsInvalidSlug(): void
    {
        $uppercase = parseOperatorQueryParam('WSDOT');
        $this->assertTrue($uppercase['ok']);
        $this->assertSame('wsdot', $uppercase['value']);

        $invalid = parseOperatorQueryParam('not valid');
        $this->assertFalse($invalid['ok']);
        $this->assertNull($invalid['value']);
        $this->assertStringContainsString((string) OPERATOR_MAX_LENGTH, (string) $invalid['error']);
        $this->assertStringNotContainsString('lowercase', (string) $invalid['error']);

        $overlong = parseOperatorQueryParam(str_repeat('a', OPERATOR_MAX_LENGTH + 1));
        $this->assertFalse($overlong['ok']);
        $this->assertStringContainsString((string) OPERATOR_MAX_LENGTH, (string) $overlong['error']);

        $valid = parseOperatorQueryParam('wsdot');
        $this->assertTrue($valid['ok']);
        $this->assertSame('wsdot', $valid['value']);

        $omitted = parseOperatorQueryParam(null);
        $this->assertTrue($omitted['ok']);
        $this->assertNull($omitted['value']);
    }

    public function testIsValidOperatorSlug_AcceptsHyphenatedAndRejectsInvalidShapes(): void
    {
        $this->assertTrue(isValidOperatorSlug('wsdot'));
        $this->assertTrue(isValidOperatorSlug('airport-owned'));
        $this->assertTrue(isValidOperatorSlug(str_repeat('a', OPERATOR_MAX_LENGTH)));
        $this->assertFalse(isValidOperatorSlug('WSDOT'));
        $this->assertFalse(isValidOperatorSlug('not valid'));
        $this->assertFalse(isValidOperatorSlug('-wsdot'));
        $this->assertFalse(isValidOperatorSlug('wsdot-'));
        $this->assertFalse(isValidOperatorSlug('ws--dot'));
        $this->assertFalse(isValidOperatorSlug(str_repeat('a', OPERATOR_MAX_LENGTH + 1)));
    }

    public function testParseOperatorQueryFromGet_ReadsQueryString(): void
    {
        $originalGet = $_GET;
        try {
            $_GET = [];
            $omitted = parseOperatorQueryFromGet();
            $this->assertTrue($omitted['ok']);
            $this->assertNull($omitted['value']);

            $_GET = ['operator' => 'WSDOT'];
            $mixed = parseOperatorQueryFromGet();
            $this->assertTrue($mixed['ok']);
            $this->assertSame('wsdot', $mixed['value']);

            $_GET = ['operator' => 'not valid'];
            $invalid = parseOperatorQueryFromGet();
            $this->assertFalse($invalid['ok']);

            $_GET = ['operator' => ['wsdot']];
            $array = parseOperatorQueryFromGet();
            $this->assertFalse($array['ok']);
            $this->assertStringContainsString((string) OPERATOR_MAX_LENGTH, (string) $array['error']);
        } finally {
            $_GET = $originalGet;
        }
    }

    public function testOpenApiOperatorQueryPattern_AcceptsMixedCase(): void
    {
        $spec = json_decode(
            (string) file_get_contents(__DIR__ . '/../../api/docs/openapi.json'),
            true
        );
        $this->assertIsArray($spec);
        $pattern = $spec['components']['parameters']['Operator']['schema']['pattern'] ?? '';
        $this->assertNotSame('', $pattern);
        $this->assertSame(1, preg_match('/' . $pattern . '/', 'WSDOT'));
        $this->assertSame(1, preg_match('/' . $pattern . '/', 'wsdot'));
        $this->assertSame(0, preg_match('/' . $pattern . '/', 'not valid'));

        $description = $spec['components']['parameters']['Operator']['description'] ?? '';
        $this->assertStringContainsString('aviationwx', $description);
        $this->assertStringContainsString('faa', $description);
        $this->assertStringContainsString('nws', $description);
        $this->assertStringContainsString('navcanada', $description);
        $this->assertStringContainsString('awosnet', $description);
        $this->assertStringContainsString('synopticdata', $description);
    }
}
