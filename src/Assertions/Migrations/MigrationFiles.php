<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Assertions\Migrations;

use Illuminate\Database\Migrations\Migration;
use PHPUnit\Framework\Assert;

/**
 * The one place a migration set is turned into a list of files, in the order the
 * migrator would run them (directory sort), and a file is turned into a Migration.
 *
 * Shared by {@see MigrationRunner} and {@see MigrationRollback} on purpose: two
 * assertions that claim to be about "the same migration set" must be reading the
 * literally same set, or a package can be green on one and untested by the other.
 */
final class MigrationFiles
{
    /**
     * Every migration file in $directory, directory-sorted — the order the migrator
     * applies them in. A missing directory fails rather than reading as "no migrations".
     *
     * @return list<string>
     */
    public static function sorted(string $directory): array
    {
        Assert::assertDirectoryExists($directory, "Migrations directory does not exist: {$directory}");

        $files = glob(rtrim($directory, '/').'/*.php') ?: [];
        sort($files);

        return $files;
    }

    /**
     * Load one migration file into its Migration instance. A file that returns anything
     * else fails by name — it is never skipped, because a silently-skipped migration is
     * the whole bug class these assertions exist for.
     */
    public static function load(string $file): Migration
    {
        $migration = require $file;

        if (! $migration instanceof Migration) {
            Assert::fail("Migration file `{$file}` did not return a runnable Migration instance.");
        }

        return $migration;
    }

    /**
     * Apply one migration file.
     *
     * The `method_exists` guard is not ceremony: `Migration` declares neither `up()` nor
     * `down()`, which is exactly the hole `Migrator::runMigration()` falls through when it
     * skips a missing `down()` in silence. Here the absence fails by name instead.
     */
    public static function up(string $file): void
    {
        $migration = self::load($file);

        if (! method_exists($migration, 'up')) {
            Assert::fail(basename($file).' declares no up() — it is not a runnable migration.');
        }

        $migration->up();
    }
}
