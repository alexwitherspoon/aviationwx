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
    public function testNginxVerifyOpsServerBlock_DockerNginxConf_ReturnsNoErrors(): void
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
     * Verifier must require X-Robots-Tag always on location /, not only on success responses.
     */
    public function testNginxVerifyOpsServerBlock_MissingRobotsHeaderAlwaysOnLocationRoot_ReturnsError(): void
    {
        $block = <<<'NGINX'
server {
    location = /robots.txt {
        add_header X-Robots-Tag "noindex, nofollow" always;
        return 200 "User-agent: *\nDisallow: /\n";
    }
    location / {
        add_header X-Robots-Tag "noindex, nofollow";
        proxy_pass http://127.0.0.1:8091;
    }
}
NGINX;
        $errors = nginx_verify_ops_server_block($block);
        $this->assertNotSame([], $errors);
        $this->assertStringContainsString('always', implode('; ', $errors));
    }

    /**
     * Verifier must require X-Robots-Tag on location /, not only /robots.txt.
     */
    public function testNginxVerifyOpsServerBlock_MissingRobotsHeaderOnLocationRoot_ReturnsError(): void
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
     * Verifier must require Disallow: / in location = /robots.txt, not only the location marker.
     */
    public function testNginxVerifyOpsServerBlock_RobotsTxtAllowsCrawl_ReturnsError(): void
    {
        $block = <<<'NGINX'
server {
    location = /robots.txt {
        add_header X-Robots-Tag "noindex, nofollow" always;
        return 200 "User-agent: *\nAllow: /\n";
    }
    location / {
        add_header X-Robots-Tag "noindex, nofollow" always;
        proxy_pass http://127.0.0.1:8091;
    }
}
NGINX;
        $errors = nginx_verify_ops_server_block($block);
        $this->assertNotSame([], $errors);
        $this->assertStringContainsString('Disallow: /', implode('; ', $errors));
    }

    /**
     * HTTP ACME server_name list must include ops alongside api and embed.
     */
    public function testHttpAcmeServerName_IncludesOpsHost_MatchesOpsPattern(): void
    {
        $path = self::nginxConfPath();
        $this->assertFileExists($path, 'docker/nginx.conf must exist');
        $content = file_get_contents($path);
        $this->assertIsString($content);
        $httpBlock = nginx_extract_server_block_containing(
            $content,
            'location /.well-known/acme-challenge/'
        );
        $this->assertNotSame('', $httpBlock, 'HTTP ACME server block must exist');
        $this->assertMatchesRegularExpression('/listen\s+80;/', $httpBlock);
        $this->assertMatchesRegularExpression(
            '/server_name\b[^;]*\bops\.aviationwx\.org\b/',
            $httpBlock,
            'HTTP ACME server_name must include ops.aviationwx.org'
        );
    }

    /**
     * Admin endpoints must be denied before the generic PHP location,
     * and admin proxy routes must not exist.
     */
    public function testNginxDenyBlock_IsBeforePhpLocation_AndNoAdminRoutes(): void
    {
        $path = self::nginxConfPath();
        $this->assertFileExists($path, 'docker/nginx.conf must exist');
        $content = (string) file_get_contents($path);

        // Match the actual deny location directive (not explanatory comments)
        $denyMatch = preg_match(
            '/location\s+~\s+\/(test-local\.php|.*?diagnostics\.php|.*?clear-cache\.php|.*?metrics\.php).*?\{\s*deny all;/s',
            $content,
            $denyMatches
        );
        $this->assertSame(1, $denyMatch, 'deny location for admin endpoints must use a location directive');

        // Generic PHP location must exist
        $this->assertStringContainsString('location ~* \.php$ {', $content);

        // Deny block must appear before generic PHP location
        $denyPos = strpos($content, $denyMatches[0]);
        $phpPos = strpos($content, 'location ~* \.php$');
        $this->assertNotFalse($denyPos, 'deny location must exist');
        $this->assertNotFalse($phpPos, 'generic PHP location must exist');
        $this->assertLessThan($phpPos, $denyPos, 'deny location must come before generic PHP location');

        // No admin proxy routes or symlinks to deleted directories
        $this->assertStringNotContainsString('proxy_pass http://localhost:8080/admin', $content);
        $this->assertStringNotContainsString('alias /admin', $content);
        $this->assertStringNotContainsString('root /admin', $content);

        // Admin directory must be denied
        $this->assertStringContainsString('admin', $content, 'admin path must be referenced in deny rules');
        $adminDenyMatch = preg_match('/location\s+~\s+\S*admin.*\{\s*deny all;/s', $content);
        $this->assertSame(1, $adminDenyMatch, 'admin directory must have a deny location');
    }
}
