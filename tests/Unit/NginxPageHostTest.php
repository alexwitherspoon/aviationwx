<?php

use PHPUnit\Framework\TestCase;

class NginxPageHostTest extends TestCase
{
    public function testPhpPagesReceivePublicHostAndScheme(): void
    {
        $config = file_get_contents(__DIR__ . '/../../docker/nginx.conf');
        // Find the generic PHP location block and verify it forwards the
        // public Host and scheme so page scripts behind nginx see their
        // canonical identity.
        $pos = strpos($config, 'location ~* \.php$');
        $this->assertNotFalse($pos, 'Generic PHP location not found');
        $block = substr($config, $pos, 500);
        $this->assertStringContainsString('proxy_set_header Host $host;', $block);
        $this->assertStringContainsString('proxy_set_header X-Real-IP $remote_addr;', $block);
        $this->assertStringContainsString('proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;', $block);
        $this->assertStringContainsString('proxy_set_header X-Forwarded-Proto $scheme;', $block);
    }
}
