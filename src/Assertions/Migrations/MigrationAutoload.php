<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Assertions\Migrations;

use Illuminate\Database\Migrations\Migrator;
use PHPUnit\Framework\Assert;
use RoundlyConsulting\Testing\Support\ProviderMigrationDirectory;

/**
 * Pins the fleet's publish-only migration policy: a package's `database/migrations`
 * directory must NOT be registered with the migrator (no `loadMigrationsFrom()`).
 *
 * Auto-loading migrations *and* publishing them timestamped means the host runs both
 * copies — a duplicate-table failure that hit three packages. The directory is
 * resolved from the provider by reflection, so the default (no explicit path) works
 * for any roundly package.
 *
 * The provider must be registered in the running app: one that never booted cannot have
 * called `loadMigrationsFrom()`, and passing it would be vacuous.
 */
final class MigrationAutoload
{
    public static function assert(string $providerClass, ?string $migrationsDir = null): void
    {
        // A provider that never booted registered nothing, so "does not auto-load" would be
        // trivially true of it — the pin must run against the provider the app really boots.
        Assert::assertNotNull(
            app()->getProvider($providerClass),
            "{$providerClass} is not registered in this app, so it cannot have registered any migration paths — "
            .'this check would pass without testing anything. Add it to packageProviders() in the TestCase this '
            .'test is bound to.',
        );

        $directory = $migrationsDir ?? ProviderMigrationDirectory::locate($providerClass);

        Assert::assertDirectoryExists($directory, "Migrations directory does not exist: {$directory}");

        $real = realpath($directory);

        if ($real === false) {
            Assert::fail("Could not resolve the real path of {$directory}.");
        }

        $registered = [];

        foreach (app(Migrator::class)->paths() as $path) {
            $resolved = realpath($path);

            if ($resolved !== false) {
                $registered[] = $resolved;
            }
        }

        Assert::assertNotContains(
            $real,
            $registered,
            "{$providerClass} auto-loads its migrations from {$directory}, but the fleet policy is publish-only. "
            .'Remove the loadMigrationsFrom() call and publish the migrations under a timestamped tag instead.',
        );
    }
}
