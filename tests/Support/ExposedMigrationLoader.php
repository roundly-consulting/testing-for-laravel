<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Support;

use RoundlyConsulting\Testing\Concerns\LoadsProviderMigrations;

/**
 * Exposes the protected static resolver on {@see LoadsProviderMigrations} so its
 * branches (provider class, literal directory, and both failure paths) can be
 * unit-tested without a Testbench boot.
 */
final class ExposedMigrationLoader
{
    use LoadsProviderMigrations;

    public static function directoryFor(string $source): string
    {
        return self::migrationDirectoryFor($source);
    }
}
