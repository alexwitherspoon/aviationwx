<?php
/**
 * ProFTPD virtual user auth file helpers for push camera FTP uploads.
 *
 * Uses mod_auth_file (ftpd.passwd) with per-user homedir via DefaultRoot ~.
 */

declare(strict_types=1);

const PROFTPD_AUTH_FILE = '/etc/proftpd/ftpd.passwd';
const PROFTPD_CONF_DIR = '/etc/proftpd/conf.d';
const PROFTPD_RUNTIME_CONF = '/etc/proftpd/conf.d/runtime.conf';
const PROFTPD_TLS_CONF = '/etc/proftpd/conf.d/tls.conf';
const PROFTPD_PID_FILE = '/var/run/proftpd.pid';
const PROFTPD_LOG_FILE = '/var/log/proftpd.log';

/**
 * Resolve ftp and www-data uid/gid for AuthUserFile entries.
 *
 * @return array{uid: int, gid: int}
 */
function getProftpdFtpUidGid(): array
{
    $ftpInfo = posix_getpwnam('ftp');
    $wwwDataInfo = posix_getpwnam('www-data');

    return [
        'uid' => $ftpInfo ? (int) $ftpInfo['uid'] : 101,
        'gid' => $wwwDataInfo ? (int) $wwwDataInfo['gid'] : 33,
    ];
}

/**
 * Hash a plaintext camera password for ProFTPD AuthUserFile.
 */
function hashProftpdPassword(string $password): string
{
    $salt = '$6$' . substr(
        str_replace(['+', '/'], ['.', '.'], base64_encode(random_bytes(12))),
        0,
        16
    );
    $hash = crypt($password, $salt);

    return $hash !== false && str_starts_with($hash, '$6$') ?
        $hash :
        crypt($password, '$6$aviationwxsalt');
}

/**
 * Parse ProFTPD AuthUserFile (colon-separated, seven fields).
 *
 * @return array{
 *   users: array<string, array{password_hash: string, uid: int, gid: int, home: string}>,
 *   errors: list<string>
 * }
 */
function parseProftpdPasswdFile(string $path = PROFTPD_AUTH_FILE): array
{
    $users = [];
    $errors = [];

    if (!file_exists($path)) {
        return ['users' => [], 'errors' => []];
    }

    $rawLines = @file($path, FILE_IGNORE_NEW_LINES);
    if ($rawLines === false) {
        return ['users' => [], 'errors' => ["Cannot read ProFTPD auth file: {$path}"]];
    }

    foreach ($rawLines as $lineNum => $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $parts = explode(':', $line);
        if (count($parts) < 7) {
            $errors[] = 'ProFTPD auth file malformed at line ' . ($lineNum + 1);
            continue;
        }

        $username = $parts[0];
        if ($username === '') {
            $errors[] = 'ProFTPD auth file has empty username at line ' . ($lineNum + 1);
            continue;
        }

        if (isset($users[$username])) {
            $errors[] = "Duplicate ProFTPD username '{$username}' at line " . ($lineNum + 1);
            continue;
        }

        $users[$username] = [
            'password_hash' => $parts[1],
            'uid' => (int) $parts[2],
            'gid' => (int) $parts[3],
            'home' => $parts[5],
        ];
    }

    return ['users' => $users, 'errors' => $errors];
}

/**
 * Write ProFTPD AuthUserFile from username => account map.
 *
 * @param array<string, array{password: string, home: string, uid?: int, gid?: int}> $accounts
 */
function writeProftpdPasswdFile(array $accounts, string $path = PROFTPD_AUTH_FILE): bool
{
    $defaults = getProftpdFtpUidGid();
    $lines = [];

    foreach ($accounts as $username => $account) {
        $password = $account['password'] ?? '';
        $home = $account['home'] ?? '';
        if ($username === '' || $password === '' || $home === '') {
            continue;
        }

        $uid = isset($account['uid']) ? (int) $account['uid'] : $defaults['uid'];
        $gid = isset($account['gid']) ? (int) $account['gid'] : $defaults['gid'];
        $hash = isProftpdPasswordHash($password) ?
            $password :
            hashProftpdPassword($password);

        $lines[] = implode(':', [
            $username,
            $hash,
            (string) $uid,
            (string) $gid,
            '',
            $home,
            '/usr/sbin/nologin',
        ]);
    }

    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }

    $tmpPath = $dir . '/.' . basename($path) . '.tmp.' . getmypid();
    $content = implode("\n", $lines) . (count($lines) > 0 ? "\n" : '');
    if (@file_put_contents($tmpPath, $content, LOCK_EX) === false) {
        @unlink($tmpPath);
        return false;
    }
    @chmod($tmpPath, 0600);

    if (!@rename($tmpPath, $path)) {
        @unlink($tmpPath);
        return false;
    }

    @chmod($path, 0600);

    return true;
}

/**
 * True when a password string is already a crypt(3) or braced hash for AuthUserFile.
 */
function isProftpdPasswordHash(string $password): bool
{
    if ($password === '') {
        return false;
    }
    if (str_starts_with($password, '{')) {
        return true;
    }

    return (bool) preg_match('/^\$[0-9A-Za-z]+\$/', $password);
}

/**
 * Whether ProFTPD explicit TLS (FTPS) is enabled in tls.conf.
 */
function isProftpdTlsEnabled(?string $path = null): bool
{
    $path = $path ?? PROFTPD_TLS_CONF;
    if (!is_readable($path)) {
        return false;
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        return false;
    }

    return (bool) preg_match('/^\s*TLSEngine\s+on\b/mi', $contents);
}

/**
 * True when auth file is missing or empty (forces full sync).
 */
function isProftpdAuthFileMissing(): bool
{
    return isProftpdAuthFileMissingAtPath(PROFTPD_AUTH_FILE);
}

/**
 * Test helper: missing or zero-byte auth file at a given path.
 */
function isProftpdAuthFileMissingAtPath(string $path): bool
{
    if (!file_exists($path)) {
        return true;
    }

    return filesize($path) === 0;
}

/**
 * Detect invalid ProFTPD auth state (missing file, empty file, or parse errors).
 */
function isProftpdAuthFileCorrupted(): bool
{
    return isProftpdAuthFileCorruptedAtPath(PROFTPD_AUTH_FILE);
}

/**
 * Test helper: auth file missing or unparseable at a given path.
 */
function isProftpdAuthFileCorruptedAtPath(string $path): bool
{
    if (!file_exists($path)) {
        return true;
    }

    if (filesize($path) === 0) {
        return true;
    }

    $parsed = parseProftpdPasswdFile($path);

    return $parsed['errors'] !== [];
}

/**
 * Reload ProFTPD after auth or endpoint config changes (SIGHUP).
 */
function reloadProftpdDaemon(): bool
{
    if (!is_readable(PROFTPD_PID_FILE)) {
        return false;
    }

    $pid = trim((string) file_get_contents(PROFTPD_PID_FILE));
    if ($pid === '' || !ctype_digit($pid)) {
        return false;
    }

    if (!function_exists('posix_kill')) {
        return false;
    }

    if (!defined('SIGHUP')) {
        return false;
    }

    return posix_kill((int) $pid, SIGHUP);
}
