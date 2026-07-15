<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use Orchestra\Testbench\TestCase;
use RoundlyConsulting\Testing\Assertions\Migrations\MigrationRunner;

/**
 * Testbench base for the real-engine runner self-tests. It configures two named
 * connections:
 *
 *  - `sqlite_real` — an in-memory SQLite database (always available). It exercises the
 *    runner on every CI leg, and — because SQLite does not enforce foreign keys at DDL
 *    time — it is exactly the driver against which the negative control must FAIL loudly.
 *  - `pgsql` — read from `TESTING_DB_*` env vars, present only on the postgres CI job.
 *    A real engine rejects a child-before-parent order, which is where the negative
 *    control turns green. When the env is absent the pgsql self-tests skip *visibly*.
 */
class RealEngineTestCase extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $config = $app->make('config');

        $config->set('database.default', 'sqlite_real');

        $config->set('database.connections.sqlite_real', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $config->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => env('TESTING_DB_HOST', '127.0.0.1'),
            'port' => env('TESTING_DB_PORT', '5432'),
            'database' => env('TESTING_DB_DATABASE', 'testing'),
            'username' => env('TESTING_DB_USERNAME', 'testing'),
            'password' => env('TESTING_DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);
    }

    /**
     * Whether a configured connection can be reached — used by the pgsql-gated tests to
     * skip visibly when no real engine is present.
     */
    public function connectionAvailable(string $connection): bool
    {
        return MigrationRunner::connectionIsAvailable($connection);
    }
}
