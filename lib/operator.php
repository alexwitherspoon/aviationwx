<?php
/**
 * Operator identity for weathercams and weather sources.
 *
 * Matching uses airports.json only. Omitted weathercam operator is aviationwx.
 * Omitted weather-source operator uses a type default (metar is faa, nws is nws).
 * Explicit slugs name other networks.
 */

require_once __DIR__ . '/constants.php';

/**
 * Whether a string is a valid operator slug.
 *
 * @param string $operator Candidate operator slug
 * @return bool True when the slug is valid
 */
function isValidOperatorSlug(string $operator): bool
{
    $length = strlen($operator);
    if ($length < 1 || $length > OPERATOR_MAX_LENGTH) {
        return false;
    }

    return (bool) preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $operator);
}

/**
 * Read an explicit operator slug from a config row, if present.
 *
 * Whitespace-only values are treated as omitted. Non-string values are invalid
 * and must not fall through to a type default (wrong network).
 *
 * @param array $row Weathercam or weather source configuration
 * @return string|null Lowercase slug, empty string when invalid, or null when omitted
 */
function resolveExplicitOperator(array $row): ?string
{
    if (!array_key_exists('operator', $row) || $row['operator'] === null) {
        return null;
    }

    if (!is_string($row['operator'])) {
        return '';
    }

    $operator = strtolower(trim($row['operator']));
    if ($operator === '') {
        return null;
    }

    return $operator;
}

/**
 * Default operator for a weather source type when operator is omitted.
 *
 * @param string $type weather_sources[].type
 * @return string Operator slug
 */
function defaultOperatorForWeatherSourceType(string $type): string
{
    switch ($type) {
        case 'metar':
            return 'faa';
        case 'nws':
            return 'nws';
        case 'swob_auto':
        case 'swob_man':
            return 'navcanada';
        case 'awosnet':
            return 'awosnet';
        case 'synopticdata':
            return 'synopticdata';
        default:
            return DEFAULT_OPERATOR;
    }
}

/**
 * Resolve the operator slug for a weathercam config row.
 *
 * @param array $webcam Weathercam configuration
 * @return string Operator slug
 */
function getWeathercamOperator(array $webcam): string
{
    $explicit = resolveExplicitOperator($webcam);
    if ($explicit === null) {
        return DEFAULT_OPERATOR;
    }

    return $explicit;
}

/**
 * Resolve the operator slug for a weather source config row.
 *
 * @param array $source weather_sources[] row
 * @return string Operator slug
 */
function getWeatherSourceOperator(array $source): string
{
    $explicit = resolveExplicitOperator($source);
    if ($explicit === null) {
        $type = isset($source['type']) && is_string($source['type']) ? $source['type'] : '';
        return defaultOperatorForWeatherSourceType($type);
    }

    return $explicit;
}

/**
 * Whether a weathercam matches an optional operator filter.
 *
 * @param array $webcam Weathercam configuration
 * @param string|null $operatorFilter Lowercase slug, or null for no filter
 * @return bool True when the camera should be included
 */
function weathercamMatchesOperator(array $webcam, ?string $operatorFilter): bool
{
    if ($operatorFilter === null) {
        return true;
    }

    return getWeathercamOperator($webcam) === $operatorFilter;
}

/**
 * Whether a weather source matches an optional operator filter.
 *
 * @param array $source weather_sources[] row
 * @param string|null $operatorFilter Lowercase slug, or null for no filter
 * @return bool True when the source should be counted
 */
function weatherSourceMatchesOperator(array $source, ?string $operatorFilter): bool
{
    if ($operatorFilter === null) {
        return true;
    }

    return getWeatherSourceOperator($source) === $operatorFilter;
}

/**
 * Count weathercams on an airport that match an optional operator filter.
 *
 * @param array $airport Airport configuration
 * @param string|null $operatorFilter Lowercase slug, or null for no filter
 * @return int Matching weathercam count
 */
