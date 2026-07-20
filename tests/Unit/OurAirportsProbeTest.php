<?php

/**
 * Unit tests for OurAirports probe result resolution.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/ourairports/probe.php';

class OurAirportsProbeTest extends TestCase
{
    public function testResolveProbeResultMarksMissingFileChanged(): void
    {
        $this->assertSame('changed', ourAirportsResolveProbeResult(true, 'a', 'a', true));
    }

    public function testResolveProbeResultDetectsEtagChange(): void
    {
        $this->assertSame('changed', ourAirportsResolveProbeResult(true, 'old', 'new', false));
    }

    public function testResolveProbeResultUnchangedWhenEtagsMatch(): void
    {
        $this->assertSame('unchanged', ourAirportsResolveProbeResult(true, 'same', 'same', false));
    }

    public function testResolveProbeResultChangedWhenNoChangeSignals(): void
    {
        $this->assertSame('changed', ourAirportsResolveProbeResult(true, 'stored', null, false, null, null));
    }

    public function testResolveProbeResultUnchangedWhenLastModifiedMatchesWithoutEtag(): void
    {
        $lastModified = 'Sun, 19 Jul 2026 01:53:58 GMT';

        $this->assertSame(
            'unchanged',
            ourAirportsResolveProbeResult(true, 'stored', null, false, $lastModified, $lastModified)
        );
    }

    public function testResolveProbeResultChangedWhenLastModifiedDiffersWithoutEtag(): void
    {
        $this->assertSame(
            'changed',
            ourAirportsResolveProbeResult(
                true,
                'stored',
                null,
                false,
                'Sat, 18 Jul 2026 01:53:58 GMT',
                'Sun, 19 Jul 2026 01:53:58 GMT'
            )
        );
    }

    public function testResolveProbeResultErrorWhenHeadFails(): void
    {
        $this->assertSame('error', ourAirportsResolveProbeResult(false, null, 'etag', true));
    }
}
