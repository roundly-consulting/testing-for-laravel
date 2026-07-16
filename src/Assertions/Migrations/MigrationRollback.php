<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Assertions\Migrations;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Assert;
use ReflectionMethod;

/**
 * Pins that a migration set can be rolled back — that a host app can uninstall the
 * package it installed.
 *
 * Two halves, and the split is the point:
 *
 *  1. **Structural** ({@see self::assertEveryMigrationDeclaresDown()}) — every migration
 *     declares a non-empty `down()`. Needs **no engine**, so it bites on every leg
 *     including a SQLite-only local run, and it fails **by filename**.
 *  2. **Behavioural** ({@see self::assertRollsBackOnConnection()}) — the set applies and
 *     then unwinds to an empty schema on a live connection. Engine-gated; the caller
 *     skips it visibly when the connection is unreachable.
 *
 * Why the structural half is the load-bearing one: `Migrator::runMigration()` guards
 * `down()` with `method_exists`, so a **missing `down()` is not an error — it is a silent
 * no-op**. On SQLite `:memory:` that never mattered, because the database dies with the
 * connection. On a real engine per-test rollback is the only state reset, so the table
 * *survives* and the **next** test dies on a duplicate-table error pointing at the
 * **wrong migration**. Turning this package's own pgsql leg real surfaced 26 failures
 * from that one cause (bug #1); no package in the fleet had ever tested it.
 *
 * The structural half is what turns that into one red naming the one migration at fault,
 * on the leg every developer already runs.
 */
final class MigrationRollback
{
    /**
     * @param  int  $expectedMigrations  guard-the-guard: pin the file count so the check cannot
     *                                   pass over an empty or relocated directory
     * @param  string|null  $connection  when given, also prove the set really unwinds on that
     *                                   live connection
     */
    public static function assert(string $migrationsDir, int $expectedMigrations, ?string $connection = null): void
    {
        $files = MigrationFiles::sorted($migrationsDir);

        Assert::assertNotEmpty(
            $files,
            "No migrations found in {$migrationsDir}. A rollback pin over an empty set is exactly the "
            .'vacuous green this assertion exists to kill.',
        );

        Assert::assertCount(
            $expectedMigrations,
            $files,
            "Expected {$expectedMigrations} migrations in {$migrationsDir} but found ".count($files)
            .'. Update the pin deliberately — do not let it drift.',
        );

        // Structural first, always: it names the migration at fault. Run the engine half
        // first and a missing down() resurfaces as a confusing leftover-table error.
        self::assertEveryMigrationDeclaresDown($files);

        if ($connection !== null) {
            self::assertRollsBackOnConnection($files, $connection);
        }
    }

    /**
     * @param  list<string>  $files
     */
    private static function assertEveryMigrationDeclaresDown(array $files): void
    {
        foreach ($files as $file) {
            $migration = MigrationFiles::load($file);
            $name = basename($file);

            // Deliberately the framework's own predicate: this is the exact condition
            // Migrator::runMigration() tests before it silently skips the rollback.
            Assert::assertTrue(
                method_exists($migration, 'down'),
                "{$name} declares no down(). Migrator::runMigration() guards down() with method_exists, "
                .'so rolling this migration back is a SILENT no-op: the table survives the rollback and '
                .'a later test dies on a duplicate table, naming the wrong migration. A package that '
                .'cannot roll back is a package a host cannot uninstall.',
            );

            Assert::assertFalse(
                self::bodyIsEmpty(new ReflectionMethod($migration, 'down')),
                "{$name}: down() has an empty body. That is the missing-down() no-op with extra steps — "
                .'it silences this assertion without restoring anything. It must reverse up().',
            );
        }
    }

    /**
     * Whether a method's body contains no statements.
     *
     * Tokenized rather than regexed on purpose: a docblock or a `// TODO: drop the table`
     * must not read as a body. "The comment satisfied the check" is a bug this package
     * was extracted from, not a hypothetical.
     */
    private static function bodyIsEmpty(ReflectionMethod $method): bool
    {
        $file = $method->getFileName();
        $start = $method->getStartLine();
        $end = $method->getEndLine();

        if ($file === false || $start === false || $end === false) {
            // An internal or eval'd method — unreadable, so claim nothing about it rather
            // than inventing a failure.
            return false;
        }

        $lines = file($file);

        if ($lines === false) {
            return false;
        }

        $source = implode('', array_slice($lines, $start - 1, $end - $start + 1));

        $depth = 0;
        $statements = 0;

        foreach (token_get_all('<?php '.$source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)) {
                    continue;
                }

                if ($depth > 0) {
                    $statements++;
                }

                continue;
            }

            if ($token === '{') {
                $depth++;

                continue;
            }

            if ($token === '}') {
                $depth--;

                continue;
            }

            if ($depth > 0) {
                $statements++;
            }
        }

        return $statements === 0;
    }

    /**
     * Apply the set, then unwind it, on a live connection: the definitive proof that each
     * `down()` really reverses its `up()` rather than merely existing.
     *
     * @param  list<string>  $files
     */
    private static function assertRollsBackOnConnection(array $files, string $connection): void
    {
        $config = app(Repository::class);
        $previousDefault = $config->get('database.default');

        // The migrations' own Schema::create() calls target the default connection, so
        // point it at the target for the duration and always restore it.
        $config->set('database.default', $connection);

        self::dropAllTables($connection);

        try {
            foreach ($files as $file) {
                try {
                    MigrationFiles::up($file);
                } catch (QueryException $exception) {
                    Assert::fail(basename($file)." did not apply on [{$connection}]: {$exception->getMessage()}");
                }
            }

            Assert::assertNotEmpty(
                self::tables($connection),
                "The migration set created no tables on [{$connection}], so there is nothing to roll back "
                .'and a green rollback proves nothing.',
            );

            // Reverse order, exactly as the migrator unwinds a batch: a down() must drop
            // children before the parents they reference, or a real engine refuses.
            foreach (array_reverse($files) as $file) {
                $migration = MigrationFiles::load($file);

                // Already proven by the structural half; narrows the call for the analyser.
                if (method_exists($migration, 'down')) {
                    try {
                        $migration->down();
                    } catch (QueryException $exception) {
                        Assert::fail(
                            basename($file).": down() failed on [{$connection}]: {$exception->getMessage()}",
                        );
                    }
                }
            }

            $left = self::tables($connection);

            Assert::assertSame(
                [],
                $left,
                'Rolling the set back left '.count($left)." table(s) behind on [{$connection}]: "
                .implode(', ', $left).'. A down() that does not reverse its up() leaves the host with '
                .'schema it cannot remove — and leaves the next test to fail on a duplicate table.',
            );
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

            // SQLite's own bookkeeping table is not schema this package created and
            // survives dropAllTables; counting it would fail every green SQLite run.
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
}
