<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Assertions\Migrations;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Assert;
use Throwable;

/**
 * Runs a directory of migration sources against a *live* database connection — the
 * independent oracle that the structural {@see MigrationGraph} pin cannot be: a real
 * engine (pgsql/mysql) enforces foreign keys at DDL time, so a broken order is
 * rejected the moment a table references a not-yet-created parent.
 *
 * Two entry points, and the second is the load-bearing one:
 *  - {@see self::applyOnConnection()} proves a set applies clean, and creates something;
 *  - {@see self::brokenOrderIsRejectedOnConnection()} is the **negative control**. A
 *    green FK test proves nothing until you have watched the engine reject the broken
 *    order. If the engine *accepts* a deliberately broken order — because it does not
 *    enforce foreign keys (SQLite) — that is not a pass, it is a vacuous check, and
 *    this method fails loudly.
 *
 * Migrations run with the default connection temporarily pointed at the target, so
 * the migrations' own `Schema::create()` calls land there; the connection is dropped
 * clean before and after every run and the previous default is always restored.
 */
final class MigrationRunner
{
    /**
     * Apply every migration in $migrationsDir, in directory-sort order, against a live
     * connection from an empty database. Fails if any migration does not apply (on a
     * real engine, that includes a foreign key onto a table that is not there yet).
     *
     * @param  int|null  $expectedMigrations  guard-the-guard: pin the file count so the check
     *                                        cannot pass over an empty or relocated directory
     */
    public static function applyOnConnection(
        string $migrationsDir,
        string $connection,
        ?int $expectedMigrations = null,
    ): void {
        $files = MigrationFiles::sorted($migrationsDir);

        Assert::assertNotEmpty($files, "No migrations found in {$migrationsDir}.");

        if ($expectedMigrations !== null) {
            Assert::assertCount(
                $expectedMigrations,
                $files,
                "Expected {$expectedMigrations} migrations in {$migrationsDir} but found ".count($files)
                .'. Update the pin deliberately — do not let it drift.',
            );
        }

        try {
            self::runFiles($files, $connection, static function () use ($connection): void {
                // A set that applies without creating a single table applies "cleanly" the
                // way an empty directory does: vacuously. Assert inside the run, while the
                // schema is still live — runFiles drops it on the way out.
                Assert::assertNotEmpty(
                    self::tables($connection),
                    "The migration set created no tables on [{$connection}], so a green apply "
                    .'proves nothing about it.',
                );
            });
        } catch (QueryException $exception) {
            Assert::fail(
                "Migrations did not apply cleanly on [{$connection}]: {$exception->getMessage()}",
            );
        }
    }

    /**
     * The negative control. $reorder maps the sorted migration files to a deliberately
     * broken order; the assertion passes only if the engine **rejects** applying it.
     * If the engine accepts the broken order the check is vacuous — that fails loudly.
     *
     * @param  Closure(list<string>): iterable<string>  $reorder
     */
    public static function brokenOrderIsRejectedOnConnection(
        string $migrationsDir,
        Closure $reorder,
        string $connection,
    ): void {
        $files = MigrationFiles::sorted($migrationsDir);

        Assert::assertNotEmpty($files, "No migrations found in {$migrationsDir}.");

        $broken = self::normaliseReorder($reorder($files));

        // Guard the guard: a $reorder that drops, adds or renames files is a broken
        // control, not a broken order. It must be a permutation of the real set.
        $sortedExpected = $files;
        $sortedBroken = $broken;
        sort($sortedExpected);
        sort($sortedBroken);

        Assert::assertSame(
            $sortedExpected,
            $sortedBroken,
            'The $reorder closure must return a permutation of the migration files (the same files, reordered).',
        );

        $rejected = false;

        try {
            self::runFiles($broken, $connection);
        } catch (QueryException) {
            $rejected = true;
        }

        Assert::assertTrue(
            $rejected,
            "The [{$connection}] engine ACCEPTED a deliberately broken migration order. That makes this a "
            .'vacuous negative control: a driver that does not enforce foreign keys (e.g. sqlite) cannot prove '
            .'the order matters. Run this assertion against pgsql or mysql.',
        );
    }

    /**
     * Whether a configured connection can actually be reached. Used to gate the
     * real-engine assertions so a suite with no pgsql/mysql skips them *visibly*
     * rather than passing vacuously.
     */
    public static function connectionIsAvailable(string $connection): bool
    {
        try {
            DB::connection($connection)->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  list<string>  $files
     * @param  Closure(): void|null  $inspect  run once every file has applied, while the
     *                                         schema is still live and before it is dropped
     */
    private static function runFiles(array $files, string $connection, ?Closure $inspect = null): void
    {
        $config = app(Repository::class);
        $previousDefault = $config->get('database.default');

        $config->set('database.default', $connection);

        self::dropAllTables($connection);

        try {
            foreach ($files as $file) {
                MigrationFiles::up($file);
            }

            if ($inspect !== null) {
                $inspect();
            }
        } finally {
            self::dropAllTables($connection);
            $config->set('database.default', $previousDefault);
        }
    }

    /**
     * The connection's user tables, sorted.
     *
     * @return list<string>
     */
    private static function tables(string $connection): array
    {
        $names = [];

        foreach (Schema::connection($connection)->getTables() as $table) {
            $name = (string) $table['name'];

            // SQLite's own bookkeeping table is not schema a migration created and survives
            // dropAllTables; counting it would make an empty database look populated.
            if (str_starts_with($name, 'sqlite_')) {
                continue;
            }

            $names[] = $name;
        }

        sort($names);

        return $names;
    }

    private static function dropAllTables(string $connection): void
    {
        Schema::connection($connection)->dropAllTables();
    }

    /**
     * @param  iterable<string>  $reordered
     * @return list<string>
     */
    private static function normaliseReorder(iterable $reordered): array
    {
        $files = [];

        foreach ($reordered as $file) {
            $files[] = (string) $file;
        }

        return $files;
    }
}
