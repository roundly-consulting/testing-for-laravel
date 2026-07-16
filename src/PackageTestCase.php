<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use RoundlyConsulting\Testing\Assertions\Migrations\MigrationRunner;
use RoundlyConsulting\Testing\Concerns\LoadsProviderMigrations;
use RoundlyConsulting\Testing\Concerns\SwapsConfiguredModels;
use RoundlyConsulting\Testing\Database\DriverMatrix;

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

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationSources($this->migrationSources());
    }

    /**
     * Reset the schema between tests by **dropping every table**, not by rolling the
     * migrations back.
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
     * @internal Overrides Testbench's teardown hook, dispatched by trait basename.
     */
    protected function tearDownInteractsWithMigrations(): void
    {
        // In-memory SQLite needs nothing: the database dies with the connection. Leave that
        // path exactly as Testbench wrote it.
        if ($this->usesSqliteInMemoryDatabaseConnection()) {
            parent::tearDownInteractsWithMigrations();

            return;
        }

        // Nothing loaded through loadMigrationsFrom() (a suite with no migrations, or one
        // using RefreshDatabase, which takes Testbench's other branch) — leave it alone.
        if ($this->cachedTestMigratorProcessors === []) {
            parent::tearDownInteractsWithMigrations();

            return;
        }

        // Displace the down()-based rollback before delegating: emptying the cache is what
        // makes Testbench's `foreach (...) $migrator->rollback()` a no-op, while leaving the
        // rest of its teardown (the RefreshDatabaseState reset) intact.
        $this->cachedTestMigratorProcessors = [];

        parent::tearDownInteractsWithMigrations();

        $config = $this->app?->make(Repository::class);

        if ($config === null) {
            return;
        }

        Schema::connection((string) $config->get('database.default'))->dropAllTables();
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
