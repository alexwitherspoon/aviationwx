<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';

/**
 * Tests for contributions feature gate (isContributionsEnabled).
 */
class ContributionsConfigTest extends TestCase
{
    public function testIsContributionsEnabled_KeyAbsent_ReturnsFalse(): void
    {
        self::assertFalse(isContributionsEnabled(['config' => []]));
    }

    public function testIsContributionsEnabled_EnabledFalse_ReturnsFalse(): void
    {
        self::assertFalse(isContributionsEnabled([
            'config' => [
                'contributions' => [
                    'enabled' => false,
                ],
            ],
        ]));
    }

    public function testIsContributionsEnabled_EnabledTrue_ReturnsTrue(): void
    {
        self::assertTrue(isContributionsEnabled([
            'config' => [
                'contributions' => [
                    'enabled' => true,
                ],
            ],
        ]));
    }

    public function testIsContributionsEnabled_NullParam_UsesLoadConfigWhenUnset(): void
    {
        self::assertFalse(isContributionsEnabled(null));
    }
}
