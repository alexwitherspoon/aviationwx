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
        $this->assertSame('aviationwx', $formatted['operator']);
    }

    public function testFormatWebcamMetadata_UsesExplicitOperator(): void
    {
        self::loadFormatWebcamMetadata();

        $airport = [
            'enabled' => true,
            'maintenance' => false,
        ];
        $webcam = [
            'name' => 'DOT Camera',
            'approximate_heading' => 180,
            'operator' => 'wsdot',
        ];

        $formatted = formatWebcamMetadata('kspb', 0, $webcam, $airport);
        $this->assertSame('wsdot', $formatted['operator']);
    }

    public function testListWebcamsFilter_KeepsConfigIndexesWhenOperatorSet(): void
    {
        self::loadFormatWebcamMetadata();

        $airport = [
            'enabled' => true,
            'maintenance' => false,
            'webcams' => [
                ['name' => 'Ours', 'approximate_heading' => 0],
                ['name' => 'DOT', 'operator' => 'wsdot', 'approximate_heading' => 180],
            ],
        ];

        $formatted = listFormattedWebcamsForAirport('kspb', $airport, 'wsdot');

        $this->assertCount(1, $formatted);
        $this->assertSame(1, $formatted[0]['index']);
        $this->assertSame('wsdot', $formatted[0]['operator']);
        $this->assertSame('/v1/airports/kspb/webcams/1/image', $formatted[0]['image_url']);
        $this->assertSame('/v1/airports/kspb/webcams/1/image', $formatted[0]['images'][0]['url']);
    }

    public function testFormatWebcamMetadata_AbsoluteUrls_UseCanonicalV1Base(): void
    {
        self::loadFormatWebcamMetadata();

        $airport = ['enabled' => true, 'maintenance' => false];
        $webcam = ['name' => 'North', 'approximate_heading' => 0];
        $formatted = formatWebcamMetadata('kspb', 0, $webcam, $airport, true);
        $base = getCanonicalPublicApiV1BaseUrl();

        $this->assertSame($base . '/airports/kspb/webcams/0/image', $formatted['image_url']);
        $this->assertSame($formatted['image_url'], $formatted['images'][0]['url']);
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

    public function testFormatWebcamMetadata_IncludesHistoryWhenEnabled(): void
    {
        self::loadFormatWebcamMetadata();

        $airport = ['enabled' => true, 'maintenance' => false];
        $webcam = ['name' => 'Camera', 'approximate_heading' => 90];

        $formatted = formatWebcamMetadata('kspb', 0, $webcam, $airport);

        $this->assertArrayHasKey('history_enabled', $formatted);
        $this->assertArrayHasKey('history_url', $formatted);
        $this->assertTrue($formatted['history_enabled']);
        $this->assertSame('/v1/airports/kspb/webcams/0/history', $formatted['history_url']);
    }

    public function testFormatWebcamMetadata_OmitsHistoryWhenDisabled(): void
    {
        self::loadFormatWebcamMetadata();

        // pdx fixture sets webcam_history_retention_hours to 0 (history disabled via config).
        $airport = ['enabled' => true, 'maintenance' => false];
        $webcam = ['name' => 'Camera'];

        $formatted = formatWebcamMetadata('pdx', 0, $webcam, $airport);

        $this->assertArrayNotHasKey('history_enabled', $formatted);
        $this->assertArrayNotHasKey('history_url', $formatted);
    }

    public function testFormatWebcamMetadata_AlwaysIncludesHeadingKey(): void
    {
        self::loadFormatWebcamMetadata();

        $airport = ['enabled' => true, 'maintenance' => false];
        $webcam = [
            'name' => 'Camera',
            'approximate_heading' => 318,
        ];

        $formatted = formatWebcamMetadata('kspb', 0, $webcam, $airport);

        $this->assertArrayHasKey('approximate_heading', $formatted);
        $this->assertArrayNotHasKey('approximate_heading_reference', $formatted);
        $this->assertSame(318, $formatted['approximate_heading']);
        $this->assertArrayHasKey('history_url', $formatted);
        $this->assertArrayHasKey('image_url', $formatted);
        $this->assertSame('/v1/airports/kspb/webcams/0/image', $formatted['image_url']);
    }

    public function testFormatWebcamMetadata_NullHeadingForNonIntegerValue(): void
    {
        self::loadFormatWebcamMetadata();

        $airport = ['enabled' => true, 'maintenance' => false];
        $webcam = [
            'name' => 'Camera',
            'approximate_heading' => 90.5,
        ];

        $formatted = formatWebcamMetadata('kspb', 0, $webcam, $airport);

        $this->assertArrayHasKey('approximate_heading', $formatted);
        $this->assertNull($formatted['approximate_heading']);
    }

    public function testFormatWebcamMetadata_NullHeadingForNumericString(): void
    {
        self::loadFormatWebcamMetadata();

        $airport = ['enabled' => true, 'maintenance' => false];
        $webcam = [
            'name' => 'Camera',
            'approximate_heading' => '180',
        ];

        $formatted = formatWebcamMetadata('kspb', 0, $webcam, $airport);

        $this->assertArrayHasKey('approximate_heading', $formatted);
        $this->assertNull($formatted['approximate_heading']);
    }
}
