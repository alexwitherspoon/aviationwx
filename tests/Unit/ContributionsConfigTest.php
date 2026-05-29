<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';

/**
 * Tests for contributions feature gate (isContributionsEnabled).
 */
class ContributionsConfigTest extends TestCase
{
    public function testDisabledWhenKeyAbsent(): void
    {
        self::assertFalse(isContributionsEnabled(['config' => []]));
    }

    public function testDisabledWhenEnabledFalse(): void
    {
        self::assertFalse(isContributionsEnabled([
            'config' => [
                'contributions' => [
                    'enabled' => false,
                ],
            ],
        ]));
    }

    public function testEnabledWhenEnabledTrue(): void
    {
        self::assertTrue(isContributionsEnabled([
            'config' => [
                'contributions' => [
                    'enabled' => true,
                ],
            ],
        ]));
    }

    public function testNullConfigReturnsFalse(): void
    {
        self::assertFalse(isContributionsEnabled(null));
    }
}
