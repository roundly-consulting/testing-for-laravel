<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use RoundlyConsulting\Testing\Concerns\ConfiguresSqliteDatabase;
use RoundlyConsulting\Testing\Concerns\LoadsProviderMigrations;
use RoundlyConsulting\Testing\Concerns\SwapsConfiguredModels;

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
    use ConfiguresSqliteDatabase;
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
        $this->configureSqliteDatabase($app);

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
}
