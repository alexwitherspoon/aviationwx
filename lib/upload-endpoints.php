<?php
/**
 * Upload endpoint cache and ProFTPD masquerade sync for family-aware PASV.
 */

declare(strict_types=1);

const UPLOAD_ENDPOINTS_CACHE_FILE = '/var/lib/aviationwx/upload-endpoints.json';
const UPLOAD_ENDPOINTS_PROFTPD_CONF = '/etc/proftpd/conf.d/masquerade.conf';
const UPLOAD_ENDPOINTS_REFRESH_STATE_FILE = '/var/lib/aviationwx/upload-endpoints-refresh.last';
const UPLOAD_ENDPOINTS_REFRESH_LOG_FILE = '/var/lib/aviationwx/upload-endpoints-refresh.log';

/** @var list<string> */
const UPLOAD_CAPABILITY_KEYS = ['plain_ftp', 'ftps', 'sftp', 'ipv4', 'ipv6'];

/**
 * Default upload capability toggles (all enabled).
 *
 * @return array{plain_ftp: bool, ftps: bool, sftp: bool, ipv4: bool, ipv6: bool}
 */
function getDefaultUploadCapabilities(): array
{
    return [
        'plain_ftp' => true,
        'ftps' => true,
        'sftp' => true,
        'ipv4' => true,
        'ipv6' => true,
    ];
}

/**
 * Upload capability flags from config (defaults all true).
 *
 * @return array{plain_ftp: bool, ftps: bool, sftp: bool, ipv4: bool, ipv6: bool}
 */
function getUploadCapabilities(): array
{
    $defaults = getDefaultUploadCapabilities();
    $raw = getGlobalConfig('upload_capabilities');
    if (!is_array($raw)) {
        return $defaults;
    }

    foreach (UPLOAD_CAPABILITY_KEYS as $key) {
        if (array_key_exists($key, $raw)) {
            $defaults[$key] = $raw[$key] === true;
        }
    }

    return $defaults;
}

/**
 * @return array<int, string>
 */
function validateUploadCapabilitiesConfig(array $config): array
{
    $errors = [];
    if (!isset($config['upload_capabilities'])) {
        return $errors;
    }

    $raw = $config['upload_capabilities'];
    if (!is_array($raw) || array_is_list($raw)) {
        $errors[] = 'config.upload_capabilities must be an object';

        return $errors;
    }

    foreach ($raw as $key => $value) {
        if (!is_string($key) || !in_array($key, UPLOAD_CAPABILITY_KEYS, true)) {
            $errors[] = "config.upload_capabilities: unknown key '{$key}'";
            continue;
        }
        if (!is_bool($value)) {
            $errors[] = "config.upload_capabilities.{$key} must be a boolean";
        }
    }

    return $errors;
}

/**
 * Baseline DNS refresh interval for upload endpoints (seconds, 0 = disabled).
 */
function getDynamicDnsRefreshSeconds(): int
{
    if (isUploadEndpointFullyStatic()) {
        return 0;
    }

    $seconds = getGlobalConfig('dynamic_dns_refresh_seconds');
    if ($seconds === null || !is_int($seconds) || $seconds <= 0) {
        return 0;
    }

    return max(60, $seconds);
}

/**
 * Accelerated refresh interval when fleet upload probe is unhealthy.
 */
function getDynamicDnsAcceleratedRefreshSeconds(): int
{
    if (!isDynamicDnsEnabled()) {
        return 0;
    }

    $seconds = getGlobalConfig('dynamic_dns_accelerated_refresh_seconds');
    if ($seconds === null || !is_int($seconds) || $seconds <= 0) {
        return 60;
    }

    return max(60, $seconds);
}

/**
 * Whether periodic endpoint refresh is enabled.
 */
function isDynamicDnsEnabled(): bool
{
    return getDynamicDnsRefreshSeconds() > 0;
}

/**
 * True when both address families have static config overrides (no DNS refresh needed).
 */
