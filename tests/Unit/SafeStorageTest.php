<?php
/**
 * Safe Storage (localStorage SecurityError) Tests
 *
 * Verifies that pages using localStorage include the safeStorageGet/safeStorageSet
 * pattern to handle SecurityError in iOS Private Browsing and disabled storage.
 *
 * @see pages/airports.php
 * @see pages/airport.php
 */

namespace AviationWX\Tests;

use PHPUnit\Framework\TestCase;

class SafeStorageTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectRoot = dirname(__DIR__, 2);
    }

    /**
     * safeStorageGet pattern: try/catch around localStorage.getItem returning null on error
     */
    public function testAirportsPage_ContainsSafeStorageGetPattern(): void
    {
        $content = file_get_contents($this->projectRoot . '/pages/airports.php');
        $this->assertNotEmpty($content, 'airports.php should be readable');

        $this->assertStringContainsString('safeStorageGet', $content, 'Should define safeStorageGet');
        $this->assertStringContainsString('localStorage.getItem', $content, 'Should use localStorage');
        $this->assertMatchesRegularExpression(
            '/try\s*\{\s*return\s+localStorage\.getItem\([^)]+\)\s*;\s*\}\s*catch/',
            $content,
            'Should wrap getItem in try/catch'
        );
    }

    /**
     * safeStorageSet pattern: try/catch around localStorage.setItem
     */
    public function testAirportsPage_ContainsSafeStorageSetPattern(): void
    {
        $content = file_get_contents($this->projectRoot . '/pages/airports.php');
        $this->assertStringContainsString('safeStorageSet', $content, 'Should define safeStorageSet');
        $this->assertMatchesRegularExpression(
            '/try\s*\{\s*localStorage\.setItem\([^)]+\)\s*;\s*\}\s*catch/',
            $content,
            'Should wrap setItem in try/catch'
        );
    }

    /**
     * Airport page preferences block contains safe storage helpers
     */
    public function testAirportPage_ContainsSafeStorageHelpers(): void
    {
        $content = file_get_contents($this->projectRoot . '/pages/airport.php');
        $this->assertNotEmpty($content, 'airport.php should be readable');

        $this->assertStringContainsString('safeStorageGet', $content, 'Should define safeStorageGet');
        $this->assertStringContainsString('safeStorageSet', $content, 'Should define safeStorageSet');
        $this->assertStringContainsString('safeSessionStorageGet', $content, 'Should define safeSessionStorageGet');
    }

    /**
     * Airport page uses safeStorageGet for preference reads (not raw localStorage.getItem)
     */
    public function testAirportPage_PreferenceGettersUseSafeStorage(): void
    {
        $content = file_get_contents($this->projectRoot . '/pages/airport.php');

        $this->assertStringContainsString(
            'safeStorageGet(\'aviationwx_time_format\')',
            $content,
            'getTimeFormat should use safeStorageGet'
        );
        $this->assertStringContainsString(
            'safeStorageGet(\'aviationwx_theme\')',
            $content,
            'getThemePreference should use safeStorageGet'
        );
    }

    /**
     * Airport page version check block has its own safe storage helpers (IIFE scope)
     */
    public function testAirportPage_VersionBlockHasSafeStorage(): void
    {
        $content = file_get_contents($this->projectRoot . '/pages/airport.php');

        $this->assertMatchesRegularExpression(
            '/SecurityError.*Private Browsing/',
            $content,
            'Should document SecurityError/Private Browsing in comment'
        );
    }
}
