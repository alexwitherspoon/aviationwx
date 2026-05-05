<?php
/**
 * Unit tests: partner logo cache paths align with cache-paths helpers; subprocess checks cover CI without
 * depending on outbound HTTP for example.com URLs (mocked when shouldMockExternalServices() is true).
 */

use PHPUnit\Framework\TestCase;

class PartnerLogoCachePathsTest extends TestCase
{
    /**
     * @return array{exit: int, output: string}
     */
    private function runPartnerLogoSubprocess(string $scriptBody): array
    {
        $root = dirname(__DIR__, 2);
        $fixture = $root . '/tests/Fixtures/airports.json.test';
        $tmp = sys_get_temp_dir() . '/aviationwx_partner_logo_sub_' . bin2hex(random_bytes(8)) . '.php';
        $full = $this->subprocessBootstrapPreamble() . $scriptBody;
        file_put_contents($tmp, $full);

        $env = [
            'PARTNER_LOGO_TEST_ROOT' => $root,
            'PARTNER_LOGO_TEST_CONFIG_PATH' => $fixture,
        ];
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' 2>&1';
        $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptor, $pipes, null, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);
        @unlink($tmp);

        return ['exit' => $exitCode, 'output' => $out . $err];
    }

    private function subprocessBootstrapPreamble(): string
    {
        $cacheId = bin2hex(random_bytes(8));
        $cacheBootstrap = <<<BOOT
if (!defined('CACHE_BASE_DIR')) {
    define('CACHE_BASE_DIR', sys_get_temp_dir() . '/aviationwx_partner_logo_sub_{$cacheId}');
}
@mkdir(CACHE_BASE_DIR, 0755, true);

BOOT;

        return <<<'PHP'
<?php
putenv('APP_ENV=testing');
putenv('CONFIG_PATH=' . getenv('PARTNER_LOGO_TEST_CONFIG_PATH'));
$_ENV['APP_ENV'] = 'testing';
$_ENV['CONFIG_PATH'] = getenv('PARTNER_LOGO_TEST_CONFIG_PATH');
if (!defined('AVIATIONWX_LOG_DIR')) {
    define('AVIATIONWX_LOG_DIR', sys_get_temp_dir() . '/aviationwx_partner_logo_sub');
}
@mkdir(AVIATIONWX_LOG_DIR, 0755, true);
@touch(AVIATIONWX_LOG_DIR . '/app.log');
@touch(AVIATIONWX_LOG_DIR . '/user.log');

PHP
            . $cacheBootstrap . <<<'PHP'
$root = getenv('PARTNER_LOGO_TEST_ROOT');

PHP;
    }

    /**
     * Remote logo file path must match getPartnerLogoCachedFilePath(hash, ext) for the same URL.
     * Runs in a subprocess with CACHE_BASE_DIR under sys_get_temp_dir() so the unit suite does not
     * create cache/partners under the repository (cleanTestCache does not remove partner files).
     */
    public function testGetPartnerLogoCacheFile_MatchesCachedFilePathHelper(): void
    {
        $body = <<<'PHP'
require $root . '/lib/constants.php';
require $root . '/lib/logger.php';
require $root . '/lib/partner-logo-cache.php';
$pngUrl = 'https://example.com/sponsor/logo.png';
if (getPartnerLogoCachedFilePath(md5($pngUrl), 'jpg') !== getPartnerLogoCacheFile($pngUrl)) {
    fwrite(STDERR, "example.com URL with .png path must map to .jpg cache file (mock placeholder is JPEG)\n");
    exit(1);
}
$jpegUrl = 'https://example.com/sponsor/photo.jpeg';
if (getPartnerLogoCachedFilePath(md5($jpegUrl), 'jpg') !== getPartnerLogoCacheFile($jpegUrl)) {
    fwrite(STDERR, "jpeg path mismatch\n");
    exit(1);
}
echo "ok\n";
PHP;
        $result = $this->runPartnerLogoSubprocess($body);
        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertStringContainsString('ok', $result['output']);
    }

    /**
     * Partner cache directory must stay aligned with CACHE_PARTNERS_DIR (subprocess; see path test).
     */
    public function testGetPartnerLogoCacheDir_EqualsCachePartnersDir(): void
    {
        $body = <<<'PHP'
require $root . '/lib/constants.php';
require $root . '/lib/logger.php';
require $root . '/lib/partner-logo-cache.php';
if (CACHE_PARTNERS_DIR !== getPartnerLogoCacheDir()) {
    fwrite(STDERR, "dir mismatch\n");
    exit(1);
}
echo "ok\n";
PHP;
        $result = $this->runPartnerLogoSubprocess($body);
        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertStringContainsString('ok', $result['output']);
    }

    /**
     * Fresh PHP process loading partner-logo-cache (as api/partner-logo.php does) must exit cleanly.
     */
    public function testSubprocessLoadsPartnerLogoCacheOnlyNoFatal(): void
    {
        $body = <<<'PHP'
require $root . '/lib/constants.php';
require $root . '/lib/logger.php';
require $root . '/lib/partner-logo-cache.php';
echo "ok\n";
PHP;
        $result = $this->runPartnerLogoSubprocess($body);
        $this->assertSame(0, $result['exit'], 'subprocess exit code (output=' . $result['output'] . ')');
        $this->assertStringContainsString('ok', $result['output']);
    }

    /**
     * api/partner-logo.php with a remote url must not fatal when loadConfig pulls cache-paths (regression guard).
     */
    public function testSubprocessPartnerLogoEndpointRemoteUrlNoFatal(): void
    {
        $body = <<<'PHP'
chdir($root);
$_GET['url'] = 'https://example.com/ci-partner-logo-probe.png';
require $root . '/api/partner-logo.php';
PHP;
        $result = $this->runPartnerLogoSubprocess($body);
        $this->assertStringNotContainsString('Cannot redeclare function', $result['output']);
        $this->assertStringNotContainsString('Fatal error', $result['output']);
        $this->assertSame(0, $result['exit'], 'subprocess exit code (output length ' . strlen($result['output']) . ')');
    }

    /**
     * Missing url still bootstraps partner-logo stack (config + cache-paths); must not fatal.
     */
    public function testSubprocessPartnerLogoEndpointMissingUrlNoFatal(): void
    {
        $body = <<<'PHP'
chdir($root);
$_GET = [];
require $root . '/api/partner-logo.php';
PHP;
        $result = $this->runPartnerLogoSubprocess($body);
        $this->assertStringNotContainsString('Cannot redeclare function', $result['output']);
        $this->assertStringNotContainsString('Fatal error', $result['output']);
        $this->assertSame(0, $result['exit'], 'subprocess exit code (output=' . $result['output'] . ')');
        $this->assertStringContainsString('Missing url', $result['output']);
    }

    /**
     * Explicit load order cache-paths then partner-logo-cache (require_once must not collide on names).
     */
    public function testSubprocessLoadCachePathsThenPartnerLogoCacheNoFatal(): void
    {
        $body = <<<'PHP'
chdir($root);
require $root . '/lib/constants.php';
require $root . '/lib/logger.php';
require $root . '/lib/cache-paths.php';
require $root . '/lib/partner-logo-cache.php';
echo "ok\n";
PHP;
        $result = $this->runPartnerLogoSubprocess($body);
        $this->assertStringNotContainsString('Cannot redeclare function', $result['output']);
        $this->assertStringNotContainsString('Fatal error', $result['output']);
        $this->assertSame(0, $result['exit'], 'subprocess exit code (output=' . $result['output'] . ')');
        $this->assertStringContainsString('ok', $result['output']);
    }
}
