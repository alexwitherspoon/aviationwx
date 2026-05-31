<?php

/**
 * Internal API - airport sponsor application submit (exchange spool).
 *
 * POST JSON when config.contributions.enabled is true. Writes spool file for ops ingest.
 */

declare(strict_types=1);

/** Maximum JSON request body size for sponsor application submissions (bytes). */
const SPONSOR_APPLICATION_MAX_BODY_BYTES = 16384;

/** Rate limit window for sponsor application POSTs (seconds); matches checkRateLimit() call. */
const SPONSOR_APPLICATION_RATE_LIMIT_WINDOW = 3600;

/**
 * Thrown when the sponsor application handler finishes in test mode (instead of exit).
 */
final class SponsorApplicationHandlerStopped extends RuntimeException
{
}

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/rate-limit.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/exchange-spool.php';

if (!defined('AVIATIONWX_SPONSOR_APPLICATION_LOAD_ONLY')) {
    sponsorApplicationHeader('Content-Type: application/json; charset=utf-8');
    sponsorApplicationHeader('Cache-Control: no-store');

    if (!isContributionsEnabled()) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found'], JSON_THROW_ON_ERROR);
        sponsorApplicationFinish();
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        sponsorApplicationHeader('Allow: POST');
        echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_THROW_ON_ERROR);
        sponsorApplicationFinish();
    }

    if (!checkRateLimit('sponsor_application', 3, SPONSOR_APPLICATION_RATE_LIMIT_WINDOW)) {
        http_response_code(429);
        sponsorApplicationHeader('Retry-After: ' . SPONSOR_APPLICATION_RATE_LIMIT_WINDOW);
        echo json_encode(['ok' => false, 'error' => 'Too many requests'], JSON_THROW_ON_ERROR);
        sponsorApplicationFinish();
    }

    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > SPONSOR_APPLICATION_MAX_BODY_BYTES) {
        http_response_code(413);
        echo json_encode(['ok' => false, 'error' => 'Request body too large'], JSON_THROW_ON_ERROR);
        sponsorApplicationFinish();
    }

    if (defined('AVIATIONWX_SPONSOR_APPLICATION_TEST_MODE')) {
        $raw = getenv('SPONSOR_APPLICATION_TEST_INPUT');
        $raw = is_string($raw) ? $raw : '';
        if (strlen($raw) > SPONSOR_APPLICATION_MAX_BODY_BYTES) {
            http_response_code(413);
            echo json_encode(['ok' => false, 'error' => 'Request body too large'], JSON_THROW_ON_ERROR);
            sponsorApplicationFinish();
        }
    } else {
        $raw = file_get_contents('php://input');
        $raw = is_string($raw) ? $raw : '';
        if (strlen($raw) > SPONSOR_APPLICATION_MAX_BODY_BYTES) {
            http_response_code(413);
            echo json_encode(['ok' => false, 'error' => 'Request body too large'], JSON_THROW_ON_ERROR);
            sponsorApplicationFinish();
        }
    }

    if (trim($raw) === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON body'], JSON_THROW_ON_ERROR);
        sponsorApplicationFinish();
    }

    try {
        $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON body'], JSON_THROW_ON_ERROR);
        sponsorApplicationFinish();
    }

    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON body'], JSON_THROW_ON_ERROR);
        sponsorApplicationFinish();
    }

    if (!empty($body['website'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid request'], JSON_THROW_ON_ERROR);
        sponsorApplicationFinish();
    }

    $errors = validateSponsorApplicationBody($body);
    if ($errors !== []) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Validation failed', 'fields' => $errors], JSON_THROW_ON_ERROR);
        sponsorApplicationFinish();
    }

    $applicationId = generateSponsorApplicationUuid();

    try {
        aviationwx_exchange_write_sponsor_application([
            'application_id' => $applicationId,
            'airport_id' => (string) $body['airport_id'],
            'org_name' => (string) $body['org_name'],
            'contact_name' => (string) $body['contact_name'],
            'contact_email' => (string) $body['contact_email'],
            'org_type' => (string) $body['org_type'],
            'logo_url' => $body['logo_url'] ?? null,
            'message' => $body['message'] ?? null,
        ]);
    } catch (Throwable $e) {
        aviationwx_log('error', 'sponsor application spool write failed', [
            'application_id' => $applicationId,
            'error' => $e->getMessage(),
        ], 'app', true);

        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Unable to submit application'], JSON_THROW_ON_ERROR);
        sponsorApplicationFinish();
    }

    aviationwx_log('info', 'sponsor application spool written', [
        'application_id' => $applicationId,
        'airport_id' => strtolower(trim((string) $body['airport_id'])),
    ], 'app');

    http_response_code(202);
    echo json_encode([
        'ok' => true,
        'application_id' => $applicationId,
        'message' => 'Application received. We will review it and follow up by email.',
    ], JSON_THROW_ON_ERROR);
    sponsorApplicationFinish();
}