function isUploadEndpointFullyStatic(): bool
{
    $caps = getUploadCapabilities();
    $needsV4 = $caps['ipv4'];
    $needsV6 = $caps['ipv6'];

    if (!$needsV4 && !$needsV6) {
        return true;
    }

    if ($needsV4 && getPublicIP() === null) {
        return false;
    }
    if ($needsV6 && getPublicIPv6() === null) {
        return false;
    }

    return $needsV4 || $needsV6;
}

/**
 * Resolve upload endpoints from config overrides and DNS.
 *
 * @return array{
 *   hostname: string,
 *   ipv4: string|null,
 *   ipv6: string|null,
 *   resolved_at: string,
 *   source: array{ipv4: string, ipv6: string}
 * }
 */
function resolveUploadEndpointsFromConfig(): array
{
    $caps = getUploadCapabilities();
    $hostname = getUploadHostname();
    $resolvedAt = gmdate('Y-m-d\TH:i:s\Z');
    $ipv4 = null;
    $ipv6 = null;
    $sourceV4 = 'disabled';
    $sourceV6 = 'disabled';

    if ($caps['ipv4']) {
        $overrideV4 = getPublicIP();
        if ($overrideV4 !== null) {
            $ipv4 = $overrideV4;
            $sourceV4 = 'override';
        } else {
            $resolved = resolveUploadHostnameAddresses($hostname, 'ipv4');
            if ($resolved !== null) {
                $ipv4 = $resolved;
                $sourceV4 = 'dns';
            }
        }
    }

    if ($caps['ipv6']) {
        $overrideV6 = getPublicIPv6();
        if ($overrideV6 !== null) {
            $ipv6 = $overrideV6;
            $sourceV6 = 'override';
        } else {
            $resolved = resolveUploadHostnameAddresses($hostname, 'ipv6');
            if ($resolved !== null) {
                $ipv6 = $resolved;
                $sourceV6 = 'dns';
            }
        }
    }

    return [
        'hostname' => $hostname,
        'ipv4' => $ipv4,
        'ipv6' => $ipv6,
        'resolved_at' => $resolvedAt,
        'source' => [
            'ipv4' => $sourceV4,
            'ipv6' => $sourceV6,
        ],
    ];
}

/**
 * Resolve upload hostname to a single address for the given family.
 */
function resolveUploadHostnameAddresses(string $hostname, string $family): ?string
{
    if ($family !== 'ipv4' && $family !== 'ipv6') {
        return null;
    }

    $script = '/usr/local/bin/resolve-upload-ip.sh';
    if (!is_executable($script)) {
        $script = __DIR__ . '/../scripts/resolve-upload-ip.sh';
    }
    if (!is_executable($script)) {
        return resolveUploadHostnameAddressesPhp($hostname, $family);
    }

    $cmd = escapeshellarg($script) . ' ' . escapeshellarg($hostname) . ' ' . escapeshellarg($family);
    $output = [];
    $exitCode = 0;
    exec($cmd . ' 2>/dev/null', $output, $exitCode);
    if ($exitCode !== 0 || $output === []) {
        return resolveUploadHostnameAddressesPhp($hostname, $family);
    }

    $candidate = trim($output[0]);
    $flag = $family === 'ipv4' ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6;
    if (!filter_var($candidate, FILTER_VALIDATE_IP, $flag)) {
        return null;
    }

    return $candidate;
}

/**
 * PHP fallback DNS resolution when resolve-upload-ip.sh is unavailable.
 */
function resolveUploadHostnameAddressesPhp(string $hostname, string $family): ?string
{
    $records = dns_get_record($hostname, DNS_A + DNS_AAAA);
    if ($records === false || $records === []) {
        return null;
    }

    if ($family === 'ipv4') {
        foreach ($records as $record) {
            if (($record['type'] ?? '') === 'A' && isset($record['ip'])) {
                return $record['ip'];
            }
        }

        return null;
    }

    foreach ($records as $record) {
        if (($record['type'] ?? '') === 'AAAA' && isset($record['ipv6'])) {
            return $record['ipv6'];
        }
    }

    return null;
}

/**
 * Read cached upload endpoints (null when missing or invalid).
 *
 * @return array<string, mixed>|null
 */
