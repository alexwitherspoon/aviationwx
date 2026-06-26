<?php
/**
 * Upload SFTP SSH host key roster (well-known JSON).
 *
 * GET /.well-known/aviationwx-upload-ssh-host-keys.json
 * Fingerprints are computed at request time from /etc/ssh/ssh_host_*_key.pub.
 */

require_once __DIR__ . '/../lib/cache-headers.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/upload-ssh-host-keys.php';

sendNoStoreCacheHeaders();
header('Content-Type: application/json; charset=utf-8');

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($requestMethod === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($requestMethod !== 'GET') {
    header('Allow: GET, OPTIONS');
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_SLASHES);
    exit;
}

$document = buildUploadSshHostKeysDocument();
if ($document === null) {
    aviationwx_log('error', 'upload ssh host keys roster unavailable', [
        'ssh_dir' => UPLOAD_SSH_HOST_KEYS_DIR,
    ], 'app');
    http_response_code(503);
    echo json_encode([
        'error' => 'SSH host key roster unavailable',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode($document, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