function countWeathercamsForOperator(array $airport, ?string $operatorFilter): int
{
    $webcams = $airport['webcams'] ?? [];
    if (!is_array($webcams)) {
        return 0;
    }

    $count = 0;
    foreach ($webcams as $webcam) {
        if (is_array($webcam) && weathercamMatchesOperator($webcam, $operatorFilter)) {
            $count++;
        }
    }

    return $count;
}

/**
 * True when any configured weather source matches an optional operator filter.
 *
 * Uses airports.json rows only, not the live fused observation.
 *
 * @param array $airport Airport configuration
 * @param string|null $operatorFilter Lowercase slug, or null for no filter
 * @return bool True when a matching weather source is configured
 */
function airportHasWeatherSourceOperator(array $airport, ?string $operatorFilter): bool
{
    $sources = $airport['weather_sources'] ?? [];
    if (!is_array($sources)) {
        return false;
    }

    foreach ($sources as $source) {
        if (is_array($source) && !empty($source['type']) && weatherSourceMatchesOperator($source, $operatorFilter)) {
            return true;
        }
    }

    return false;
}

/**
 * True when the airport has a matching weathercam or weather source in config.
 *
 * @param array $airport Airport configuration
 * @param string|null $operatorFilter Lowercase slug, or null for no filter
 * @return bool True when either resource matches, or when the filter is omitted
 */
function airportMatchesOperator(array $airport, ?string $operatorFilter): bool
{
    if ($operatorFilter === null) {
        return true;
    }

    return countWeathercamsForOperator($airport, $operatorFilter) > 0
        || airportHasWeatherSourceOperator($airport, $operatorFilter);
}

/**
 * Parse an operator query value.
 *
 * @param string|null $raw Raw query value, or null when the parameter is omitted
 * @return array{ok: bool, value: string|null, error: string|null}
 */
function parseOperatorQueryParam(?string $raw): array
{
    if ($raw === null) {
        return ['ok' => true, 'value' => null, 'error' => null];
    }

    $operator = strtolower(trim($raw));
    if ($operator === '' || !isValidOperatorSlug($operator)) {
        return [
            'ok' => false,
            'value' => null,
            'error' => 'operator must be a lowercase slug (letters, digits, hyphens)',
        ];
    }

    return ['ok' => true, 'value' => $operator, 'error' => null];
}

/**
 * Parse operator from the current request query string.
 *
 * @return array{ok: bool, value: string|null, error: string|null}
 */
function parseOperatorQueryFromGet(): array
{
    if (!array_key_exists('operator', $_GET)) {
        return ['ok' => true, 'value' => null, 'error' => null];
    }

    $raw = $_GET['operator'];
    if (is_array($raw)) {
        return [
            'ok' => false,
            'value' => null,
            'error' => 'operator must be a lowercase slug (letters, digits, hyphens)',
        ];
    }

    return parseOperatorQueryParam((string) $raw);
}

/**
 * Validate optional operator on a config row.
 *
 * Config values must already be lowercase; query parsing lowercases first.
 *
 * @param array $row Weathercam or weather source configuration
 * @param string $label Error-message prefix
 * @return array Validation error strings
 */
function validateConfigOperator(array $row, string $label): array
{
    if (!array_key_exists('operator', $row)) {
        return [];
    }

    $operator = $row['operator'];
    if (!is_string($operator)) {
        return ["{$label} has invalid operator: must be a lowercase slug (letters, digits, hyphens)"];
    }

    $trimmed = trim($operator);
    if ($trimmed === '' || $trimmed !== $operator || !isValidOperatorSlug($trimmed)) {
        return ["{$label} has invalid operator: must be a lowercase slug (letters, digits, hyphens)"];
    }

    return [];
}

/**
 * Validate optional operator on a weathercam config row.
 *
 * @param array $webcam Weathercam configuration
 * @param string $airportCode Airport identifier for error messages
 * @param int $idx Weathercam index for error messages
 * @return array Validation error strings
 */
function validateWeathercamOperator(array $webcam, string $airportCode, int $idx): array
{
    return validateConfigOperator($webcam, "Airport '{$airportCode}' webcam[{$idx}]");
}
