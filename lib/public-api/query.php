<?php
/**
 * Public API query parsing helpers.
 */

require_once dirname(__DIR__) . '/webcam-format-generation.php';

/**
 * Read one scalar query value without coercing arrays.
 *
 * @param string $name Query parameter name
 * @return array{ok: true, present: bool, value: ?string, error: null}|array{ok: false, present: true, value: null, error: string}
 */
function parsePublicApiScalarQueryFromGet(string $name): array
{
    if (!array_key_exists($name, $_GET)) {
        return ['ok' => true, 'present' => false, 'value' => null, 'error' => null];
    }

    $raw = $_GET[$name];
    if (is_array($raw)) {
        return [
            'ok' => false,
            'present' => true,
            'value' => null,
            'error' => "{$name} must be a single value",
        ];
    }

    return ['ok' => true, 'present' => true, 'value' => (string) $raw, 'error' => null];
}

/**
 * Parse a canonical positive integer string within inclusive bounds.
 *
 * @param string $raw Raw query value
 * @param int $minimum Minimum accepted value
 * @param int $maximum Maximum accepted value
 * @return int|null Parsed integer, or null when invalid
 */
function parsePublicApiBoundedInteger(string $raw, int $minimum, int $maximum): ?int
{
    if (!preg_match('/^[1-9][0-9]*$/D', $raw)) {
        return null;
    }

    $value = filter_var(
        $raw,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => $minimum, 'max_range' => $maximum]]
    );

    return $value === false ? null : $value;
}

/**
 * Parse fmt against enabled webcam generation formats.
 *
 * Omit fmt for the native original; fmt is only for generated size variants.
 *
 * @param string|null $raw Raw fmt query value
 * @return array{ok: true, explicit: bool, format: ?string, error: null}|array{ok: false, explicit: true, format: ?string, error: string}
 */
function parsePublicApiWebcamFmtQuery(?string $raw): array
{
    if ($raw === null || trim($raw) === '') {
        return ['ok' => true, 'explicit' => false, 'format' => null, 'error' => null];
    }

    $format = normalizeWebcamFormatName($raw);
    if ($format === null) {
        return [
            'ok' => false,
            'explicit' => true,
            'format' => null,
            'error' => 'Unknown image format',
        ];
    }

    if (!in_array($format, getEnabledWebcamFormats(), true)) {
        return [
            'ok' => false,
            'explicit' => true,
            'format' => $format,
            'error' => "Format '{$format}' is not enabled",
        ];
    }

    return ['ok' => true, 'explicit' => true, 'format' => $format, 'error' => null];
}

/**
 * Parse fmt from the current request without coercing array-shaped input.
 *
 * @return array{ok: true, explicit: bool, format: ?string, error: null}|array{ok: false, explicit: true, format: ?string, error: string}
 */
function parsePublicApiWebcamFmtQueryFromGet(): array
{
    $query = parsePublicApiScalarQueryFromGet('fmt');
    if (!$query['ok']) {
        return [
            'ok' => false,
            'explicit' => true,
            'format' => null,
            'error' => $query['error'],
        ];
    }
    if (!$query['present']) {
        return parsePublicApiWebcamFmtQuery(null);
    }

    return parsePublicApiWebcamFmtQuery($query['value']);
}

/**
 * Parse size as the native original or a canonical height.
 *
 * @return array{ok: true, size: string, error: null}|array{ok: false, size: null, error: string}
 */
function parsePublicApiWebcamSizeQueryFromGet(): array
{
    $query = parsePublicApiScalarQueryFromGet('size');
    if (!$query['ok']) {
        return ['ok' => false, 'size' => null, 'error' => $query['error']];
    }
    if (!$query['present']) {
        return ['ok' => true, 'size' => 'original', 'error' => null];
    }

    $raw = $query['value'];
    if ($raw === 'original') {
        return ['ok' => true, 'size' => 'original', 'error' => null];
    }

    $height = parsePublicApiBoundedInteger($raw, 1, 5000);
    if ($height === null) {
        return [
            'ok' => false,
            'size' => null,
            'error' => 'size must be original or an integer from 1 to 5000',
        ];
    }

    return ['ok' => true, 'size' => (string) $height, 'error' => null];
}

/**
 * Parse an optional width or height query parameter.
 *
 * @param string $name width or height
 * @param int $minimum Minimum accepted dimension
 * @param int $maximum Maximum accepted dimension
 * @return array{ok: true, value: ?int, error: null}|array{ok: false, value: null, error: string}
 */
function parsePublicApiWebcamDimensionQueryFromGet(string $name, int $minimum, int $maximum): array
{
    $query = parsePublicApiScalarQueryFromGet($name);
    if (!$query['ok']) {
        return ['ok' => false, 'value' => null, 'error' => $query['error']];
    }
    if (!$query['present']) {
        return ['ok' => true, 'value' => null, 'error' => null];
    }

    $value = parsePublicApiBoundedInteger($query['value'], $minimum, $maximum);
    if ($value === null) {
        return [
            'ok' => false,
            'value' => null,
            'error' => "{$name} must be an integer from {$minimum} to {$maximum}",
        ];
    }

    return ['ok' => true, 'value' => $value, 'error' => null];
}

/**
 * Parse an optional historical frame timestamp.
 *
 * @return array{ok: true, present: bool, timestamp: ?int, error: null}|array{ok: false, present: true, timestamp: null, error: string}
 */
function parsePublicApiWebcamTimestampQueryFromGet(): array
{
    $query = parsePublicApiScalarQueryFromGet('ts');
    if (!$query['ok']) {
        return [
            'ok' => false,
            'present' => true,
            'timestamp' => null,
            'error' => $query['error'],
        ];
    }
    if (!$query['present']) {
        return ['ok' => true, 'present' => false, 'timestamp' => null, 'error' => null];
    }

    $timestamp = parsePublicApiBoundedInteger($query['value'], 1, PHP_INT_MAX);
    if ($timestamp === null) {
        return [
            'ok' => false,
            'present' => true,
            'timestamp' => null,
            'error' => 'ts must be a positive integer timestamp',
        ];
    }

    return ['ok' => true, 'present' => true, 'timestamp' => $timestamp, 'error' => null];
}
