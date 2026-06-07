<?php

declare(strict_types=1);

/**
 * Exchange volume spool writers (OSS → Operator Console).
 *
 * Used only when config.contributions.enabled is true.
 */

require_once __DIR__ . '/config.php';

/** Restrictive mode for exchange spool files (owner/group read, not world-readable). */
const AVIATIONWX_EXCHANGE_FILE_MODE = 0640;

/**
 * Root path for the shared exchange volume.
 */
function aviationwx_exchange_root(): string
{
    $root = getenv('EXCHANGE_PATH');
    if ($root === false || $root === '') {
        $root = '/exchange';
    }

    return rtrim($root, '/');
}

/**
 * Atomically write a JSON document to the exchange spool.
 *
 * @param array<string, mixed> $payload
 */
function aviationwx_exchange_write_json(string $targetPath, array $payload): void
{
    $dir = dirname($targetPath);
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create exchange directory: ' . $dir);
    }

    $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $tmp = $targetPath . '.' . bin2hex(random_bytes(8)) . '.tmp';
    $written = file_put_contents($tmp, $json);
    if ($written === false || $written !== strlen($json)) {
        @unlink($tmp);
        throw new RuntimeException('Failed to write exchange temp file');
    }

    if (!rename($tmp, $targetPath)) {
        @unlink($tmp);
        throw new RuntimeException('Failed to publish exchange file');
    }

    aviationwx_exchange_restrict_file_permissions($targetPath);
}

/**
 * @internal
 */
function aviationwx_exchange_restrict_file_permissions(string $path): void
{
    if (!chmod($path, AVIATIONWX_EXCHANGE_FILE_MODE)) {
        throw new RuntimeException('Failed to set exchange file permissions');
    }
}

/**
 * Append one structured log line for console ingest.
 *
 * @param array<string, mixed> $context
 */
function aviationwx_exchange_append_structured_log(
    string $level,
    string $message,
    array $context = [],
    ?string $requestId = null,
): void {
    $now = new DateTime('now', new DateTimeZone('UTC'));
    $line = [
        'schema_version' => '1.0.0',
        'logged_at' => $now->format('c'),
        'level' => strtolower($level),
        'message' => $message,
        'context' => $context,
        'source' => $context['source'] ?? (PHP_SAPI === 'cli' ? 'cli' : 'web'),
    ];

    if ($requestId !== null) {
        $line['request_id'] = $requestId;
    }

    $day = $now->format('Y-m-d');
    $path = aviationwx_exchange_root() . '/in/structured-logs/' . $day . '.jsonl';
    $encoded = json_encode($line, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";

    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create structured log directory');
    }

    if (file_put_contents($path, $encoded, FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('Failed to append structured log line');
    }

    aviationwx_exchange_restrict_file_permissions($path);
}

/**
 * Write a sponsor application spool file for console ingest.
 *
 * @param array{
 *   application_id: string,
 *   airport_id: string,
 *   org_name: string,
 *   contact_name: string,
 *   contact_email: string,
 *   org_type: string,
 *   logo_url?: string,
 *   message?: string
 * } $application
 */
function aviationwx_exchange_write_sponsor_application(array $application): string
{
    $applicationId = aviationwx_exchange_normalize_application_id($application['application_id']);
    $payload = [
        'schema_version' => '1.0.0',
        'application_id' => $applicationId,
        'submitted_at' => (new DateTime('now', new DateTimeZone('UTC')))->format('c'),
        'org_name' => trim($application['org_name']),
        'airport_id' => strtolower(trim($application['airport_id'])),
        'contact_name' => trim($application['contact_name']),
        'contact_email' => strtolower(trim($application['contact_email'])),
        'org_type' => $application['org_type'],
    ];

    if (
        isset($application['logo_url'])
        && is_string($application['logo_url'])
        && trim($application['logo_url']) !== ''
    ) {
        $payload['logo_url'] = trim($application['logo_url']);
    }
    if (
        isset($application['message'])
        && is_string($application['message'])
        && trim($application['message']) !== ''
    ) {
        $payload['message'] = trim($application['message']);
    }

    $path = aviationwx_exchange_root() . '/in/sponsor-applications/' . $applicationId . '.json';
    aviationwx_exchange_write_json($path, $payload);

    return $path;
}

/**
 * Normalize and validate a sponsor application UUID for spool filenames.
 */
function aviationwx_exchange_normalize_application_id(mixed $applicationId): string
{
    if (!is_string($applicationId)) {
        throw new InvalidArgumentException('Invalid application_id');
    }

    $normalized = strtolower(trim($applicationId));
    if (
        preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $normalized,
        ) !== 1
    ) {
        throw new InvalidArgumentException('Invalid application_id');
    }

    return $normalized;
}

/**
 * Whether an airport id exists in loaded config.
 */
function aviationwx_airport_exists_in_config(string $airportId, ?array $config = null): bool
{
    if ($config === null) {
        $config = loadConfig();
    }
    if ($config === null) {
        return false;
    }

    $key = strtolower(trim($airportId));
    if ($key === '' || str_starts_with($key, '_')) {
        return false;
    }

    return isset($config['airports'][$key]) && is_array($config['airports'][$key]);
}
