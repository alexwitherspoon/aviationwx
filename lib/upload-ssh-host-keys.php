<?php
/**
 * Live SFTP SSH host key fingerprint helpers.
 *
 * Reads ssh_host_*_key.pub from the container sshd config directory at request time
 * so clients can pin the keys currently served on config.network_ports.sftp.
 */

require_once __DIR__ . '/config.php';

/** Default sshd host public key directory in the web/SFTP container. */
const UPLOAD_SSH_HOST_KEYS_DIR = '/etc/ssh';

/** Glob for sshd host public keys (excludes private keys and non-host keys). */
const UPLOAD_SSH_HOST_PUBLIC_KEY_GLOB = '/ssh_host_*_key.pub';

/**
 * Compute OpenSSH SHA256 fingerprint for one authorized_keys-style public key line.
 *
 * @param string $publicKeyLine Single-line OpenSSH public key
 * @return string|null Fingerprint as SHA256:base64 or null when unparsable
 */
function sshPublicKeySha256Fingerprint(string $publicKeyLine): ?string
{
    $trimmed = trim($publicKeyLine);
    if ($trimmed === '' || str_starts_with($trimmed, '#')) {
        return null;
    }

    $parts = preg_split('/\s+/', $trimmed, 3);
    if (!is_array($parts) || count($parts) < 2 || $parts[1] === '') {
        return null;
    }

    $keyBlob = base64_decode($parts[1], true);
    if ($keyBlob === false) {
        return null;
    }

    $hash = hash('sha256', $keyBlob, true);
    $encoded = rtrim(base64_encode($hash), '=');

    return 'SHA256:' . $encoded;
}

/**
 * Collect SHA256 fingerprints from sshd host public keys in a directory.
 *
 * @param string $sshDir Directory containing ssh_host_*_key.pub files
 * @return list<string> Sorted unique fingerprints
 */
function collectSshHostKeySha256Fingerprints(string $sshDir = UPLOAD_SSH_HOST_KEYS_DIR): array
{
    $pattern = rtrim($sshDir, '/') . UPLOAD_SSH_HOST_PUBLIC_KEY_GLOB;
    $files = glob($pattern);
    if ($files === false || $files === []) {
        return [];
    }

    sort($files, SORT_STRING);
    $fingerprints = [];

    foreach ($files as $path) {
        if (!is_readable($path)) {
            continue;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            continue;
        }

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $fingerprint = sshPublicKeySha256Fingerprint((string) $line);
            if ($fingerprint !== null) {
                $fingerprints[$fingerprint] = true;
            }
        }
    }

    $unique = array_keys($fingerprints);
    sort($unique, SORT_STRING);

    return $unique;
}

/**
 * Build the v1 upload SSH host key roster document.
 *
 * @param string|null $sshDir Override host key directory (for tests)
 * @return array{
 *   version: int,
 *   hostname: string,
 *   port: int,
 *   sha256: list<string>,
 *   updated_at: string
 * }|null Null when no readable host keys were found
 */
function buildUploadSshHostKeysDocument(?string $sshDir = null): ?array
{
    $fingerprints = collectSshHostKeySha256Fingerprints($sshDir ?? UPLOAD_SSH_HOST_KEYS_DIR);
    if ($fingerprints === []) {
        return null;
    }

    return [
        'version' => 1,
        'hostname' => getUploadHostname(),
        'port' => getSftpPort(),
        'sha256' => $fingerprints,
        'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ];
}
