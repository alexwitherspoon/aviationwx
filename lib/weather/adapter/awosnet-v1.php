<?php
/**
 * AWOSnet Adapter v1
 *
 * Fetches weather from AWOSnet XML endpoint (awiAwosNet.php).
 * Parses structured XML and uses METAR as fallback. Invalid values
 * (///, \\, ###, ***) are normalized to null.
 *
 * Configuration:
 * - station_id: AWOSnet station ID (e.g., "ks40" for ks40.awosnet.com) - REQUIRED
 *
 * @package AviationWX\Weather\Adapter
 */

require_once __DIR__ . '/../../constants.php';
require_once __DIR__ . '/../../logger.php';
require_once __DIR__ . '/../../test-mocks.php';
require_once __DIR__ . '/../data/WeatherReading.php';
require_once __DIR__ . '/../data/WindGroup.php';
require_once __DIR__ . '/../data/WeatherSnapshot.php';
require_once __DIR__ . '/../calculator.php';
require_once __DIR__ . '/metar-v1.php';

use AviationWX\Weather\Data\WeatherReading;
use AviationWX\Weather\Data\WindGroup;
use AviationWX\Weather\Data\WeatherSnapshot;

class AwosnetAdapter
{
    /** AWOSnet invalid value markers: /// = not available, \\ = expired, ### = sensor inactive, *** = not installed */
    private const INVALID_MARKERS = ['///', '\\\\', '###', '***'];
    public const FIELDS_PROVIDED = [
        'temperature',
        'dewpoint',
        'humidity',
        'pressure',
        'wind_speed',
        'wind_direction',
        'gust_speed',
        'visibility',
        'ceiling',
        'cloud_cover',
        'precip_accum',
    ];

    public const UPDATE_FREQUENCY = 600;  // 10 minutes typical for AWOS

    public const MAX_ACCEPTABLE_AGE = 1800;  // 30 minutes

    public const SOURCE_TYPE = 'awosnet';

    public static function getFieldsProvided(): array
    {
        return self::FIELDS_PROVIDED;
    }

    public static function getTypicalUpdateFrequency(): int
    {
        return self::UPDATE_FREQUENCY;
    }

    public static function getMaxAcceptableAge(): int
    {
        return self::MAX_ACCEPTABLE_AGE;
    }

    public static function getSourceType(): string
    {
        return self::SOURCE_TYPE;
    }

    public static function providesField(string $fieldName): bool
    {
        return in_array($fieldName, self::FIELDS_PROVIDED, true);
    }

    /**
     * Build the fetch URL for an AWOSnet station
     *
     * Fetches awiAwosNet.php - the data endpoint (JS loads from here).
     * Root HTML is static; real data comes from this PHP script.
     *
     * @param array $config Source config with station_id (e.g., "ks40")
     * @return string|null URL or null if invalid
     */
    public static function buildUrl(array $config): ?string
    {
        $stationId = $config['station_id'] ?? '';
        if (empty($stationId) || !is_string($stationId)) {
            return null;
        }
        $stationId = strtolower(trim($stationId));
        // Validate: lowercase alphanumeric, 2-20 chars to avoid injection
        if (!preg_match('/^[a-z0-9]{2,20}$/', $stationId)) {
            if (function_exists('aviationwx_log')) {
                aviationwx_log('warning', 'AWOSnet invalid station_id format', [
                    'station_id' => $stationId,
                    'expected' => 'lowercase alphanumeric, 2-20 chars',
                ]);
            }
            return null;
        }
        return "http://{$stationId}.awosnet.com/awiAwosNet.php";
    }

    /**
     * Get HTTP headers for AWOSnet request
     *
     * Server may require Referer to return data (JS loads from same origin).
     *
     * @param array $config Source config with station_id
     * @return array HTTP headers
     */
    public static function getHeaders(array $config): array
    {
        $stationId = $config['station_id'] ?? '';
        $stationId = strtolower(trim($stationId));
        if (!preg_match('/^[a-z0-9]{2,20}$/', $stationId)) {
            return ['Accept: */*'];
        }
        $baseUrl = "http://{$stationId}.awosnet.com/";
        return [
            'Accept: */*',
            'Referer: ' . $baseUrl,
        ];
    }

