<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Testing\Database\DriverMatrix;
use RoundlyConsulting\Testing\Tests\Support\MinimalPackageTestCase;

uses(MinimalPackageTestCase::class);

function matrixPgsqlReachable(): bool
{
    config()->set('database.connections.matrix_pgsql', DriverMatrix::connectionConfig('pgsql'));

    try {
        DB::connection('matrix_pgsql')->getPdo();

        return true;
    } catch (Throwable) {
        return false;
    } finally {
        DB::purge('matrix_pgsql');
    }
}

// ---------------------------------------------------------------------------
// Driver selection + config shape — runs on every leg.
// ---------------------------------------------------------------------------

it('defaults to sqlite when TESTING_DB_DRIVER is unset', function (): void {
    expect(DriverMatrix::driver())->toBe('sqlite');
});

it('configures an in-memory sqlite connection with foreign keys on by default', function (): void {
    expect(DriverMatrix::connectionConfig())->toMatchArray([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
});

it('builds a postgres connection config from the TESTING_DB_* env', function (): void {
    expect(DriverMatrix::connectionConfig('pgsql'))->toMatchArray([
        'driver' => 'pgsql',
        'database' => 'testing',
        'search_path' => 'public',
    ]);
});

it('builds a mysql and a mariadb connection config', function (): void {
    expect(DriverMatrix::connectionConfig('mysql'))->toMatchArray(['driver' => 'mysql', 'charset' => 'utf8mb4'])
        ->and(DriverMatrix::connectionConfig('mariadb'))->toMatchArray(['driver' => 'mariadb']);
});

it('points the default testing connection at the matrix driver', function (): void {
    DriverMatrix::configure(app());

    expect(config('database.default'))->toBe('testing')
        ->and(config('database.connections.testing.driver'))->toBe('sqlite');
});

// ---------------------------------------------------------------------------
// pgsql leg only — the matrix must be able to reach a real engine in CI. Skips
// visibly (never a silent green) when no postgres service is wired.
// ---------------------------------------------------------------------------

it('reaches a real postgres engine on the CI pgsql leg', function (): void {
    config()->set('database.connections.matrix_pgsql', DriverMatrix::connectionConfig('pgsql'));

    expect(DB::connection('matrix_pgsql')->getPdo())->not->toBeNull();
})->skip(fn (): bool => ! matrixPgsqlReachable(), 'no postgres engine configured');
