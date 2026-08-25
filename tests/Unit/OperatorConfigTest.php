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
        $this->assertFalse($faa['has_webcams']);
        $this->assertSame(0, $faa['webcam_count']);

        $wsdot = formatAirportSummary('kspb', $airport, 'wsdot');
        $this->assertTrue($wsdot['has_weather']);
        $this->assertTrue($wsdot['has_webcams']);
        $this->assertSame(1, $wsdot['webcam_count']);
    }

    public function testParseOperatorQueryParam_NormalizesAndRejectsInvalidSlug(): void
    {
        $uppercase = parseOperatorQueryParam('WSDOT');
        $this->assertTrue($uppercase['ok']);
        $this->assertSame('wsdot', $uppercase['value']);

        $invalid = parseOperatorQueryParam('not valid');
        $this->assertFalse($invalid['ok']);
        $this->assertNull($invalid['value']);

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
}
