<?php
/**
 * Unit tests for GET /v1/weathercam/bulk catalog composition and config-SHA cache.
 */

use PHPUnit\Framework\TestCase;

class PublicApiWeathercamBulkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../../lib/weather/utils.php';
        require_once __DIR__ . '/../../api/v1/weathercam-bulk.php';
        resetWeathercamBulkCatalogCache();
    }

    protected function tearDown(): void
    {
        resetWeathercamBulkCatalogCache();
        parent::tearDown();
    }

    /**
     * @param list<array<string, mixed>> $airports
     * @return array<string, mixed>|null
     */
    private function findAirport(array $airports, string $id): ?array
    {
        foreach ($airports as $airport) {
            if (($airport['id'] ?? '') === $id) {
                return $airport;
            }
        }
        return null;
    }

    public function testBuildWeathercamBulkAirports_OmitsWeatherSourceOnlyMatch(): void
    {
        $airports = [
            'kspb' => [
                'name' => 'Scappoose',
                'lat' => 45.77,
                'lon' => -122.86,
                'timezone' => 'UTC',
                'webcams' => [
                    ['name' => 'North'],
                    ['name' => 'DOT', 'operator' => 'wsdot'],
                ],
                'weather_sources' => [
                    ['type' => 'metar', 'station_id' => 'KSPB'],
                ],
            ],
            '03s' => [
                'name' => 'Weather only',
                'lat' => 45.0,
                'lon' => -122.0,
                'timezone' => 'UTC',
                'webcams' => [],
                'weather_sources' => [
                    ['type' => 'metar', 'station_id' => 'KSPB'],
                ],
            ],
        ];

        $faa = buildWeathercamBulkAirports($airports, 'faa');
        $this->assertNull($this->findAirport($faa, 'kspb'));
        $this->assertNull($this->findAirport($faa, '03s'));

        $aviationwx = buildWeathercamBulkAirports($airports, 'aviationwx');
        $kspb = $this->findAirport($aviationwx, 'kspb');
        $this->assertNotNull($kspb);
        $this->assertCount(1, $kspb['webcams']);
        $this->assertSame(0, $kspb['webcams'][0]['index']);
        $this->assertSame(1, $kspb['webcam_count']);
        $this->assertNull($this->findAirport($aviationwx, '03s'));
    }

    public function testBuildWeathercamBulkAirports_SkipsNonArrayAirportRows(): void
    {
        $catalog = buildWeathercamBulkAirports(
            [
                'kspb' => 'not-an-airport',
                'kcma' => [
                    'name' => 'Camarillo',
                    'timezone' => 'UTC',
                    'webcams' => [
                        ['name' => 'West'],
                    ],
                ],
            ],
            null
        );

        $this->assertNull($this->findAirport($catalog, 'kspb'));
        $this->assertNotNull($this->findAirport($catalog, 'kcma'));
    }

    public function testBuildWeathercamBulkAirports_SortsByAirportId(): void
    {
        $airports = [
            's40' => [
                'name' => 'Later',
                'timezone' => 'UTC',
                'webcams' => [['name' => 'A']],
            ],
            'kspb' => [
                'name' => 'Earlier',
                'timezone' => 'UTC',
                'webcams' => [['name' => 'B']],
            ],
        ];

        $catalog = buildWeathercamBulkAirports($airports, null);
        $this->assertSame(['kspb', 's40'], array_column($catalog, 'id'));
    }

    public function testFilterWeathercamBulkAirportsByOperator_DoesNotMutateCachedRows(): void
    {
        $cached = [
            [
                'id' => 'kspb',
                'webcam_count' => 2,
                'webcams' => [
                    ['index' => 0, 'operator' => 'aviationwx'],
                    ['index' => 1, 'operator' => 'wsdot'],
                ],
            ],
        ];

        $aviationwx = filterWeathercamBulkAirportsByOperator($cached, 'aviationwx');
        $this->assertCount(1, $aviationwx[0]['webcams']);
        $this->assertSame(1, $aviationwx[0]['webcam_count']);
        $this->assertCount(2, $cached[0]['webcams']);
        $this->assertSame(2, $cached[0]['webcam_count']);

        $again = filterWeathercamBulkAirportsByOperator($cached, null);
        $this->assertCount(2, $again[0]['webcams']);
    }

    public function testRecallWeathercamBulkCatalog_MatchingSha_ReturnsAirports(): void
    {
        $sha = getConfigFileSha256();
        $this->assertNotNull($sha);
        $payload = [['id' => 'kspb']];
        rememberWeathercamBulkCatalog($payload, $sha);
        $this->assertSame($payload, recallWeathercamBulkCatalog());
    }

    public function testRecallWeathercamBulkCatalog_MismatchedSha_ReturnsNull(): void
    {
        rememberWeathercamBulkCatalog([['id' => 'stale']], 'not-the-current-config-sha');
        $this->assertNull(recallWeathercamBulkCatalog());
    }

    public function testGetWeathercamBulkAirports_UsesCatalogCachedForConfigSha(): void
    {
        $sha = getConfigFileSha256();
        $this->assertNotNull($sha);
        rememberWeathercamBulkCatalog([], $sha);
        $this->assertSame([], getWeathercamBulkAirports(null));

        resetWeathercamBulkCatalogCache();
        $rebuilt = getWeathercamBulkAirports(null);
        $this->assertNotSame([], $rebuilt);
        $this->assertNotNull($this->findAirport($rebuilt, 'kspb'));
    }

    public function testGetWeathercamBulkAirports_OperatorSliceDoesNotCorruptFullCache(): void
    {
        $aviationwx = getWeathercamBulkAirports('aviationwx');
        $kspbFiltered = $this->findAirport($aviationwx, 'kspb');
        $this->assertNotNull($kspbFiltered);
        $this->assertCount(1, $kspbFiltered['webcams']);
        $this->assertSame('aviationwx', $kspbFiltered['webcams'][0]['operator']);

        $all = getWeathercamBulkAirports(null);
        $kspbAll = $this->findAirport($all, 'kspb');
        $this->assertNotNull($kspbAll);
        $this->assertCount(2, $kspbAll['webcams']);
    }

    public function testGetWeathercamBulkAirports_FixtureOmitsFaaBecauseNoFaaWeathercam(): void
    {
        $this->assertTrue(airportMatchesOperator(
            getPublicApiAirport('kspb') ?? [],
            'faa'
        ));
        $this->assertNull($this->findAirport(getWeathercamBulkAirports('faa'), 'kspb'));
        $this->assertNull($this->findAirport(getWeathercamBulkAirports('faa'), '03s'));
    }

    public function testGetWeathercamBulkAirports_AbsoluteImageUrlsAndConfiguredVariants(): void
    {
        $base = getCanonicalPublicApiV1BaseUrl();
        $kspb = $this->findAirport(getWeathercamBulkAirports('aviationwx'), 'kspb');
        $this->assertNotNull($kspb);
        $cam = $kspb['webcams'][0];
        $this->assertSame($base . '/airports/kspb/webcams/0/image', $cam['image_url']);
        $this->assertStringStartsNotWith('/v1', $cam['image_url']);
        $this->assertSame(0, substr_count($cam['image_url'], '/v1/v1/'));

        $this->assertNotEmpty($cam['images']);
        $this->assertSame('original', $cam['images'][0]['variant']);
        $this->assertNull($cam['images'][0]['height']);
        $this->assertSame('jpg', $cam['images'][0]['format']);
        $this->assertSame($cam['image_url'], $cam['images'][0]['url']);

        $heights = [];
        foreach ($cam['images'] as $image) {
            $this->assertStringStartsWith($base . '/airports/kspb/webcams/0/image', $image['url']);
            if ($image['variant'] !== 'original') {
                $heights[] = $image['height'];
                $this->assertSame((string) $image['height'], $image['variant']);
                $this->assertStringContainsString('size=' . $image['height'], $image['url']);
                if ($image['format'] === 'jpg') {
                    $this->assertStringNotContainsString('fmt=', $image['url']);
                } else {
                    $this->assertStringContainsString('fmt=' . $image['format'], $image['url']);
                }
            }
        }
        $this->assertContains(1080, $heights);
        $this->assertContains(720, $heights);
        $this->assertContains(360, $heights);
    }

    public function testOpenApiWeathercamBulk_IsCatalogPathWithWeathercamOperator(): void
    {
        $spec = json_decode(
            (string) file_get_contents(__DIR__ . '/../../api/docs/openapi.json'),
            true
        );
        $this->assertIsArray($spec);
        $path = $spec['paths']['/weathercam/bulk']['get'] ?? null;
        $this->assertIsArray($path);

        $names = [];
        foreach ($path['parameters'] ?? [] as $param) {
            if (isset($param['$ref'])) {
                $this->assertSame('#/components/parameters/WeathercamOperator', $param['$ref']);
                $names[] = 'operator';
                continue;
            }
            if (isset($param['name'])) {
                $names[] = $param['name'];
            }
        }
        $this->assertContains('operator', $names);
        $this->assertNotContains('has_webcams', $names);
        $this->assertNotContains('has_weather', $names);
        $this->assertNotContains('airports', $names);

        $description = $spec['components']['parameters']['WeathercamOperator']['description'] ?? '';
        $this->assertStringContainsString('weathercam', strtolower($description));
        $this->assertStringContainsString('weather sources do not', strtolower($description));
    }
}
