<?php
/**
 * Regression tests for docker/nginx.conf embed.aviationwx.org routing.
 *
 * Ensures Public API paths on the embed host are not served via off-host 301,
 * which breaks cross-origin fetch() from third-party iframe origins (CORS).
 *
 * @package AviationWX\Tests\Unit
 */

use PHPUnit\Framework\TestCase;

class NginxEmbedVhostConfigTest extends TestCase
{
    /**
     * Path to nginx config relative to repository root.
     */
    private static function nginxConfPath(): string
    {
        return dirname(__DIR__, 2) . '/docker/nginx.conf';
    }

    /**
     * Extract the server { ... } block for embed.aviationwx.org from raw nginx config.
     *
     * @param string $content Full nginx.conf contents
     * @return string Embed server block including outer braces
     */
    public static function extractEmbedServerBlock(string $content): string
    {
        $marker = 'server_name embed.aviationwx.org;';
        $pos = strpos($content, $marker);
        if ($pos === false) {
            return '';
        }
        // Opening brace of this server block: search backward from marker for "server {"
        $slice = substr($content, 0, $pos);
        $serverKw = strrpos($slice, 'server {');
        if ($serverKw === false) {
            return '';
        }
        $prefix = substr($content, $serverKw, 12);
        $braceRel = strpos($prefix, '{');
        if ($braceRel === false) {
            return '';
        }
        $braceOpen = $serverKw + $braceRel;
        // $braceOpen points at '{'
        $depth = 0;
        $len = strlen($content);
        for ($i = $braceOpen; $i < $len; $i++) {
            $c = $content[$i];
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($content, $serverKw, $i - $serverKw + 1);
                }
            }
        }

        return '';
    }

    /**
     * Embed vhost must not redirect /api/v1 to api.aviationwx.org (breaks CORS on first hop).
     */
    public function testEmbedVhostDoesNotUseOffHost301ForApiV1(): void
    {
        $path = self::nginxConfPath();
        $this->assertFileExists($path, 'docker/nginx.conf must exist');
        $content = file_get_contents($path);
        $this->assertIsString($content);
        $block = self::extractEmbedServerBlock($content);
        $this->assertNotSame('', $block, 'Could not extract embed.aviationwx.org server block');

        $bad = 'return 301 https://api.aviationwx.org/v1';
        $this->assertStringNotContainsString(
            $bad,
            $block,
            'embed.aviationwx.org must not use off-host 301 for /api/v1 (use internal rewrite + location /v1/)'
        );
    }

    /**
     * Embed vhost must proxy API v1 through router.php like api.aviationwx.org.
     */
    public function testEmbedVhostContainsInternalV1Routing(): void
    {
        $path = self::nginxConfPath();
        $content = file_get_contents($path);
        $this->assertIsString($content);
        $block = self::extractEmbedServerBlock($content);
        $this->assertNotSame('', $block);

        $this->assertStringContainsString(
            'location /v1/',
            $block,
            'embed server block should include location /v1/ for Public API routing'
        );
        $this->assertStringContainsString(
            '/api/v1/router.php',
            $block,
            'embed server block should rewrite /v1/ to api/v1/router.php'
        );
    }
}
