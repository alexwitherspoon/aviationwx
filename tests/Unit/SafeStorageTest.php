<?php
/**
 * Safe Storage (localStorage SecurityError) Tests
 *
 * Verifies that pages using localStorage include the safeStorageGet/safeStorageSet
 * pattern to handle SecurityError in iOS Private Browsing and disabled storage.
 *
 * @see pages/airports.php (inline preferences script)
 * @see public/js/airport-dashboard.js (airport dashboard preferences; extracted from pages/airport.php)
 * @see lib/version.php (version-check IIFE embedded in pages/airport.php)
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

    private function readProjectFile(string $relativePath): string
    {
        $content = file_get_contents($this->projectRoot . '/' . $relativePath);
        $this->assertNotFalse($content, $relativePath . ' should be readable');

        return $content;
    }

    /**
     * safeStorageGet pattern: try/catch around localStorage.getItem returning null on error
     */
    public function testAirportsPage_ContainsSafeStorageGetPattern(): void
    {
        $content = $this->readProjectFile('pages/airports.php');

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
        $content = $this->readProjectFile('pages/airports.php');
        $this->assertStringContainsString('safeStorageSet', $content, 'Should define safeStorageSet');
        $this->assertMatchesRegularExpression(
            '/try\s*\{\s*localStorage\.setItem\([^)]+\)\s*;\s*\}\s*catch/',
            $content,
            'Should wrap setItem in try/catch'
        );
    }

    /**
     * Airport dashboard preferences live in the extracted JS bundle.
     */
    public function testAirportDashboardJs_ContainsSafeStorageHelpers(): void
    {
        $content = $this->readProjectFile('public/js/airport-dashboard.js');

        $this->assertStringContainsString('safeStorageGet', $content, 'Should define safeStorageGet');
        $this->assertStringContainsString('safeStorageSet', $content, 'Should define safeStorageSet');
        $this->assertStringContainsString('safeSessionStorageGet', $content, 'Should define safeSessionStorageGet');
    }

    /**
     * Airport dashboard preference getters use safeStorageGet (not raw localStorage.getItem).
     */
    public function testAirportDashboardJs_PreferenceGettersUseSafeStorage(): void
    {
        $content = $this->readProjectFile('public/js/airport-dashboard.js');

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
     * Version-check IIFE (rendered from lib/version.php) documents Private Browsing handling.
     */
    public function testVersionPhp_VersionBlockHasSafeStorage(): void
    {
        $content = $this->readProjectFile('lib/version.php');

        $this->assertMatchesRegularExpression(
            '/SecurityError.*Private Browsing/',
            $content,
            'Should document SecurityError/Private Browsing in comment'
        );
    }
}
