<?php
/**
 * Regression tests for docker/nginx.conf ops.aviationwx.org routing.
 *
 * Ensures the operator console subdomain proxies to the ops stack on loopback :8091
 * and is not handled by the airport wildcard or dashboard port 8080.
 *
 * @package AviationWX\Tests\Unit
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/nginx-ops-vhost-verify.php';

class NginxOpsVhostConfigTest extends TestCase
{
    /**
     * Path to nginx config relative to repository root.
     *
     * @return string Absolute filesystem path
     */
    private static function nginxConfPath(): string
    {
        return dirname(__DIR__, 2) . '/docker/nginx.conf';
    }

    /**
     * Ops vhost must proxy to 127.0.0.1:8091 without main-site auth or CSP.
     */
    public function testOpsVhostProxiesToOpsStackPort(): void
    {
        $path = self::nginxConfPath();
        $this->assertFileExists($path, 'docker/nginx.conf must exist');
        $content = file_get_contents($path);
        $this->assertIsString($content);
        $block = nginx_extract_ops_aviationwx_server_block($content);
        $errors = nginx_verify_ops_server_block($block, $content);
        $this->assertSame(
            [],
            $errors,
            $errors !== [] ? implode('; ', $errors) : ''
        );
    }

    /**
     * Verifier must require X-Robots-Tag on location /, not only /robots.txt.
     */
    public function testOpsVerifierRequiresRobotsHeaderOnLocationRoot(): void
    {
        $block = <<<'NGINX'
server {
    location = /robots.txt {
        add_header X-Robots-Tag "noindex, nofollow" always;
        return 200 "User-agent: *\nDisallow: /\n";
    }
    location / {
        proxy_pass http://127.0.0.1:8091;
    }
}
NGINX;
        $errors = nginx_verify_ops_server_block($block);
        $this->assertNotSame([], $errors);
        $this->assertStringContainsString('location / must set X-Robots-Tag', implode('; ', $errors));
    }

    /**
     * HTTP ACME server_name list must include ops alongside api and embed.
     */
    public function testHttpServerNameListIncludesOps(): void
    {
        $path = self::nginxConfPath();
        $content = file_get_contents($path);
        $this->assertIsString($content);
        $this->assertMatchesRegularExpression(
            '/server_name\b[^;]*\bops\.aviationwx\.org\b/',
            $content
        );
    }
}