    /**
     * Check if an AWOSnet value is valid (not ///, \\, ###, ***, or empty)
     */
    private static function isValueValid(?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return false;
        }
        return !in_array($trimmed, self::INVALID_MARKERS, true)
            && !preg_match('/^[\/\\\\*#\s]+$/', $trimmed);
    }

    /**
     * Extract numeric value from XML element, or null if invalid
     */
    private static function getNumericAttr(?\SimpleXMLElement $el): ?float
    {
        if ($el === null) {
            return null;
        }
        $val = (string)$el['value'];
        if (!self::isValueValid($val) || !is_numeric($val)) {
            return null;
        }
        return (float)$val;
    }

    /**
     * Extract timestamp from XML element's time attribute
     */
    private static function getTimeAttr(?\SimpleXMLElement $el): ?int
    {
        if ($el === null || !isset($el['time'])) {
            return null;
        }
        $t = (int)(string)$el['time'];
        return ($t > 0) ? $t : null;
    }

    /**
     * Extract METAR string from response (HTML or XML)
     */
    public static function extractMetarFromHtml(string $html): ?string
    {
        $text = preg_replace('/\s+/', ' ', $html);
        if (preg_match('/\b(METAR\s+[A-Z0-9]+\s+\d{6}Z\s+[A-Z0-9\/\s\.\-SM]+?)(?:\s+RMK\b|$)/', $text, $m)) {
            $metar = trim($m[1]);
            if (strlen($metar) > 15 && !str_contains($metar, '///')) {
                return $metar;
            }
        }
        if (preg_match('/\b(METAR\s+[A-Z0-9]+\s+\d{6}Z\s+[A-Z0-9\/\s\.\-SM]+)/', $text, $m)) {
            $metar = trim($m[1]);
            if (strlen($metar) > 15 && !str_contains($metar, '///')) {
                return $metar;
            }
        }
        return null;
    }

    /**
     * Parse AWOSnet XML into weather array, merging with METAR fallback
     *
     * @param string $response Raw XML/HTML response
     * @return array|null Merged weather data or null if no usable data
     */
    public static function parseXmlToWeatherArray(string $response): ?array
    {
        $metar = self::extractMetarFromHtml($response);
        $metarParsed = $metar ? parseRawMETARToWeatherArray($metar) : null;

        $prevLibxml = libxml_use_internal_errors(true);
        $xml = @simplexml_load_string($response);
        libxml_clear_errors();
        libxml_use_internal_errors($prevLibxml);

        if ($xml === false) {
            return $metarParsed;
        }

        $result = $metarParsed ?? [
            'temperature' => null,
            'dewpoint' => null,
            'humidity' => null,
            'wind_direction' => null,
            'wind_speed' => null,
            'gust_speed' => null,
            'pressure' => null,
            'visibility' => null,
            'visibility_greater_than' => false,
            'ceiling' => null,
            'cloud_cover' => null,
            'precip_accum' => 0,
            'obs_time' => null,
        ];

        // Prefer METAR-derived obs_time when available - METAR is in Zulu (UTC) and authoritative.
        // AWOSnet XML time attribute may be in local time or from misconfigured hardware clock.
        $obsTime = $result['obs_time']
            ?? self::getTimeAttr($xml->METAR ?? null)
            ?? self::getTimeAttr($xml->airTemperature ?? null)
            ?? time();

        $result['obs_time'] = $obsTime;

        $tempVal = self::getNumericAttr($xml->airTemperature ?? null);
        if ($tempVal !== null) {
            $result['temperature'] = (int)round($tempVal);
        }
        $dewVal = self::getNumericAttr($xml->dewPoint ?? null);
        if ($dewVal !== null) {
            $result['dewpoint'] = (int)round($dewVal);
        }
        $rhVal = self::getNumericAttr($xml->relativeHumidity ?? null);
        if ($rhVal !== null) {
            $result['humidity'] = (int)round($rhVal);
        }
        $pressVal = self::getNumericAttr($xml->altimeterSetting ?? null);
        if ($pressVal !== null) {
            $result['pressure'] = $pressVal;
        }
        $windDirVal = self::getNumericAttr($xml->twoMinutewindDirection ?? null);
        if ($windDirVal !== null) {
            $result['wind_direction'] = (int)round($windDirVal);
        }
        $windSpdVal = self::getNumericAttr($xml->twoMinutewindSpeed ?? null);
        if ($windSpdVal !== null) {
            $result['wind_speed'] = (int)round($windSpdVal);
        }
        $gustVal = self::getNumericAttr($xml->windGust ?? null);
        if ($gustVal !== null) {
            $result['gust_speed'] = (int)round($gustVal);
        }
        $visVal = self::getNumericAttr($xml->tenMinutevisibility ?? null);
        if ($visVal !== null) {
            $result['visibility'] = $visVal;
            $result['visibility_greater_than'] = false;  // XML value is exact, not METAR P-prefix
        }
        $ceilVal = self::getNumericAttr($xml->ceilometer ?? null);
        if ($ceilVal !== null) {
            $result['ceiling'] = (int)round($ceilVal);
        }
        $rainVal = self::getNumericAttr($xml->rainfallCurrentHour ?? null);
        if ($rainVal !== null && $rainVal >= 0) {
            $result['precip_accum'] = $rainVal;
        }

        if ($result['humidity'] === null && $result['temperature'] !== null && $result['dewpoint'] !== null
            && function_exists('calculateHumidityFromDewpoint')) {
            $result['humidity'] = calculateHumidityFromDewpoint($result['temperature'], $result['dewpoint']);
        }

        if ($metarParsed === null && $result['temperature'] === null && $result['wind_speed'] === null) {
            return null;
        }

        return $result;
    }

    /**
     * Parse AWOSnet response into WeatherSnapshot
     *
     * Parses XML when available, falls back to METAR. Merges valid XML fields.
     *
     * @param string $response Raw XML/HTML response
     * @param array $config Source configuration
     * @return WeatherSnapshot|null
     */
    public static function parseToSnapshot(string $response, array $config = []): ?WeatherSnapshot
    {
        $parsed = self::parseXmlToWeatherArray($response);
        if ($parsed === null) {
            return WeatherSnapshot::empty(self::SOURCE_TYPE);
        }

        $obsTime = $parsed['obs_time'] ?? time();
        $source = self::SOURCE_TYPE;

        $windDirection = $parsed['wind_direction'];
        $windSpeed = $parsed['wind_speed'];
        $gustSpeed = $parsed['gust_speed'] ?? null;
        if ($windDirection === 'VRB') {
            $windDirection = null;
        }
        $hasCompleteWind = $windSpeed !== null && $windDirection !== null;

        $metar = self::extractMetarFromHtml($response);

        return new WeatherSnapshot(
            source: $source,
            fetchTime: time(),
            temperature: WeatherReading::celsius($parsed['temperature'], $source, $obsTime),
            dewpoint: WeatherReading::celsius($parsed['dewpoint'], $source, $obsTime),
            humidity: WeatherReading::percent($parsed['humidity'], $source, $obsTime),
            pressure: WeatherReading::inHg($parsed['pressure'], $source, $obsTime),
            precipAccum: WeatherReading::inches($parsed['precip_accum'] ?? 0, $source, $obsTime),
            wind: $hasCompleteWind
                ? WindGroup::from($windSpeed, $windDirection, $gustSpeed, $source, $obsTime)
                : WindGroup::empty(),
            visibility: WeatherReading::statuteMiles(
                $parsed['visibility'],
                $source,
                $obsTime,
                $parsed['visibility_greater_than'] ?? false
            ),
            ceiling: WeatherReading::feet($parsed['ceiling'], $source, $obsTime),
            cloudCover: WeatherReading::text($parsed['cloud_cover'] ?? null, $source, $obsTime),
            rawMetar: $metar,
            isValid: true
        );
    }
}

/**
 * Parse AWOSnet response into standard weather array
 *
 * Parses XML when available, merges with METAR fallback. Used by tests.
 *
 * @param string $response Raw XML/HTML response
 * @param array $config Unused (for signature compatibility)
 * @return array|null Weather data array or null
 */
function parseAwosnetResponse(string $response, array $config = []): ?array
{
    return AwosnetAdapter::parseXmlToWeatherArray($response);
}
