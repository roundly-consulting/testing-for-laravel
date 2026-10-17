<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\Database\MigrateProcessor;
use Orchestra\Testbench\TestCase as Orchestra;
use RoundlyConsulting\Testing\Assertions\Migrations\MigrationRunner;
use RoundlyConsulting\Testing\Concerns\LoadsProviderMigrations;
use RoundlyConsulting\Testing\Concerns\SwapsConfiguredModels;
use RoundlyConsulting\Testing\Database\DriverMatrix;
use RoundlyConsulting\Testing\Database\KeptSchema;
use RoundlyConsulting\Testing\Database\SchemaCatalogue;
use RoundlyConsulting\Testing\Database\SchemaSignature;

/**
 * Base Testbench test case for a roundly `*-for-laravel` package suite — the one
 * replacement for the near-identical `tests/TestCase.php` copied into every package.
 *
 * A package's whole TestCase becomes:
 *
 * ```php
 * final class TestCase extends PackageTestCase
 * {
 *     protected function packageProviders(): array
 *     {
 *         return [CryptoServiceProvider::class, PasskeysServiceProvider::class];
 *     }
 *
 *     protected function migrationSources(): array
 *     {
 *         return [PasskeysServiceProvider::class];
 *     }
 * }
 * ```
 *
 * This class owns the Testbench half. Nothing in the app-facing surface
 * ({@see Assert}, the {@see Expectations\Expectations expectations}) references
 * Testbench, so a plain Laravel app's own suite can use every assertion without it.
 */
abstract class PackageTestCase extends Orchestra
{
    use LoadsProviderMigrations;
    use SwapsConfiguredModels;

    /**
     * The schema this test runs on when it keeps it — null when it migrates and drops like any
     * other test.
     */
    private ?KeptSchema $keptSchema = null;

    /**
     * The migrators {@see self::migrationSources()} added, so the in-memory teardown can skip
     * exactly their rollback and no other.
     *
     * @var list<MigrateProcessor>
     */
    private array $sourceMigrators = [];

    /**
     * The package service providers under test, in the order they should register.
     *
     * @return list<class-string<ServiceProvider>>
     */
    abstract protected function packageProviders(): array;

    /**
     * Migration sources to load: provider classes (resolved to their
     * `database/migrations` directory by reflection) and/or literal directories.
     * Naming a single migration file is deliberately impossible.
     *
     * @return list<class-string<ServiceProvider>|string>
     */
    protected function migrationSources(): array
    {
        return [];
    }

    /**
     * Config applied in `defineEnvironment()`, BEFORE the providers boot — the only
     * correct place to swap a configured model or pre-seed a config key. Overriding
     * this and calling {@see SwapsConfiguredModels::swapModel()} both feed the same
     * before-boot window.
     *
     * @return array<string, mixed>
     */
    protected function configBeforeBoot(): array
    {
        return [];
    }

    /**
     * Opt in to migrating **once per worker** and resetting only the data between tests.
     *
     * By default every test migrates every source and drops every table afterwards. With many
     * sources, or on a real engine, that is most of the suite's time: tickets-for-laravel ran
     * 1047 migrates over 9 sources, ~90 % of its MySQL leg. Kept, a worker migrates once per
     * schema signature (the connection, the migration sources, the providers, the whole config
     * and {@see self::schemaSignatureExtras()}) — 12 migrates for the same suite — and after each
     * test empties the tables it wrote and puts every key counter back where the migrate left it.
     *
     * Each test still starts where a fresh migrate leaves it:
     *
     *  - no wrapping transaction, so the transaction level a test sees is its own and a forked
     *    racer sees the parent's committed rows across real connections;
     *  - empty tables, counters as migrated: the next insert gets the id a fresh migrate gives;
     *  - a test that changed the schema — a column, an index, a dropped table, a migrations row,
     *    SQLite's foreign keys left off — is caught by a catalogue check after it, and the next
     *    test migrates from scratch (what the check covers: {@see SchemaCatalogue});
     *  - migrations that write rows are detected after the first migrate: that schema keeps the
     *    drop-per-test reset, so no test loses the rows its migrations seeded;
     *  - when the worker exits it drops its tables, as the drop-per-test reset left them.
     *
     * Supported on MySQL, MariaDB, PostgreSQL and in-memory SQLite; anything else (and a suite
     * using `RefreshDatabase`) keeps the drop-per-test reset. A forked child — a racer on a real
     * engine — must end with SIGKILL, never `exit()`: it shares the parent's database sessions,
     * the kept schema's included, and a normal exit closes them.
     */
    protected function keepsMigratedSchema(): bool
    {
        return false;
    }

