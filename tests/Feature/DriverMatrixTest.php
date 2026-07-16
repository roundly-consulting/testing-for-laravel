<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Testing\Database\DriverMatrix;
use RoundlyConsulting\Testing\Tests\Support\MinimalPackageTestCase;

uses(MinimalPackageTestCase::class);

// ---------------------------------------------------------------------------
// Driver selection + config shape — runs on every leg.
// ---------------------------------------------------------------------------

it('defaults to sqlite when TESTING_DB_DRIVER is unset', function (): void {
    expect(DriverMatrix::driver())->toBe('sqlite')
        ->and(DriverMatrix::connectionConfig())->toMatchArray([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
})->skip(fn (): bool => driverEnvIsSet(), 'TESTING_DB_DRIVER is exported on this leg');

it('follows TESTING_DB_DRIVER when it is set', function (): void {
    // The var CI must export to move a suite off sqlite. Exporting only TESTING_DB_HOST
    // and friends leaves the suite on sqlite — a "pgsql" leg that never touches postgres.
    expect(DriverMatrix::driver())->toBe(env('TESTING_DB_DRIVER'))
        ->and(DriverMatrix::connectionConfig())->toMatchArray(['driver' => env('TESTING_DB_DRIVER')]);
})->skip(fn (): bool => ! driverEnvIsSet(), 'TESTING_DB_DRIVER not exported on this leg');

it('always builds the sqlite config when sqlite is named explicitly', function (): void {
    // Leg-independent: the default branch is reachable on every leg by naming the driver.
    expect(DriverMatrix::connectionConfig('sqlite'))->toMatchArray([
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
        ->and(config('database.connections.testing.driver'))->toBe(DriverMatrix::driver());
});

it('registers the real-engine connections from a single source of truth', function (): void {
    DriverMatrix::configure(app());

    // Overwriting the framework's stock pgsql/mysql connections is the point: those read
    // DB_* and aim at database `laravel` / user `root`, which can never reach a
    // TESTING_DB_*-configured CI service — so a gate on them skips even with the engine up.
    expect(config('database.connections.pgsql'))->toBe(DriverMatrix::connectionConfig('pgsql'))
        ->and(config('database.connections.mysql'))->toBe(DriverMatrix::connectionConfig('mysql'));
});

// ---------------------------------------------------------------------------
// pgsql leg only — the matrix must be able to reach a real engine in CI. Skips
// visibly (never a silent green) when no postgres service is wired.
// ---------------------------------------------------------------------------

it('reaches a real postgres engine on the CI pgsql leg', function (): void {
    // The base case registers `pgsql` from the matrix, so this no longer hand-rolls a
    // connection or a reachability probe — it uses the shipped gate an adopting package uses.
    expect(DB::connection('pgsql')->getPdo())->not->toBeNull();
})->skip(fn (): bool => ! test()->connectionAvailable('pgsql'), 'no postgres engine configured');

it('runs the whole suite on postgres when TESTING_DB_DRIVER says so', function (): void {
    // The end-to-end proof that the driver leg is not a lie: the suite's *default*
    // connection is postgres and it is actually open. Before TESTING_DB_DRIVER was
    // exported, this job ran every one of these tests on sqlite.
    expect(config('database.connections.testing.driver'))->toBe('pgsql')
        ->and(DB::connection('testing')->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME))->toBe('pgsql');
})->skip(fn (): bool => DriverMatrix::driver() !== 'pgsql', 'pgsql driver leg only');