function readUploadEndpointsCache(?string $path = null): ?array
{
    $path = $path ?? UPLOAD_ENDPOINTS_CACHE_FILE;
    if (!is_readable($path)) {
        return null;
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return null;
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }

    if (!is_array($decoded)) {
        return null;
    }

    return $decoded;
}

/**
 * Atomically write upload endpoint cache.
 *
 * @param array<string, mixed> $state
 */
function writeUploadEndpointsCache(array $state, ?string $path = null): bool
{
    $path = $path ?? UPLOAD_ENDPOINTS_CACHE_FILE;
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        return false;
    }

    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    $json .= "\n";

    $tmp = $dir . '/.' . basename($path) . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        @unlink($tmp);

        return false;
    }
    @chmod($tmp, 0640);

    if (!@rename($tmp, $path)) {
        @unlink($tmp);

        return false;
    }

    @chmod($path, 0640);

    return true;
}

/**
 * Refresh endpoint cache from config/DNS and sync ProFTPD masquerade config.
 *
 * @return array{ok: bool, changed: bool, endpoints: array<string, mixed>, error?: string}
 */
function refreshUploadEndpoints(bool $reloadDaemon = true): array
{
    $endpoints = resolveUploadEndpointsFromConfig();
    $validationError = validateUploadEndpointsForCapabilities($endpoints);
    if ($validationError !== null) {
        return [
            'ok' => false,
            'changed' => false,
            'endpoints' => $endpoints,
            'error' => $validationError,
        ];
    }

    $warnings = collectUploadEndpointWarnings($endpoints);
    $previous = readUploadEndpointsCache();
    $changed = $previous === null || uploadEndpointsStateChanged($previous, $endpoints);

    if (!writeUploadEndpointsCache($endpoints)) {
        return [
            'ok' => false,
            'changed' => false,
            'endpoints' => $endpoints,
            'error' => 'Failed to write upload endpoint cache',
        ];
    }

    if (!writeProftpdMasqueradeConf($endpoints)) {
        return [
            'ok' => false,
            'changed' => $changed,
            'endpoints' => $endpoints,
            'error' => 'Failed to write ProFTPD masquerade configuration',
        ];
    }

    if ($changed) {
        $logContext = [
            'hostname' => $endpoints['hostname'],
            'ipv4' => $endpoints['ipv4'],
            'ipv6' => $endpoints['ipv6'],
            'source' => $endpoints['source'],
        ];
        if ($warnings !== []) {
            $logContext['warnings'] = $warnings;
        }
        aviationwx_log('info', 'Upload endpoints refreshed', $logContext, 'app');
    }

    if ($reloadDaemon && $changed && function_exists('reloadProftpdDaemon')) {
        reloadProftpdDaemon();
    }

    return [
        'ok' => true,
        'changed' => $changed,
        'endpoints' => $endpoints,
    ];
}

/**
 * @param array<string, mixed> $previous
 * @param array<string, mixed> $current
 */
function uploadEndpointsStateChanged(array $previous, array $current): bool
{
    foreach (['hostname', 'ipv4', 'ipv6'] as $key) {
        if (($previous[$key] ?? null) !== ($current[$key] ?? null)) {
            return true;
        }
    }

    return false;
}

/**
 * Fail closed when IPv4 is required but missing. IPv6 absence is logged but non-fatal when IPv4 exists.
 *
 * @param array<string, mixed> $endpoints
 */
function validateUploadEndpointsForCapabilities(array $endpoints): ?string
{
    $caps = getUploadCapabilities();
    if ($caps['ipv4'] && ($endpoints['ipv4'] ?? null) === null) {
        return 'IPv4 upload endpoint unavailable (capability enabled)';
    }

    return null;
}

/**
 * @param array<string, mixed> $endpoints
 * @return list<string>
 */
function collectUploadEndpointWarnings(array $endpoints): array
{
    $warnings = [];
    $caps = getUploadCapabilities();
    if ($caps['ipv6'] && ($endpoints['ipv6'] ?? null) === null) {
        $warnings[] = 'IPv6 upload endpoint unavailable (capability enabled)';
    }

    return $warnings;
}

