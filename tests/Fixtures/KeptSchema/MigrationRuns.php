<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\KeptSchema;

/**
 * How often each fixture schema's migrations ran in this process — the observable difference
 * between a test that migrated and one that was handed a kept schema.
 */
final class MigrationRuns
{
    /** @var array<string, int> */
    private static array $runs = [];

    public static function record(string $schema): void
    {
        self::$runs[$schema] = self::of($schema) + 1;
    }

    public static function of(string $schema): int
    {
        return self::$runs[$schema] ?? 0;
    }
}
