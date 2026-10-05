<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Testing\Assertions\Migrations\MigrationRunner;
use RoundlyConsulting\Testing\Database\DriverMatrix;
use RoundlyConsulting\Testing\Tests\Support\MinimalPackageTestCase;
use Symfony\Component\Process\Process;

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
    expect(config('database.connections.pgsql'))->toBe(DriverMatrix::probeConnectionConfig('pgsql'))
        ->and(config('database.connections.mysql'))->toBe(DriverMatrix::probeConnectionConfig('mysql'));
});

// ---------------------------------------------------------------------------
// Probe isolation — the regression pins for the probe/suite collision. All of
// these run on EVERY leg and need no engine: the original bug was masked by
// random execution order, so its pin must never depend on a seed.
// ---------------------------------------------------------------------------

it('confines the real-engine probes to their own namespace', function (): void {
    expect(DriverMatrix::probeConnectionConfig('pgsql'))->toMatchArray([
        'driver' => 'pgsql',
        'database' => 'testing',
        'search_path' => DriverMatrix::PROBE_NAMESPACE,
    ])
        ->and(DriverMatrix::probeConnectionConfig('mysql'))->toMatchArray([
            'driver' => 'mysql',
            'database' => DriverMatrix::PROBE_NAMESPACE,
        ]);
});

it('never hands a probe the same namespace as the suite it runs beside', function (): void {
    // The exact byte-identity that caused the collision. On the pgsql leg
    // connectionConfig() IS the suite's connection, so this asserts the probe and the
    // suite can never be the same physical schema — the property the fix has to hold.
    foreach (['pgsql', 'mysql'] as $driver) {
        $suite = DriverMatrix::connectionConfig($driver);
        $probe = DriverMatrix::probeConnectionConfig($driver);

        expect($probe)->not->toBe($suite);

        $suiteNamespace = $driver === 'pgsql' ? $suite['search_path'] : $suite['database'];
        $probeNamespace = $driver === 'pgsql' ? $probe['search_path'] : $probe['database'];

        expect($probeNamespace)->not->toBe($suiteNamespace);
    }
});

it('leaves sqlite untouched — an in-memory database is already isolated', function (): void {
    // Each :memory: connection is its own database, so there is nothing to confine, and
    // inventing a namespace would break the sqlite_real fixture connection.
    expect(DriverMatrix::probeConnectionConfig('sqlite'))->toBe(DriverMatrix::connectionConfig('sqlite'));
});

it('never points one engine at another engine location', function (): void {
    // The TESTING_DB_* location describes the LEG's engine, not every engine. On the pgsql
    // leg TESTING_DB_PORT=5432 was handed to the mysql driver, which connects to Postgres,
    // then blocks 60 SECONDS waiting for a MySQL handshake — per call, on a connection
    // whose only job is to be unreachable. An off-leg engine must fall back to its own
    // default port so it fails fast instead.
    $offLeg = array_values(array_filter(['pgsql', 'mysql'], fn (string $d): bool => $d !== DriverMatrix::driver()));

    foreach ($offLeg as $driver) {
        $port = DriverMatrix::connectionConfig($driver)['port'];

        expect($port)->toBe($driver === 'pgsql' ? '5432' : '3306');
    }
})->skip(fn (): bool => DriverMatrix::driver() === 'sqlite', 'no leg engine to conflict with on sqlite');

it('never prepares a connection that is not an isolated probe', function (): void {
    DriverMatrix::configure(app());

    // prepareProbe keys off the namespace, not the connection name, so the connection the
    // suite is actually running on can never be mistaken for a probe and prepared (or,
    // one step later, dropped). A no-op must also be silent — not an exception.
    DriverMatrix::prepareProbe('testing');
    DriverMatrix::prepareProbe('a-connection-that-is-not-configured');

    expect(config('database.connections.testing'))->toBe(DriverMatrix::connectionConfig());
});

it('reports the mysql probe unavailable rather than throwing when no mysql leg is up', function (): void {
    DriverMatrix::configure(app());

    // The mysql probe database is created on demand over the suite's own location, because
    // MySQL refuses to connect to a database that does not exist. With no mysql engine
    // reachable that bootstrap must surface as an ordinary "unavailable" — the gate every
    // R row skips on — never as an exception that takes the suite down with it.
    expect(MigrationRunner::connectionIsAvailable('mysql'))->toBeFalse();

    // The bootstrap connection is scratch: it must not be left behind in config.
    expect(config('database.connections.mysql__probe_bootstrap'))->toBeNull();
})->skip(fn (): bool => MigrationRunner::connectionIsAvailable('mysql'), 'a mysql engine is reachable on this leg');

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

// ---------------------------------------------------------------------------
// An unrecognised driver is a configuration error, never a silent SQLite run.
// ---------------------------------------------------------------------------

it('refuses to build a config for a driver it does not know', function (string $driver): void {
    expect(fn (): array => DriverMatrix::connectionConfig($driver))
        ->toThrow(InvalidArgumentException::class, "[{$driver}]");
})->with(['postgres', 'sqlsrv', 'SQLITE']);

it('fails loudly when TESTING_DB_DRIVER names an unknown driver', function (): void {
    // Laravel's env repository is immutable inside this process, so the variable is set on a
    // child PHP process — the only honest way to observe what a CI leg exporting it gets.
    $probe = fn (string $driver): Process => tap(new Process(
        [PHP_BINARY, '-r', 'require "vendor/autoload.php"; echo RoundlyConsulting\Testing\Database\DriverMatrix::driver();'],
        dirname(__DIR__, 2),
        ['TESTING_DB_DRIVER' => $driver],
    ))->run();

    $typo = $probe('postgres');

    // A "postgres" leg used to get sqlite `:memory:` and skip every pgsql-gated test green.
    expect($typo->isSuccessful())->toBeFalse()
        ->and($typo->getErrorOutput().$typo->getOutput())->toContain('TESTING_DB_DRIVER=postgres');

    $known = $probe('pgsql');

    expect($known->isSuccessful())->toBeTrue()
        ->and($known->getOutput())->toBe('pgsql');
});

it('does not hand a sqlite-leg location to the mysql probe', function (): void {
    // A sqlite leg with a Postgres location beside it (TESTING_DB_PORT=5432): the location
    // describes that Postgres. Handed to mysql too, the probe dialled Postgres and waited on a
    // MySQL handshake — 240 seconds per availability check, measured.
    $configs = new Process(
        [PHP_BINARY, '-r', 'require "vendor/autoload.php"; use RoundlyConsulting\Testing\Database\DriverMatrix as M; echo json_encode([M::connectionConfig("pgsql"), M::connectionConfig("mysql")]);'],
        dirname(__DIR__, 2),
        ['TESTING_DB_DRIVER' => false, 'TESTING_DB_HOST' => '10.9.9.9', 'TESTING_DB_PORT' => '5432', 'TESTING_DB_USERNAME' => 'ci'],
    );
    $configs->mustRun();

    [$pgsql, $mysql] = json_decode($configs->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    expect($pgsql)->toMatchArray(['host' => '10.9.9.9', 'port' => '5432', 'username' => 'ci'])
        ->and($mysql)->toMatchArray(['host' => '127.0.0.1', 'port' => '3306', 'username' => 'root']);
});
