<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RoundlyConsulting\Testing\Database\DriverMatrix;

/**
 * `RefreshDatabase` on an in-memory SQLite database, on every leg, opting in to a kept schema it
 * must not get: it owns the reset, so the teardown must leave it to Testbench. Pinned to SQLite because `RefreshDatabase` never drops
 * what it migrated: on a real engine its tables would outlive the suite's other tests.
 */
class InMemoryRefreshDatabaseTestCase extends FakePackageTestCase
{
    use RefreshDatabase;

    protected function keepsMigratedSchema(): bool
    {
        return true;
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('database.connections.testing', DriverMatrix::connectionConfig('sqlite'));
    }
}
