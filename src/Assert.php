<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Testing\Assertions\AboutSecrets;
use RoundlyConsulting\Testing\Assertions\ConfigContract\ConfigContract;
use RoundlyConsulting\Testing\Assertions\Migrations\MigrationAutoload;
use RoundlyConsulting\Testing\Assertions\Migrations\MigrationGraph;
use RoundlyConsulting\Testing\Assertions\Migrations\MigrationPublish;
use RoundlyConsulting\Testing\Assertions\Migrations\MigrationRunner;
use RoundlyConsulting\Testing\Assertions\ModelSwap;

/**
 * Static entry points for every assertion in this package.
 *
 * The Pest expectations in {@see Expectations\Expectations} are the documented,
 * canonical API — lead with `expect(...)->toXxx(...)`. This class is a thin
 * secondary entry point for plain PHPUnit suites (and what the expectations
 * delegate to internally); the real logic lives in the `Assertions\` classes.
 */
final class Assert
{
    /**
     * Pin that a directory of migrations runs from an empty database in directory
     * order: every foreign-key target is created before the table referencing it.
     *
     * @param  int|null  $expectedForeignKeys  guard-the-guard: pin the total edge count so the
     *                                         check cannot pass over an empty parse
     * @param  array<string, string>  $tableResolvers  raw expression => table, for non-literal
     *                                                 `Schema::create($var)` / `->constrained(Class::method())`
     */
    public static function migrationsRunInDependencyOrder(
        string $migrationsDir,
        ?int $expectedForeignKeys = null,
        array $tableResolvers = [],
    ): void {
        MigrationGraph::forDirectory($migrationsDir, $tableResolvers)
            ->assertRunnable($expectedForeignKeys);
    }

    /**
     * Real-engine companion to {@see self::migrationsRunInDependencyOrder()}: run the
     * migrations against a live connection and pin that every one applies (on pgsql/mysql
     * that includes every foreign key landing on an existing parent).
     *
     * @param  int|null  $expectedMigrations  guard-the-guard: pin the file count so the check
     *                                        cannot pass over an empty or relocated directory
     */
    public static function migrationsApplyOnConnection(
        string $migrationsDir,
        string $connection,
        ?int $expectedMigrations = null,
    ): void {
        MigrationRunner::applyOnConnection($migrationsDir, $connection, $expectedMigrations);
    }

    /**
     * The negative control: pass only if the live engine **rejects** a deliberately
     * broken order. Fails loudly if the engine accepts it (a driver that does not enforce
     * foreign keys makes the check vacuous).
     *
     * @param  Closure(list<string>): iterable<string>  $reorder
     */
    public static function brokenOrderIsRejectedOnConnection(
        string $migrationsDir,
        Closure $reorder,
        string $connection,
    ): void {
        MigrationRunner::brokenOrderIsRejectedOnConnection($migrationsDir, $reorder, $connection);
    }

    /**
     * Pin the publish-only policy: the package's migrations directory (resolved from the
     * provider by reflection, or given explicitly) must not be registered with the migrator.
     *
     * @param  class-string<ServiceProvider>|string  $providerClass
     */
    public static function doesNotAutoLoadMigrations(string $providerClass, ?string $migrationsDir = null): void
    {
        MigrationAutoload::assert($providerClass, $migrationsDir);
    }

    /**
     * Pin that a provider publishes exactly $count migrations under $tag, each to a
     * timestamped `database_path('migrations/<Y_m_d_His>_<name>.php')` destination.
     *
     * @param  class-string<ServiceProvider>|string  $providerClass
     */
    public static function publishesMigrationsTimestamped(string $providerClass, string $tag, int $count): void
    {
        MigrationPublish::assert($providerClass, $tag, $count);
    }

    /**
     * Secondary escape hatch for `expect($configPath)->toSatisfyConfigContract(...)`:
     * pin that every config key the code reads is shipped (forward) and every shipped
     * leaf key is read (reverse), scraped from source with the tokenizer.
     *
     * @param  string|list<string>  $srcDirs
     * @param  array{
     *     excludeFromReverse?: list<string>,
     *     sectionVariables?: array<string, array<string, string>>,
     *     extraReadPrefixes?: list<string>,
     *     allowUnread?: list<string>,
     *     allowUnshipped?: list<string>,
     *     reverse?: bool,
     * }  $options
     */
    public static function configContract(string $configPath, string|array $srcDirs, ?string $prefix = null, array $options = []): void
    {
        ConfigContract::assert($configPath, $srcDirs, $prefix, $options);
    }

    /**
     * Secondary escape hatch for `expect($section)->toLeakNoSecrets(...)`: capture one
     * `artisan about` section and pin it renders every $mustRender string and leaks no
     * $secrets. $mustRender must be non-empty (a construction error otherwise).
     *
     * @param  list<string>  $secrets
     * @param  list<string>  $mustRender
     */
    public static function aboutSectionLeaksNoSecrets(string $section, array $secrets, array $mustRender): void
    {
        AboutSecrets::assert($section, $secrets, $mustRender);
    }

    /**
     * Secondary escape hatch for `expect($configKey)->toHonourModelSwap(...)`: drive the
     * real flow and pin that every model it produces has $subclass as its concrete class
     * (not merely `instanceof`), failing fast if the swap was not applied before boot.
     *
     * @param  class-string  $subclass
     * @param  Closure(): (Model|iterable<Model>)  $exercise
     */
    public static function modelSwapHonoured(string $configKey, string $subclass, Closure $exercise): void
    {
        ModelSwap::assert($configKey, $subclass, $exercise);
    }
}
