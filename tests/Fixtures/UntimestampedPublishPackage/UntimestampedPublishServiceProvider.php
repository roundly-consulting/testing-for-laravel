<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\UntimestampedPublishPackage;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Testing\Assertions\Migrations\MigrationPublish;

/**
 * A throwaway provider that publishes its migrations directory to a bare
 * `database_path('migrations')` destination — no timestamp stamped into the filename.
 * Red fixture: {@see MigrationPublish}
 * must go red on the untimestamped destination shape.
 */
final class UntimestampedPublishServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/database/migrations' => database_path('migrations'),
        ], 'untimestamped-migrations');
    }
}