/**
 * Build ProFTPD masquerade.conf from endpoint cache (family-aware when both families active).
 *
 * @param array<string, mixed> $endpoints
 */
function buildProftpdMasqueradeConf(array $endpoints): string
{
    $caps = getUploadCapabilities();
    $ipv4 = is_string($endpoints['ipv4'] ?? null) ? $endpoints['ipv4'] : null;
    $ipv6 = is_string($endpoints['ipv6'] ?? null) ? $endpoints['ipv6'] : null;

    if ($caps['ipv4'] && $ipv4 !== null && (!$caps['ipv6'] || $ipv6 === null)) {
        return "# Generated by upload-endpoints.php - do not edit by hand\n"
            . "MasqueradeAddress               {$ipv4}\n";
    }

    if ($caps['ipv6'] && $ipv6 !== null && (!$caps['ipv4'] || $ipv4 === null)) {
        return "# Generated by upload-endpoints.php - do not edit by hand\n"
            . "MasqueradeAddress               {$ipv6}\n";
    }

    if ($caps['ipv4'] && $ipv4 !== null && $caps['ipv6'] && $ipv6 !== null) {
        return <<<EOF
# Generated by upload-endpoints.php - do not edit by hand
<IfModule mod_ifsession.c>
  <Class upload_ipv4_mapped>
    From ::ffff:0.0.0.0/96
  </Class>
  <Class upload_ipv4_direct>
    From 0.0.0.0/0
  </Class>
  <IfClass upload_ipv4_mapped>
    MasqueradeAddress               {$ipv4}
  </IfClass>
  <IfClass upload_ipv4_direct>
    MasqueradeAddress               {$ipv4}
  </IfClass>
  <IfClass !upload_ipv4_mapped>
    <IfClass !upload_ipv4_direct>
      MasqueradeAddress               {$ipv6}
    </IfClass>
  </IfClass>
</IfModule>

EOF;
    }

    return "# Generated by upload-endpoints.php - no masquerade endpoints configured\n";
}

/**
 * @param array<string, mixed> $endpoints
 */
function writeProftpdMasqueradeConf(array $endpoints, ?string $path = null): bool
{
    $path = $path ?? UPLOAD_ENDPOINTS_PROFTPD_CONF;
    $content = buildProftpdMasqueradeConf($endpoints);
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }

    $tmp = $dir . '/.' . basename($path) . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
        @unlink($tmp);

        return false;
    }

    if (!@rename($tmp, $path)) {
        @unlink($tmp);

        return false;
    }

    return true;
}

/**
 * Build TLS snippet for capability flags (plain FTP vs FTPS).
 */
function buildProftpdTlsCapabilityConf(
    string $certFile,
    string $keyFile,
    ?array $capabilities = null
): string {
    $caps = $capabilities ?? getUploadCapabilities();
    if ($caps['ftps'] !== true) {
        return <<<'EOF'
# Generated by upload-endpoints.php - FTPS disabled via upload_capabilities
<IfModule mod_tls.c>
  TLSEngine                      off
</IfModule>

EOF;
    }

    if (!is_readable($certFile) || !is_readable($keyFile)) {
        return <<<'EOF'
# Generated by upload-endpoints.php - TLS disabled until certificates are available
<IfModule mod_tls.c>
  TLSEngine                      off
</IfModule>

EOF;
    }

    $tlsRequired = $caps['plain_ftp'] === true ? 'off' : 'on';
    $cert = $certFile;
    $key = $keyFile;

    return <<<EOF
# Generated by upload-endpoints.php
<IfModule mod_tls.c>
  TLSEngine                      on
  TLSRSACertificateFile          {$cert}
  TLSRSACertificateKeyFile       {$key}
  TLSVerifyClient                off
  TLSRequired                    {$tlsRequired}
  TLSProtocol                    TLSv1.2
</IfModule>

EOF;
}

/**
 * ProFTPD listener directives from upload capability flags.
 */