    /**
     * Whatever else your migrations read, for a test case that keeps its schema: the signature
     * already holds the connection, the migration sources, the providers, the config (objects by
     * class) and the schema builder's static defaults. Return what it cannot see — an env var, a
     * static flag set in `defineEnvironment()` — so a test that would migrate differently never
     * reuses another's schema.
     *
     * @return array<array-key, mixed>
     */
    protected function schemaSignatureExtras(): array
    {
        return [];
    }

    /**
     * @param  Application  $app
     * @return list<class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return $this->packageProviders();
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        DriverMatrix::configure($app);

        $config = $app->make('config');

        foreach ($this->configBeforeBoot() as $key => $value) {
            $config->set($key, $value);
        }

        $this->applyConfiguredModelSwaps($app);
    }

    /**
     * Migrate the sources — or, for a test case that {@see self::keepsMigratedSchema() keeps its
     * schema}, hand the test the worker's kept one. Override it only to add to it, calling
     * `parent::defineDatabaseMigrations()` first; anything migrated after it shows up as a schema
     * change, so a kept schema then migrates afresh for every test (correct, just not faster).
     */
    protected function defineDatabaseMigrations(): void
    {
        if ($this->keptSchema !== null) {
            // A second migrate in one test means "migrate now", from an empty database.
            $this->keptSchema = null;
            KeptSchema::discard();
            $this->purgeConnections();
        }

        $connection = $this->connectionKeepingItsSchema();

        if ($connection === null) {
            KeptSchema::release();
            $this->runMigrationSources();

            return;
        }

        $this->keptSchema = KeptSchema::keep(
            $connection,
            $this->schemaSignature($connection),
            $this->migrationsTable(),
            $this->runMigrationSources(...),
        );
    }

    private function runMigrationSources(): void
    {
        $before = count($this->cachedTestMigratorProcessors);

        $this->loadMigrationSources($this->migrationSources());

        // loadMigrationsFrom() unshifts, so the migrators just added come first.
        $added = array_slice($this->cachedTestMigratorProcessors, 0, count($this->cachedTestMigratorProcessors) - $before);

        $this->sourceMigrators = [...$this->sourceMigrators, ...$added];
    }

    private function connectionKeepingItsSchema(): ?Connection
    {
        if (! $this->keepsMigratedSchema() || static::usesRefreshDatabaseTestingConcern()) {
            return null;
        }

        $manager = $this->app?->make('db');
        $connection = $manager instanceof DatabaseManager ? $manager->connection() : null;

        return $connection instanceof Connection && KeptSchema::supports($connection) ? $connection : null;
    }

    private function schemaSignature(Connection $connection): string
    {
        $config = $this->app?->make(Repository::class);

        return SchemaSignature::of(
            $connection,
            array_map(static fn (string $source): string => self::migrationDirectoryFor($source), $this->migrationSources()),
            array_map(strval(...), array_keys($this->app?->getLoadedProviders() ?? [])),
            $config?->all() ?? [],
            $this->schemaSignatureExtras(),
        );
    }

    private function migrationsTable(): string
    {
        $table = $this->app?->make(Repository::class)->get('database.migrations');

        if (is_array($table)) {
            $table = $table['table'] ?? null;
        }

        return is_string($table) && $table !== '' ? $table : 'migrations';
    }

