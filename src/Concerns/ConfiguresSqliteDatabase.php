<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Concerns;

use Illuminate\Contracts\Foundation\Application;

/**
 * Configures the Testbench app to run against an in-memory SQLite database with
 * foreign-key constraints ON.
 *
 * `foreign_key_constraints => true` is the fleet default on purpose: SQLite defaults
 * to *off*, and a suite with constraints disabled cannot observe a broken foreign
 * key at all. Turning them on is the weakest possible net (it still only bites at
 * insert time, not DDL) — the structural migration-order pin is the real guard.
 */
trait ConfiguresSqliteDatabase
{
    protected function configureSqliteDatabase(Application $app): void
    {
        $config = $app->make('config');

        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }
}