function buildProftpdListenersConf(?array $capabilities = null): string
{
    $caps = $capabilities ?? getUploadCapabilities();
    $lines = ['# Generated by upload-endpoints.php - do not edit by hand'];

    if ($caps['ipv4'] && !$caps['ipv6']) {
        $lines[] = 'UseIPv6                         off';
    } elseif (!$caps['ipv4'] && $caps['ipv6']) {
        $lines[] = 'UseIPv6                         on';
        $lines[] = 'DefaultAddress                  ::';
    }

    return implode("\n", $lines) . "\n";
}

/**
 * @param array<string, mixed> $endpoints
 */
function writeProftpdListenersConf(?array $capabilities = null, ?string $path = null): bool
{
    $path = $path ?? '/etc/proftpd/conf.d/listeners.conf';
    $content = buildProftpdListenersConf($capabilities);
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }

    $tmp = $dir . '/.' . basename($path) . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
        @unlink($tmp);

        return false;
    }

    if (!@rename($tmp, $path)) {
        @unlink($tmp);

        return false;
    }

    return true;
}

/**
 * Write TLS conf from capability flags and certificate paths.
 */
function writeProftpdTlsCapabilityConf(string $certFile, string $keyFile, ?string $path = null): bool
{
    $path = $path ?? '/etc/proftpd/conf.d/tls.conf';
    $content = buildProftpdTlsCapabilityConf($certFile, $keyFile);
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }

    $tmp = $dir . '/.' . basename($path) . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
        @unlink($tmp);

        return false;
    }

    if (!@rename($tmp, $path)) {
        @unlink($tmp);

        return false;
    }

    return true;
}

/**
 * Sync all ProFTPD generated conf.d snippets from endpoint cache and capabilities.
 *
 * @return array{ok: bool, changed: bool, endpoints: array<string, mixed>, error?: string}
 */
function syncProftpdUploadDaemonConfig(bool $reloadDaemon = true): array
{
    $result = refreshUploadEndpoints(false);
    if (!$result['ok']) {
        return $result;
    }

    if (!writeProftpdListenersConf()) {
        return [
            'ok' => false,
            'changed' => $result['changed'],
            'endpoints' => $result['endpoints'],
            'error' => 'Failed to write ProFTPD listeners configuration',
        ];
    }

    $certFile = '/etc/letsencrypt/live/aviationwx.org/fullchain.pem';
    $keyFile = '/etc/letsencrypt/live/aviationwx.org/privkey.pem';
    if (!writeProftpdTlsCapabilityConf($certFile, $keyFile)) {
        return [
            'ok' => false,
            'changed' => $result['changed'],
            'endpoints' => $result['endpoints'],
            'error' => 'Failed to write ProFTPD TLS configuration',
        ];
    }

    if ($reloadDaemon && $result['changed'] && function_exists('reloadProftpdDaemon')) {
        reloadProftpdDaemon();
    }

    return $result;
}

/**
 * Whether upload endpoint refresh should use the accelerated DDNS interval.
 */
function shouldAccelerateUploadEndpointRefresh(?string $probeStatePath = null): bool
{
    if (!isDynamicDnsEnabled()) {
        return false;
    }

    $probeStatePath = $probeStatePath ?? '/var/lib/aviationwx/upload-probe.json';
    if (!is_readable($probeStatePath)) {
        return false;
    }

    $raw = file_get_contents($probeStatePath);
    if ($raw === false || trim($raw) === '') {
        return false;
    }

    try {
        $state = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return false;
    }

    if (!is_array($state)) {
        return false;
    }

    foreach (['ftps', 'sftp'] as $protocol) {
        $section = $state[$protocol] ?? null;
        if (!is_array($section)) {
            continue;
        }
        if (($section['skipped'] ?? false) === true) {
            continue;
        }
        if (($section['ok'] ?? true) === false) {
            return true;
        }
    }

    return false;
}

/**
 * Effective refresh interval for maybe-run-refresh-upload-endpoints (baseline or accelerated).
 */
function getEffectiveUploadEndpointRefreshSeconds(?string $probeStatePath = null): int
{
    $baseline = getDynamicDnsRefreshSeconds();
    if ($baseline <= 0) {
        return 0;
    }

    if (shouldAccelerateUploadEndpointRefresh($probeStatePath)) {
        return getDynamicDnsAcceleratedRefreshSeconds();
    }

    return $baseline;
}
