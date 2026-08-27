<?php
/**
 * Public API query parsing helpers.
 */

require_once dirname(__DIR__) . '/webcam-format-generation.php';

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
    if (!array_key_exists('fmt', $_GET)) {
        return parsePublicApiWebcamFmtQuery(null);
    }

    $raw = $_GET['fmt'];
    if (is_array($raw)) {
        return [
            'ok' => false,
            'explicit' => true,
            'format' => null,
            'error' => 'fmt must be a single value',
        ];
    }

    return parsePublicApiWebcamFmtQuery((string) $raw);
}
