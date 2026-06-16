<?php
/**
 * Unit tests for Public API webcam list metadata formatting.
 */

use PHPUnit\Framework\TestCase;

class PublicApiWebcamMetadataTest extends TestCase
{
    private static function loadFormatWebcamMetadata(): void
    {
        static $loaded = false;
        if (!$loaded) {
            require_once __DIR__ . '/../../lib/config.php';
            require_once __DIR__ . '/../../api/v1/webcams.php';
            $loaded = true;
        }
    }

    public function testFormatWebcamMetadata_IncludesHeadingWhenConfigured(): void
    {
        self::loadFormatWebcamMetadata();

        $airport = [
            'enabled' => true,
            'maintenance' => false,
        ];
        $webcam = [
            'name' => 'East Camera',
            'approximate_heading' => 90,
        ];

        $formatted = formatWebcamMetadata('kspb', 0, $webcam, $airport);

        $this->assertArrayHasKey('approximate_heading', $formatted);
        $this->assertArrayNotHasKey('approximate_heading_reference', $formatted);
        $this->assertSame(90, $formatted['approximate_heading']);
    }

    public function testFormatWebcamMetadata_NullHeadingWhenOmitted(): void
    {
        self::loadFormatWebcamMetadata();

        $airport = [
            'enabled' => true,
            'maintenance' => true,
        ];
        $webcam = [
            'name' => 'Maintenance Camera',
            'url' => 'https://example.com/cam.jpg',
        ];

        $formatted = formatWebcamMetadata('pdx', 0, $webcam, $airport);

        $this->assertArrayHasKey('approximate_heading', $formatted);
        $this->assertArrayNotHasKey('approximate_heading_reference', $formatted);
        $this->assertNull($formatted['approximate_heading']);
    }

    public function testFormatWebcamMetadata_AlwaysIncludesHeadingKey(): void
    {
        self::loadFormatWebcamMetadata();

        $airport = ['enabled' => true, 'maintenance' => false];
        $webcam = ['name' => 'Camera'];

        $formatted = formatWebcamMetadata('kspb', 0, $webcam, $airport);

        $this->assertArrayHasKey('approximate_heading', $formatted);
        $this->assertArrayNotHasKey('approximate_heading_reference', $formatted);
        $this->assertArrayHasKey('image_url', $formatted);
        $this->assertSame('/v1/airports/kspb/webcams/0/image', $formatted['image_url']);
    }
}
