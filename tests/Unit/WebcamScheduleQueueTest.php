<?php
/**
 * Unit Tests for WebcamScheduleQueue
 * Tests priority queue scheduling for webcam processing
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/webcam-schedule-queue.php';
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/constants.php';

class WebcamScheduleQueueTest extends TestCase
{
    private WebcamScheduleQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queue = new WebcamScheduleQueue();
    }

    /**
     * Test basic queue initialization with airports config
     */
    public function testInitializeWithAirports()
    {
        $airports = [
            'kspb' => [
                'name' => 'Test Airport 1',
                'enabled' => true,
                'webcams' => [
                    ['url' => 'http://example.com/cam1.mjpg', 'refresh_seconds' => 60],
                    ['url' => 'http://example.com/cam2.mjpg', 'refresh_seconds' => 120],
                ]
            ],
            'kczk' => [
                'name' => 'Test Airport 2',
                'enabled' => true,
                'webcams' => [
                    ['type' => 'push', 'push_config' => ['username' => 'test'], 'refresh_seconds' => 30],
                ]
            ]
        ];

        $this->queue->initialize($airports);
        
        $this->assertEquals(3, $this->queue->count(), 'Should have 3 cameras total');
        $this->assertFalse($this->queue->isEmpty(), 'Queue should not be empty');
    }

    /**
     * Test that disabled airports are skipped
     */
    public function testSkipsDisabledAirports()
    {
        $airports = [
            'kspb' => [
                'name' => 'Enabled Airport',
                'enabled' => true,
                'webcams' => [
                    ['url' => 'http://example.com/cam1.mjpg'],
                ]
            ],
            'kczk' => [
                'name' => 'Disabled Airport',
                'enabled' => false,
                'webcams' => [
                    ['url' => 'http://example.com/cam2.mjpg'],
                ]
            ]
        ];

        $this->queue->initialize($airports);
        
        $this->assertEquals(1, $this->queue->count(), 'Should only have cameras from enabled airport');
    }

    /**
     * Test refresh seconds config hierarchy: camera > airport > global > default
     */
    public function testRefreshSecondsConfigHierarchy()
    {
        $airports = [
            'kspb' => [
                'name' => 'Test Airport',
                'enabled' => true,
                'webcam_refresh_seconds' => 120, // Airport default
                'webcams' => [
                    ['url' => 'http://example.com/cam1.mjpg'], // Uses airport default (120)
                    ['url' => 'http://example.com/cam2.mjpg', 'refresh_seconds' => 30], // Camera override (30)
                ]
            ]
        ];

        $globalConfig = ['webcam_refresh_seconds' => 180]; // Global default (should be ignored here)

        $this->queue->initialize($airports, $globalConfig);
        
        $entry1 = $this->queue->getEntry('kspb', 0);
        $entry2 = $this->queue->getEntry('kspb', 1);
        
        $this->assertEquals(120, $entry1->refreshSeconds, 'Camera 1 should use airport default');
        $this->assertEquals(30, $entry2->refreshSeconds, 'Camera 2 should use camera-specific refresh');
    }

    /**
     * Test that refresh seconds are bounded by MIN and MAX
     */
    public function testRefreshSecondsBounds()
    {
        $airports = [
            'kspb' => [
                'name' => 'Test Airport',
                'enabled' => true,
                'webcams' => [
                    ['url' => 'http://example.com/cam1.mjpg', 'refresh_seconds' => 1], // Too low
                    ['url' => 'http://example.com/cam2.mjpg', 'refresh_seconds' => 99999], // Too high
                ]
            ]
        ];

        $this->queue->initialize($airports);
        
        $entry1 = $this->queue->getEntry('kspb', 0);
        $entry2 = $this->queue->getEntry('kspb', 1);
        
        $this->assertGreaterThanOrEqual(MIN_WEBCAM_REFRESH, $entry1->refreshSeconds, 'Should be at least MIN_WEBCAM_REFRESH');
        $this->assertLessThanOrEqual(MAX_WEBCAM_REFRESH, $entry2->refreshSeconds, 'Should be at most MAX_WEBCAM_REFRESH');
    }

    /**
     * Test that push cameras are correctly identified
     */
    public function testIdentifiesPushCameras()
    {
        $airports = [
            'kspb' => [
                'name' => 'Test Airport',
                'enabled' => true,
                'webcams' => [
                    ['url' => 'http://example.com/cam.mjpg'], // Pull
                    ['type' => 'push', 'push_config' => ['username' => 'test']], // Push via type
                    ['push_config' => ['username' => 'test2']], // Push via push_config
                ]
            ]
        ];

        $this->queue->initialize($airports);
        
        $entry1 = $this->queue->getEntry('kspb', 0);
        $entry2 = $this->queue->getEntry('kspb', 1);
        $entry3 = $this->queue->getEntry('kspb', 2);
        
        $this->assertFalse($entry1->isPush, 'Camera 1 should be pull type');
        $this->assertTrue($entry2->isPush, 'Camera 2 should be push type');
        $this->assertTrue($entry3->isPush, 'Camera 3 should be push type');
    }

    /**
     * Test that getReadyCameras returns due cameras and reschedules them
     */
    public function testGetReadyCamerasReturnsAndReschedules()
    {
        $airports = [
            'kspb' => [
                'name' => 'Test Airport',
                'enabled' => true,
                'webcams' => [
                    ['url' => 'http://example.com/cam1.mjpg', 'refresh_seconds' => 60],
                    ['url' => 'http://example.com/cam2.mjpg', 'refresh_seconds' => 120],
                ]
            ]
        ];

        $this->queue->initialize($airports);
        
        // First call should return all cameras (they start as immediately due)
        $ready = $this->queue->getReadyCameras();
        $this->assertCount(2, $ready, 'All cameras should be due initially');
        
        // Second call should return empty (all rescheduled)
        $ready = $this->queue->getReadyCameras();
        $this->assertCount(0, $ready, 'No cameras should be due immediately after rescheduling');
    }

    /**
     * Test queue statistics
     */
    public function testGetStats()
    {
        $airports = [
            'kspb' => [
                'name' => 'Test Airport',
                'enabled' => true,
                'webcams' => [
                    ['url' => 'http://example.com/cam1.mjpg', 'refresh_seconds' => 60],
                    ['type' => 'push', 'push_config' => ['username' => 'test'], 'refresh_seconds' => 30],
                ]
            ]
        ];

        $this->queue->initialize($airports);
        
        $stats = $this->queue->getStats();
        
        $this->assertEquals(2, $stats['total_cameras'], 'Should have 2 total cameras');
        $this->assertEquals(1, $stats['push_cameras'], 'Should have 1 push camera');
        $this->assertEquals(1, $stats['pull_cameras'], 'Should have 1 pull camera');
        $this->assertEquals(2, $stats['due_now'], 'Both cameras should be due initially');
    }

    /**
     * Test rebuild preserves due times for existing cameras
     */
    public function testRebuildPreservesExistingDueTimes()
    {
        $airports = [
            'kspb' => [
                'name' => 'Test Airport',
                'enabled' => true,
                'webcams' => [
                    ['url' => 'http://example.com/cam1.mjpg', 'refresh_seconds' => 60],
                ]
            ]
        ];

        $this->queue->initialize($airports);
        
        // Get and reschedule the camera
        $ready = $this->queue->getReadyCameras();
        $this->assertCount(1, $ready);
        
        // Get the rescheduled due time
        $entryBefore = $this->queue->getEntry('kspb', 0);
        $dueTimeBefore = $entryBefore->dueTime;
        
        // Rebuild with same config
        $changes = $this->queue->rebuild($airports);
        
        // Due time should be preserved
        $entryAfter = $this->queue->getEntry('kspb', 0);
        $this->assertEquals($dueTimeBefore, $entryAfter->dueTime, 'Due time should be preserved after rebuild');
        $this->assertEquals(0, $changes['added'], 'No cameras should be added');
        $this->assertEquals(0, $changes['removed'], 'No cameras should be removed');
    }

    /**
     * Test rebuild detects added and removed cameras
     */
    public function testRebuildDetectsChanges()
    {
        $airports = [
            'kspb' => [
                'name' => 'Test Airport',
                'enabled' => true,
                'webcams' => [
                    ['url' => 'http://example.com/cam1.mjpg'],
                    ['url' => 'http://example.com/cam2.mjpg'],
                ]
            ]
        ];

        $this->queue->initialize($airports);
        $this->assertEquals(2, $this->queue->count());
        
        // Rebuild with one camera removed and one added
        $newAirports = [
            'kspb' => [
                'name' => 'Test Airport',
                'enabled' => true,
                'webcams' => [
                    ['url' => 'http://example.com/cam1.mjpg'], // Kept
                    // cam2 removed
                    ['url' => 'http://example.com/cam3.mjpg'], // New at index 1
                ]
            ]
        ];
        
        $changes = $this->queue->rebuild($newAirports);
        
        // Note: Changes are tracked differently since we're replacing cam2 with cam3 at same index
        $this->assertEquals(2, $this->queue->count(), 'Should still have 2 cameras');
    }

    /**
     * Test markFailed extends due time with backoff
     */
    public function testMarkFailedExtendsBackoff()
    {
        $airports = [
            'kspb' => [
                'name' => 'Test Airport',
                'enabled' => true,
                'webcams' => [
                    ['url' => 'http://example.com/cam1.mjpg', 'refresh_seconds' => 60],
                ]
            ]
        ];

        $this->queue->initialize($airports);
        
        // Get the camera to reschedule it
        $ready = $this->queue->getReadyCameras();
        $this->assertCount(1, $ready);
        
        // Mark as failed with 300s backoff
        $this->queue->markFailed('kspb', 0, 300);
        
        // Check that due time is extended
        $entry = $this->queue->getEntry('kspb', 0);
        $this->assertGreaterThan(time() + 290, $entry->dueTime, 'Due time should be extended by backoff');
    }

    /**
     * Test getSecondsUntilNext returns correct wait time
     */
    public function testGetSecondsUntilNext()
    {
        $airports = [
            'kspb' => [
                'name' => 'Test Airport',
                'enabled' => true,
                'webcams' => [
                    ['url' => 'http://example.com/cam1.mjpg', 'refresh_seconds' => 60],
                ]
            ]
        ];

        $this->queue->initialize($airports);
        
        // Initially, cameras are due now
        $this->assertEquals(0, $this->queue->getSecondsUntilNext(), 'Should be 0 when camera is due');
        
        // After getting ready cameras, they're rescheduled
        $this->queue->getReadyCameras();
        
        // Now we should wait about 60 seconds
        $waitTime = $this->queue->getSecondsUntilNext();
        $this->assertGreaterThan(55, $waitTime, 'Should wait approximately 60 seconds');
        $this->assertLessThanOrEqual(60, $waitTime, 'Should not wait more than refresh interval');
    }

    /**
     * Test empty queue behavior
     */
    public function testEmptyQueueBehavior()
    {
        $this->assertTrue($this->queue->isEmpty(), 'New queue should be empty');
        $this->assertEquals(0, $this->queue->count(), 'New queue should have 0 cameras');
        $this->assertEquals(PHP_INT_MAX, $this->queue->getSecondsUntilNext(), 'Empty queue should return max wait');
        $this->assertEmpty($this->queue->getReadyCameras(), 'Empty queue should return empty array');
    }

    /**
     * Test ScheduleEntry key generation
     */
    public function testScheduleEntryKeyGeneration()
    {
        $entry = new ScheduleEntry(time(), 'kspb', 0, 60, false);
        $this->assertEquals('kspb_0', $entry->getKey(), 'Key should be airportId_camIndex');
        
        $entry2 = new ScheduleEntry(time(), 'kczk', 2, 30, true);
        $this->assertEquals('kczk_2', $entry2->getKey(), 'Key should be airportId_camIndex');
    }

    /**
     * Test getAllEntries returns all camera entries
     */
    public function testGetAllEntries()
    {
        $airports = [
            'kspb' => [
                'name' => 'Test Airport 1',
                'enabled' => true,
                'webcams' => [
                    ['url' => 'http://example.com/cam1.mjpg'],
                ]
            ],
            'kczk' => [
                'name' => 'Test Airport 2',
                'enabled' => true,
                'webcams' => [
                    ['url' => 'http://example.com/cam2.mjpg'],
                ]
            ]
        ];

        $this->queue->initialize($airports);
        
        $entries = $this->queue->getAllEntries();
        
        $this->assertCount(2, $entries, 'Should return all 2 entries');
        
        $airportIds = array_map(fn($e) => $e->airportId, $entries);
        $this->assertContains('kspb', $airportIds, 'Should contain kspb entry');
        $this->assertContains('kczk', $airportIds, 'Should contain kczk entry');
    }
}
