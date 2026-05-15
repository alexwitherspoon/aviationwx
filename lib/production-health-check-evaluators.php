<?php
/**
 * JSON and response-shape evaluators for {@see scripts/production-health-check.php}.
 *
 * Keeps probe assertions in one place for unit testing without outbound HTTP.
 *
 * @package AviationWX
 */

declare(strict_types=1);

/**
 * Decode a JSON object body to an associative array for probe scripts.
 *
 * Uses a depth limit to avoid stack exhaustion on hostile payloads. Returns null on empty body,
 * invalid JSON, or when the top-level JSON value is not a JSON object (arrays, scalars, null).
 *
 * @param string $body Raw HTTP body
 * @param int $maxDepth Maximum nesting depth for json_decode
 * @return array<string, mixed>|null
 */
function productionHealthCheckJsonDecodeAssoc(string $body, int $maxDepth = 64): ?array
{
    if ($body === '') {
        return null;
    }
    $flags = JSON_BIGINT_AS_STRING;
    $tree = json_decode($body, false, $maxDepth, $flags);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }
    if (!$tree instanceof stdClass) {
        return null;
    }
    $decoded = json_decode($body, true, $maxDepth, $flags);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        return null;
    }

    return $decoded;
}

/**
 * Evaluate GET /v1/status JSON body (Public API).
 *
 * @param mixed $json Decoded JSON (typically array)
 * @return array{ok: bool, detail: string}
 */
function productionHealthCheckEvaluateApiV1StatusJson(mixed $json): array
{
    if (!is_array($json) || ($json['success'] ?? null) !== true) {
        return ['ok' => false, 'detail' => 'missing or false success'];
    }
    if (!isset($json['status']) || !is_array($json['status'])) {
        return ['ok' => false, 'detail' => 'missing status object'];
    }
    $st = $json['status'];
    if (!isset($st['status']) || !is_string($st['status']) || $st['status'] === '') {
        return ['ok' => false, 'detail' => 'missing status.status string'];
    }
    if (!isset($st['checks']) || !is_array($st['checks'])) {
        return ['ok' => false, 'detail' => 'missing status.checks'];
    }

    return ['ok' => true, 'detail' => 'status checks ok (' . $st['status'] . ')'];
}

/**
 * Evaluate GET /v1/version.php JSON body.
 *
 * @param mixed $json Decoded JSON
 * @return array{ok: bool, detail: string}
 */
function productionHealthCheckEvaluateApiV1VersionJson(mixed $json): array
{
    if (!is_array($json)) {
        return ['ok' => false, 'detail' => 'not a JSON object'];
    }
    if (!isset($json['hash']) || !is_string($json['hash']) || $json['hash'] === '') {
        return ['ok' => false, 'detail' => 'missing hash string'];
    }
    $hasDeploy = isset($json['deploy_date']) && is_string($json['deploy_date']) && $json['deploy_date'] !== '';
    $hasTs = isset($json['timestamp']) && is_numeric($json['timestamp']);
    if (!$hasDeploy && !$hasTs) {
        return ['ok' => false, 'detail' => 'missing deploy_date and timestamp'];
    }

    return ['ok' => true, 'detail' => 'hash ' . $json['hash']];
}

/**
 * Evaluate GET /openapi.json body.
 *
 * @param mixed $json Decoded JSON
 * @return array{ok: bool, detail: string}
 */
function productionHealthCheckEvaluateOpenapiJson(mixed $json): array
{
    if (!is_array($json)) {
        return ['ok' => false, 'detail' => 'not a JSON object'];
    }
    if (!isset($json['openapi']) || !is_string($json['openapi'])) {
        return ['ok' => false, 'detail' => 'missing openapi version string'];
    }
    if (!str_starts_with($json['openapi'], '3.')) {
        return ['ok' => false, 'detail' => 'unexpected openapi major: ' . $json['openapi']];
    }
    if (!isset($json['info']) || !is_array($json['info'])) {
        return ['ok' => false, 'detail' => 'missing info object'];
    }
    if (!isset($json['paths']) || !is_array($json['paths'])) {
        return ['ok' => false, 'detail' => 'missing paths object'];
    }

    return ['ok' => true, 'detail' => 'OpenAPI ' . $json['openapi']];
}

/**
 * Evaluate GET /v1/operations JSON body.
 *
 * @param mixed $json Decoded JSON
 * @return array{ok: bool, detail: string}
 */
function productionHealthCheckEvaluateApiV1OperationsJson(mixed $json): array
{
    if (!is_array($json) || ($json['success'] ?? null) !== true) {
        return ['ok' => false, 'detail' => 'missing or false success'];
    }
    if (!isset($json['operations']) || !is_array($json['operations'])) {
        return ['ok' => false, 'detail' => 'missing operations object'];
    }
    if (!isset($json['operations']['snapshot_meta']) || !is_array($json['operations']['snapshot_meta'])) {
        return ['ok' => false, 'detail' => 'missing operations.snapshot_meta'];
    }
    $meta = $json['operations']['snapshot_meta'];
    if (!isset($meta['schema_version']) || !is_numeric($meta['schema_version'])) {
        return ['ok' => false, 'detail' => 'missing operations.snapshot_meta.schema_version'];
    }

    return ['ok' => true, 'detail' => 'snapshot_meta ok'];
}

