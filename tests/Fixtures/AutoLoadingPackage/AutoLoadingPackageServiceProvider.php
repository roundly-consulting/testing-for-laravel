<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\AutoLoadingPackage;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Testing\Assertions\Migrations\MigrationAutoload;

/**
 * A throwaway provider that *violates* the publish-only policy by auto-loading its
 * migrations. Red fixture: {@see MigrationAutoload}
 * must go red on it.
 */
final class AutoLoadingPackageServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }
}
