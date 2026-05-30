<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';

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

    #[DataProvider('nonBooleanEnabledProvider')]
    public function testIsContributionsEnabled_NonBooleanEnabled_ReturnsFalse(mixed $enabled): void
    {
        self::assertFalse(isContributionsEnabled([
            'config' => [
                'contributions' => [
                    'enabled' => $enabled,
                ],
            ],
        ]));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function nonBooleanEnabledProvider(): array
    {
        return [
            'string false' => ['false'],
            'string one' => ['1'],
            'integer one' => [1],
            'string true' => ['true'],
        ];
    }
}