/**
 * @param array<string, mixed> $body
 *
 * @return array<string, string>
 */
function validateSponsorApplicationBody(array $body): array
{
    $errors = [];

    $airportId = is_string($body['airport_id'] ?? null) ? strtolower(trim($body['airport_id'])) : '';
    if ($airportId === '' || preg_match('/^[a-z0-9-]+$/', $airportId) !== 1) {
        $errors['airport_id'] = 'Invalid airport_id';
    } elseif (!aviationwx_airport_exists_in_config($airportId)) {
        $errors['airport_id'] = 'Unknown airport';
    }

    foreach (['org_name', 'contact_name'] as $field) {
        if (!is_string($body[$field] ?? null) || trim((string) $body[$field]) === '') {
            $errors[$field] = 'Required';
        }
    }

    $email = is_string($body['contact_email'] ?? null) ? strtolower(trim($body['contact_email'])) : '';
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $errors['contact_email'] = 'Invalid email';
    }

    $orgType = $body['org_type'] ?? '';
    if (!in_array($orgType, ['on_airport_business', 'aviation_organization', 'other'], true)) {
        $errors['org_type'] = 'Invalid org_type';
    }

    if (isset($body['logo_url']) && $body['logo_url'] !== null && $body['logo_url'] !== '') {
        if (!is_string($body['logo_url'])) {
            $errors['logo_url'] = 'Invalid logo_url';
        } else {
            $logoUrl = trim($body['logo_url']);
            $scheme = is_string(parse_url($logoUrl, PHP_URL_SCHEME)) ? strtolower(parse_url($logoUrl, PHP_URL_SCHEME)) : '';
            if (
                filter_var($logoUrl, FILTER_VALIDATE_URL) === false
                || !in_array($scheme, ['http', 'https'], true)
            ) {
                $errors['logo_url'] = 'Invalid logo_url';
            }
        }
    }

    if (array_key_exists('message', $body) && $body['message'] !== null && $body['message'] !== '') {
        if (!is_string($body['message'])) {
            $errors['message'] = 'Invalid message';
        } elseif (strlen($body['message']) > 4000) {
            $errors['message'] = 'Message too long';
        }
    }

    return $errors;
}

function generateSponsorApplicationUuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

function sponsorApplicationHeader(string $headerLine): void
{
    header($headerLine);
    if (defined('AVIATIONWX_SPONSOR_APPLICATION_TEST_MODE')) {
        $parts = explode(':', $headerLine, 2);
        if (count($parts) === 2) {
            $GLOBALS['AVIATIONWX_SPONSOR_APPLICATION_CAPTURED_HEADERS'][strtolower(trim($parts[0]))] = trim($parts[1]);
        }
    }
}

function sponsorApplicationFinish(): never
{
    if (defined('AVIATIONWX_SPONSOR_APPLICATION_TEST_MODE')) {
        throw new SponsorApplicationHandlerStopped();
    }

    exit;
}