    /**
     * Reset the schema between tests by **dropping every table**, not by rolling the
     * migrations back — or, for a test case that {@see self::keepsMigratedSchema() keeps its
     * schema}, by emptying the tables and rewinding the key counters.
     *
     * Testbench's own teardown unwinds each migrator it cached in
     * {@see Orchestra\Testbench\Concerns\InteractsWithMigrations::loadMigrationsFrom()}
     * by running `migrate:rollback`, which calls each migration's `down()`. Roundly
     * packages **ship no `down()`** — the developer standard is explicit that a package
     * migrates forward only, because a rollback path is dead code that drifts out of sync
     * with `up()`. `Migrator::runMigration()` guards `down()` with `method_exists`, so for
     * a compliant package that rollback is a **silent no-op**: on SQLite `:memory:` it never
     * mattered (the database dies with the connection), but on a real engine the tables
     * survive and the *next* test dies on a duplicate table naming an innocent migration.
     *
     * The fix is to stop asking for a rollback at all. Dropping every table resets the same
     * state with **zero `down()`**, and is what {@see MigrationRunner} has always done.
     *
     * Deliberately *not* `RefreshDatabase`: migrating once and wrapping each test in a
     * transaction would add a transaction level, and {@see Fixtures\LockRecorder} asserts on
     * `transactionDepth` — the exact datum that condemned the deleted `LockedUpdate` helper.
     * A drop is pure DDL and opens no transaction, so the depth a test observes is its own.
     *
     * ## Why this is not gated on migrations
     *
     * It used to be. The reset returned early when `cachedTestMigratorProcessors === []`,
     * reading an empty migrator cache as "this suite is not ours to reset". That cache is
     * empty in **two** unrelated situations, and the early return could not tell them apart:
     *
     *   - a suite using `RefreshDatabase`, which migrates once and owns its own reset — and
     *     which must genuinely be left alone; and
     *   - a suite that simply **ships no migrations**, which owns nothing and is reset by
     *     nobody.
     *
     * The second is not a rare shape: `query-builder`, `metrics`, `translatable` and `crypto`
     * all ship zero migrations. For them the whole teardown was skipped, so on a real engine
     * a fixture table built in one test survived into the next (`relation "posts" already
     * exists` on test 2) and **every test's connection stayed open** — a monotonic climb that
     * ends in `FATAL: sorry, too many clients already`, blamed on whatever innocent statement
     * happened to be running when the server ran out. Invisible on `:memory:`, where the
     * database dies with the connection and nobody counts backends.
     *
     * So the gate is now the thing actually being asked — {@see usesRefreshDatabaseTestingConcern()},
     * Testbench's own discriminator, which is also what Laravel keys its self-disconnect on
     * (`RefreshDatabase` rolls back and disconnects its transacted connections itself). Having
     * no migrations is not a reason to skip the reset; owning a competing one is.
     *
     * ## In-memory SQLite skips the sources' rollback
     *
     * On `:memory:` the database dies with the connection, so the reset is free — but Testbench
     * still ran a `migrate:rollback` per source on it: no `down()` to call, only the migrations
     * rows to delete from a database about to vanish. That was ~21 % of a SQLite worker's time
     * in tickets-for-laravel. The migrators {@see self::migrationSources()} added are dropped
     * from the cache first, so it no longer runs. Nothing else changes: a migrator a test added
     * itself (`loadMigrationsFrom()`, `loadLaravelMigrations()`) still rolls back, and a
     * `RefreshDatabase` suite keeps Testbench's path untouched — its schema outlives the test.
     *
     * @internal Overrides Testbench's teardown hook, dispatched by trait basename.
     */
    protected function tearDownInteractsWithMigrations(): void
    {
        if ($this->keptSchema !== null) {
            $this->tearDownKeptSchema($this->keptSchema);

            return;
        }

        // In-memory SQLite needs no reset: the database dies with the connection. Never purge,
        // because a `:memory:` suite using RefreshDatabase keeps its schema *in* the connection.
        if ($this->usesSqliteInMemoryDatabaseConnection()) {
            if (! static::usesRefreshDatabaseTestingConcern()) {
                $this->forgetSourceMigrators();
            }

            parent::tearDownInteractsWithMigrations();

            return;
        }

        // RefreshDatabase (and LazilyRefreshDatabase) migrate once and reset per test inside a
        // transaction they roll back and disconnect themselves. Dropping their tables would
        // destroy the schema they rely on surviving. This is the one suite shape that is
        // genuinely not ours to reset.
        if (static::usesRefreshDatabaseTestingConcern()) {
            parent::tearDownInteractsWithMigrations();

            return;
        }

        // Displace the down()-based rollback before delegating: emptying the cache is what
        // makes Testbench's `foreach (...) $migrator->rollback()` a no-op. (Testbench's
        // RefreshDatabaseState reset is already excluded above — it only runs for the
        // RefreshDatabase concern, which returned.)
        $this->cachedTestMigratorProcessors = [];

        parent::tearDownInteractsWithMigrations();

        $this->dropAllTablesForReset();
        $this->purgeConnections();
    }

