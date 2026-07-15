<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\PublishOnlyPackage;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Testing\Assertions\Migrations\MigrationAutoload;
use RoundlyConsulting\Testing\Assertions\Migrations\MigrationPublish;

/**
 * A throwaway package provider that follows the fleet's publish-only policy: it never
 * calls `loadMigrationsFrom()`, and publishes its two migration sources under a
 * timestamped tag. Green fixture for both {@see MigrationAutoload}
 * and {@see MigrationPublish}.
 */
final class PublishOnlyPackageServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/database/migrations/create_gadgets_table.php.stub' => database_path(
                'migrations/2026_07_15_100000_create_gadgets_table.php',
            ),
            __DIR__.'/database/migrations/create_widgets_table.php.stub' => database_path(
                'migrations/2026_07_15_100001_create_widgets_table.php',
            ),
        ], 'publish-only-migrations');
    }
}
