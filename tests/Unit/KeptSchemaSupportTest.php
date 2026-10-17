<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Database\SqlServerConnection;
use RoundlyConsulting\Testing\Database\KeptSchema;

/**
 * Which connections a worker may keep a schema on. Everything else keeps the drop-per-test
 * reset: a file or shared-cache SQLite database outlives the test differently, and a table
 * prefix would be applied twice to the names the catalogue reads back.
 */
it('keeps a schema only where it can reset one', function (Connection $connection, bool $supported): void {
    expect(KeptSchema::supports($connection))->toBe($supported);
})->with([
    'mysql' => [fn () => new MySqlConnection(fn () => null, 'testing', '', ['driver' => 'mysql']), true],
    'mariadb' => [fn () => new MariaDbConnection(fn () => null, 'testing', '', ['driver' => 'mariadb']), true],
    'pgsql' => [fn () => new PostgresConnection(fn () => null, 'testing', '', ['driver' => 'pgsql']), true],
    'in-memory sqlite' => [fn () => new SQLiteConnection(fn () => null, ':memory:', '', ['driver' => 'sqlite', 'database' => ':memory:']), true],
    'file sqlite' => [fn () => new SQLiteConnection(fn () => null, '/tmp/db.sqlite', '', ['driver' => 'sqlite', 'database' => '/tmp/db.sqlite']), false],
    'shared-cache in-memory sqlite' => [fn () => new SQLiteConnection(fn () => null, 'file::memory:?cache=shared', '', ['driver' => 'sqlite', 'database' => 'file::memory:?cache=shared']), false],
    'sqlsrv' => [fn () => new SqlServerConnection(fn () => null, 'testing', '', ['driver' => 'sqlsrv']), false],
    'a table prefix' => [fn () => new PostgresConnection(fn () => null, 'testing', 'app_', ['driver' => 'pgsql']), false],
]);