/**
 * Evaluate GET /health/health.php JSON body.
 *
 * @param mixed $json Decoded JSON
 * @return array{ok: bool, detail: string}
 */
function productionHealthCheckEvaluateHealthLiveJson(mixed $json): array
{
    if (!is_array($json)) {
        return ['ok' => false, 'detail' => 'not a JSON object'];
    }
    if (!array_key_exists('ok', $json) || $json['ok'] !== true) {
        return ['ok' => false, 'detail' => 'ok is not true'];
    }
    if (!isset($json['time']) || !is_numeric($json['time'])) {
        return ['ok' => false, 'detail' => 'missing or non-numeric time'];
    }

    return ['ok' => true, 'detail' => 'live JSON ok'];
}

/**
 * Evaluate GET /health/ready.php JSON body (HTTP 200 or 503).
 *
 * @param mixed $json Decoded JSON
 * @return array{ok: bool, detail: string}
 */
function productionHealthCheckEvaluateHealthReadyJson(mixed $json): array
{
    if (!is_array($json)) {
        return ['ok' => false, 'detail' => 'not a JSON object'];
    }
    if (!array_key_exists('ok', $json) || !is_bool($json['ok'])) {
        return ['ok' => false, 'detail' => 'missing boolean ok'];
    }
    if (!isset($json['errors']) || !is_array($json['errors'])) {
        return ['ok' => false, 'detail' => 'missing errors array'];
    }

    return ['ok' => true, 'detail' => 'ready JSON ok'];
}

/**
 * Evaluate GET /api/outage-status.php JSON for a valid airport (HTTP 200).
 *
 * @param mixed $json Decoded JSON
 * @return array{ok: bool, detail: string}
 */
function productionHealthCheckEvaluateOutageStatusJson(mixed $json): array
{
    if (!is_array($json)) {
        return ['ok' => false, 'detail' => 'not a JSON object'];
    }
    if (($json['success'] ?? null) !== true) {
        return ['ok' => false, 'detail' => 'missing or false success'];
    }
    foreach (['maintenance', 'in_outage', 'limited_availability'] as $boolKey) {
        if (!array_key_exists($boolKey, $json) || !is_bool($json[$boolKey])) {
            return ['ok' => false, 'detail' => 'missing boolean ' . $boolKey];
        }
    }
    if (!array_key_exists('newest_timestamp', $json) || !is_numeric($json['newest_timestamp'])) {
        return ['ok' => false, 'detail' => 'missing or non-numeric newest_timestamp'];
    }
    if (!isset($json['sources']) || !is_array($json['sources'])) {
        return ['ok' => false, 'detail' => 'missing sources object'];
    }

    return ['ok' => true, 'detail' => 'outage banner JSON ok'];
}

/**
 * Evaluate GET /v1/airports/{id}/embed JSON (Public API, non-diff response).
 *
 * @param mixed $json Decoded JSON
 * @return array{ok: bool, detail: string}
 */
function productionHealthCheckEvaluateApiV1EmbedJson(mixed $json): array
{
    if (!is_array($json) || ($json['success'] ?? null) !== true) {
        return ['ok' => false, 'detail' => 'missing or false success'];
    }
    if (!isset($json['data']) || !is_array($json['data'])) {
        return ['ok' => false, 'detail' => 'missing data object'];
    }
    if (!isset($json['data']['embed']) || !is_array($json['data']['embed'])) {
        return ['ok' => false, 'detail' => 'missing data.embed object'];
    }
    $embed = $json['data']['embed'];
    if ($embed === [] || array_is_list($embed)) {
        return ['ok' => false, 'detail' => 'data.embed must be a non-empty object'];
    }

    return ['ok' => true, 'detail' => 'embed payload present'];
}

/**
 * Evaluate GET /v1/airports/{id}/weather JSON (Public API success envelope).
 *
 * @param mixed $json Decoded JSON
 * @return array{ok: bool, detail: string}
 */
function productionHealthCheckEvaluateApiV1WeatherJson(mixed $json): array
{
    if (!is_array($json) || ($json['success'] ?? null) !== true) {
        return ['ok' => false, 'detail' => 'missing or false success'];
    }
    if (!isset($json['weather']) || !is_array($json['weather'])) {
        return ['ok' => false, 'detail' => 'missing weather object'];
    }

    return ['ok' => true, 'detail' => 'weather payload present'];
}

/**
 * Evaluate GET /v1/airports/{id}/webcams JSON (Public API success envelope).
 *
 * @param mixed $json Decoded JSON
 * @return array{ok: bool, detail: string}
 */
function productionHealthCheckEvaluateApiV1WebcamsJson(mixed $json): array
{
    if (!is_array($json) || ($json['success'] ?? null) !== true) {
        return ['ok' => false, 'detail' => 'missing or false success'];
    }
    if (!array_key_exists('webcams', $json) || !is_array($json['webcams']) || !array_is_list($json['webcams'])) {
        return ['ok' => false, 'detail' => 'missing webcams list'];
    }

    return ['ok' => true, 'detail' => 'webcams payload present'];
}
