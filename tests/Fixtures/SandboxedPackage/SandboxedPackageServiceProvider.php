<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\SandboxedPackage;

use Illuminate\Support\ServiceProvider;

/**
 * A throwaway package that publishes the way the fleet does: a config file and a seeder whose
 * destinations are resolved when the provider REGISTERS, and migrations mapped at boot — plus
 * one destination that never moves with the sandbox, to prove the assertion catches it.
 */
final class SandboxedPackageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->publishes([
            __DIR__.'/config/sandboxed.php' => config_path('sandboxed.php'),
        ], 'sandboxed-config');

        $this->publishes([
            __DIR__.'/database/seeders/SandboxedSeeder.php.stub' => database_path('seeders/SandboxedSeeder.php'),
        ], 'sandboxed-seeders');
    }

    public function boot(): void
    {
        $this->publishesMigrations([
            __DIR__.'/database/migrations' => database_path('migrations'),
        ], 'sandboxed-migrations');

        $this->publishes([
            __DIR__.'/config/sandboxed.php' => base_path('sandboxed.php'),
        ], 'sandboxed-skeleton');
    }
}
