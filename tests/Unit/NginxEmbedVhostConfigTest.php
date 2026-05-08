<?php
/**
 * Regression tests for docker/nginx.conf embed.aviationwx.org routing.
 *
 * Ensures Public API paths on the embed host are not served via off-host 301,
 * which breaks cross-origin fetch() from third-party iframe origins (CORS).
 *
 * @package AviationWX\Tests\Unit
 */

require_once dirname(__DIR__, 2) . '/lib/nginx-embed-vhost-verify.php';

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
     * Embed vhost must proxy Public API v1 through router.php on-host (no off-host 301).
     */
    public function testEmbedVhostPublicApiV1Routing(): void
    {
        $path = self::nginxConfPath();
        $this->assertFileExists($path, 'docker/nginx.conf must exist');
        $content = file_get_contents($path);
        $this->assertIsString($content);
        $block = nginx_extract_embed_aviationwx_server_block($content);
        $errors = nginx_verify_embed_server_block_public_api_v1($block);
        $this->assertSame(
            [],
            $errors,
            $errors !== [] ? implode('; ', $errors) : ''
        );
    }
}