    /**
     * Drop the migrators {@see self::migrationSources()} added from Testbench's rollback list.
     */
    private function forgetSourceMigrators(): void
    {
        $sources = $this->sourceMigrators;

        $this->cachedTestMigratorProcessors = array_values(array_filter(
            $this->cachedTestMigratorProcessors,
            static fn (MigrateProcessor $migrator): bool => ! in_array($migrator, $sources, true),
        ));

        $this->sourceMigrators = [];
    }

    /**
     * The kept schema stays: nothing is rolled back (no roundly migration ships a `down()`),
     * the test's sessions close, and the schema is reset — or thrown away when the test changed
     * it, so the next test migrates from scratch.
     */
    private function tearDownKeptSchema(KeptSchema $schema): void
    {
        $this->keptSchema = null;
        $this->sourceMigrators = [];

        // A migrator the test ran on top of the sources must not roll back on the kept schema:
        // its tables and rows are a schema change, and the settle below discards the schema.
        $this->cachedTestMigratorProcessors = [];

        parent::tearDownInteractsWithMigrations();

        // Close the test's sessions first: an open transaction rolls back, its locks go.
        $this->purgeConnections();

        $schema->settle();
    }

    /**
     * Drop every table on the default connection — the schema reset, run whether or not this
     * suite loaded migrations. A suite with no migrations still creates tables (a fixture
     * builds one by hand), and those must not survive into the next test.
     */
    private function dropAllTablesForReset(): void
    {
        $config = $this->app?->make(Repository::class);

        if ($config === null) {
            return;
        }

        Schema::connection((string) $config->get('database.default'))->dropAllTables();
    }

    /**
     * Close every connection this test opened.
     *
     * Dropping tables resets the *schema*; it does nothing about the **PDO session**, and
     * nothing else in the stack closes it either. Testbench's teardown never disconnects, and
     * the app being flushed does not reliably collect the connection — so a real-engine suite
     * left one backend open per test and climbed until the server refused new ones. Measured
     * on a 20-test Postgres suite: 21 backends without this, flat at 2 with it, and identical
     * with and without migrations — which is what proves the leak was never about migrations.
     *
     * Purging (rather than merely disconnecting) also drops the manager's cached instance, so
     * the *next* test resolves a connection built from its own config rather than inheriting
     * this test's.
     */
    private function purgeConnections(): void
    {
        $manager = $this->app?->make('db');

        if (! $manager instanceof DatabaseManager) {
            return;
        }

        foreach (array_keys($manager->getConnections()) as $name) {
            $manager->purge((string) $name);
        }
    }

    /**
     * Whether a configured connection can actually be reached — the gate for the
     * real-engine assertions, so a run with no Postgres skips *visibly* rather than
     * passing vacuously.
     *
     * ```php
     * it('applies on postgres', function (): void {
     *     expect($dir)->toApplyOnConnection('pgsql');
     * })->skip(fn (): bool => ! test()->connectionAvailable('pgsql'), 'no postgres');
     * ```
     */
    public function connectionAvailable(string $connection): bool
    {
        return MigrationRunner::connectionIsAvailable($connection);
    }
}
