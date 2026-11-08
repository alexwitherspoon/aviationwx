<?php
/**
 * Unit tests for discover kiosk deck selection.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/discover.php';

class DiscoverDeckTest extends TestCase
{
    private array $airports = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->airports = [
            'kspb' => [
                'name' => 'Scappoose Airport',
                'icao' => 'KSPB',
            ],
            'pdx' => [
                'name' => 'Portland International Airport',
                'icao' => 'KPDX',
                'iata' => 'PDX',
            ],
            '03s' => [
                'name' => 'Sandy River Airport',
                'faa' => '03S',
            ],
            'custom-airport' => [
                'name' => 'Custom Airport',
            ],
        ];
    }

    public function testDiscoverSelectOperationalAirports_OperationalOnly(): void
    {
        $status = [
            'kspb' => 'operational',
            'pdx' => 'operational',
            '03s' => 'degraded',
            'custom-airport' => 'down',
        ];

        $deck = discoverSelectOperationalAirports($this->airports, $status);

        $ids = array_column($deck, 'id');
        $this->assertSame(['kspb', 'pdx'], $ids);
    }

    public function testDiscoverSelectOperationalAirports_MissingStatus_FailsClosed(): void
    {
        $status = ['kspb' => 'operational'];

        $deck = discoverSelectOperationalAirports($this->airports, $status);

        $this->assertSame(['kspb'], array_column($deck, 'id'));
    }

    public function testDiscoverSelectOperationalAirports_EmptyStatusMap_ReturnsEmpty(): void
    {
        $deck = discoverSelectOperationalAirports($this->airports, []);

        $this->assertSame([], $deck);
    }

    public function testDiscoverSelectOperationalAirports_PrimaryIdentifier_PrefersIcao(): void
    {
        $status = ['pdx' => 'operational'];

        $deck = discoverSelectOperationalAirports($this->airports, $status);

        $this->assertSame('KPDX', $deck[0]['primary']);
    }

    public function testDiscoverSelectOperationalAirports_PrimaryIdentifier_FallsBackToFaa(): void
    {
        $status = ['03s' => 'operational'];

        $deck = discoverSelectOperationalAirports($this->airports, $status);

        $this->assertSame('03S', $deck[0]['primary']);
    }

    public function testDiscoverSelectOperationalAirports_PrimaryIdentifier_FallsBackToId(): void
    {
        $status = ['custom-airport' => 'operational'];

        $deck = discoverSelectOperationalAirports($this->airports, $status);

        $this->assertSame('custom-airport', $deck[0]['primary']);
    }
}
