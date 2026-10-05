<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Database;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\ParallelTesting;
use InvalidArgumentException;
use RoundlyConsulting\Testing\Assertions\Migrations\MigrationRunner;

/**
 * The shared entry point for running a package's suite across database drivers.
 *
 * A suite that only ever runs on SQLite has a blind spot for anything the drivers
 * disagree on: translatable #39 shipped a `LIKE` without an `ESCAPE` clause that was
 * green on postgres but returned zero rows on SQLite — the migration-order lesson in
 * mirror image. Only a driver matrix catches that class of bug.
 *
 * {@see self::configure()} points the app's `testing` connection at the driver named by
 * the `TESTING_DB_DRIVER` env var (default: in-memory SQLite with foreign keys ON), and
 * registers the real-engine drivers under their own connection names.
 *
 * A CI job must export **`TESTING_DB_DRIVER`** to move the suite off SQLite;
 * `TESTING_DB_{HOST,PORT,DATABASE,USERNAME,PASSWORD}` only describe *where* the engine is.
 * Exporting the location without the driver leaves the suite on SQLite — a "pgsql" leg
 * that never touches Postgres, which is the vacuous green this package exists to kill.
 *
 * ```php
 * // In a package TestCase::defineEnvironment():
 * DriverMatrix::configure($app);
 *
 * // Skip a driver-specific case visibly on the wrong leg:
 * it('uses a jsonb column')->skip(fn () => DriverMatrix::driver() !== 'pgsql');
 * ```
 *
 * ## Parallel workers
 *
 * Under `pest --parallel` each worker carries a token (Laravel's `ParallelTesting::token()`),
 * and every worker would otherwise share one database and one probe namespace — so one
 * worker's teardown (drop every table) and probe drops pulled tables out from under another.
 * With a token the suite's real-engine database becomes `<database>_<token>` (created on
 * first use) and the probe namespace `testing_probe_<token>`. Without one nothing changes.
 */
final class DriverMatrix
{
    /**
     * Drivers registered under their own connection name by {@see self::configure()}, so a
     * suite can address a real engine directly regardless of the leg it runs on.
     *
     * @var list<string>
     */
    private const array REAL_ENGINE_DRIVERS = ['pgsql', 'mysql'];

    /**
     * Every driver the matrix can build a connection for. Anything else is refused: an
     * unknown name used to fall through to SQLite, so `TESTING_DB_DRIVER=postgres` (or
     * `sqlsrv`) produced a "postgres" leg that ran on `:memory:` and skipped every pgsql-gated
     * test green.
     *
     * @var list<string>
     */
    public const array DRIVERS = ['sqlite', 'pgsql', 'mysql', 'mariadb'];

    /**
     * The namespace the real-engine **probe** connections are isolated into: a dedicated
     * Postgres schema, and a dedicated MySQL database, both created on demand.
     *
     * This exists because the probe and the suite are the same engine at the same
     * `TESTING_DB_*` location. On the matching leg (`TESTING_DB_DRIVER=pgsql`) the
     * `testing` and `pgsql` connections were built from the same {@see self::connectionConfig()}
     * and were therefore byte-identical: **one physical database reached through two PDO
     * sessions**. {@see MigrationRunner}
     * drops the target connection clean before and after every run, so a probe run dropped
     * the *live suite's* tables mid-test. Random execution order made that a coin flip
     * rather than a hard failure, which is why it survived for weeks.
     *
     * Isolating the probe into its own namespace makes the collision structurally
     * impossible: Laravel scopes both `dropAllTables()` and `getTables()` to the
     * connection's own schema listing (`search_path` on Postgres, the database name on
     * MySQL), so a probe drop can only ever reach the probe's own schema.
     */
    public const string PROBE_NAMESPACE = 'testing_probe';

    /** @var array<string, true> worker databases this process has already ensured */
    private static array $workerDatabases = [];

    /**
     * Point the app's default `testing` connection at the matrix driver, and register the
     * real-engine connections (`pgsql`, `mysql`) as **isolated probes** against the same
     * `TESTING_DB_*` location.
     *
     * The named connections deliberately **overwrite** the framework's stock ones, which
     * read `DB_*` and point at Laravel's defaults (database `laravel`, user `root`). Those
     * can never reach a `TESTING_DB_*`-configured CI service, so a gate on them would skip
     * silently even with the engine up. Off a driver leg the named connections are present
     * but unreachable — which is exactly what makes that gate skip *visibly*.
     *
     * They are registered from {@see self::probeConnectionConfig()}, never
     * {@see self::connectionConfig()}: on the matching leg the latter would hand the probe
     * the *suite's own* database. See {@see self::PROBE_NAMESPACE}.
     */
    public static function configure(Application $app): void
    {
        $config = $app->make('config');

        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', self::connectionConfig());

        foreach (self::REAL_ENGINE_DRIVERS as $driver) {
            $config->set("database.connections.{$driver}", self::probeConnectionConfig($driver));
        }

        self::ensureWorkerDatabase($app);
    }

    /**
     * The probe namespace for this process: {@see self::PROBE_NAMESPACE}, suffixed with the
     * parallel worker's token when there is one.
     */
    public static function probeNamespace(): string
    {
        $token = self::parallelToken();

        return $token === null ? self::PROBE_NAMESPACE : self::PROBE_NAMESPACE.'_'.$token;
    }

    /**
     * The parallel worker's token, or null outside `--parallel`. Read through Laravel's own
     * {@see ParallelTesting} — the app's instance when there is one, so a custom token
     * resolver is honoured — which falls back to paratest's `TEST_TOKEN`.
     */
    private static function parallelToken(): ?string
    {
        $container = Container::getInstance();
        $parallel = $container->bound(ParallelTesting::class)
            ? $container->make(ParallelTesting::class)
            : new ParallelTesting($container);

        $token = $parallel->token();

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * Create this worker's database on the leg's engine the first time a process needs it:
     * Postgres and MySQL refuse to connect to a database that does not exist. Created over the
     * shared database the location names, which is never touched otherwise.
     */
    private static function ensureWorkerDatabase(Application $app): void
    {
        $driver = self::driver();

        if ($driver === 'sqlite' || self::parallelToken() === null) {
            return;
        }

        $worker = self::connectionConfig($driver);
        $name = (string) $worker['database'];
        $key = implode('|', [$driver, $worker['host'], $worker['port'], $name]);

        if (isset(self::$workerDatabases[$key])) {
            return;
        }

        $config = $app->make('config');
        $db = $app->make('db');
        $bootstrap = 'testing__worker_bootstrap';

        $config->set("database.connections.{$bootstrap}", [...$worker, 'database' => self::location($driver, 'DATABASE', 'testing')]);

        try {
            $connection = $db->connection($bootstrap);

            if ($driver === 'pgsql') {
                if ($connection->selectOne('select 1 from pg_database where datname = ?', [$name]) === null) {
                    $connection->statement('create database "'.str_replace('"', '""', $name).'"');
                }
            } else {
                $connection->statement('create database if not exists `'.str_replace('`', '``', $name).'`');
            }
        } finally {
            $db->purge($bootstrap);
            $config->set("database.connections.{$bootstrap}", null);
        }

        self::$workerDatabases[$key] = true;
    }

    /**
     * The connection config for a real-engine **probe**: the same engine at the same
     * `TESTING_DB_*` location as {@see self::connectionConfig()}, but confined to
     * {@see self::PROBE_NAMESPACE} so dropping it can never reach the suite's schema.
     *
     * The Postgres `search_path` is the probe schema **alone**, deliberately without a
     * `,public` fallback: Laravel derives the drop scope from `search_path`, so adding
     * `public` back would hand the probe the suite's tables again — reintroducing the very
     * collision this method exists to prevent.
     *
     * @return array<string, mixed>
     */
    public static function probeConnectionConfig(string $driver): array
    {
        $config = self::connectionConfig($driver);

        return match ($driver) {
            'pgsql' => [...$config, 'search_path' => self::probeNamespace()],
            'mysql', 'mariadb' => [...$config, 'database' => self::probeNamespace()],
            default => $config,
        };
    }

    /**
     * Create the probe's namespace if it is not there yet, so a probe connection can be
     * used from an empty engine. Idempotent, and a **no-op for anything that is not an
     * isolated probe** — a connection the suite itself might be running on is never
     * touched.
     *
     * Throws if the engine is unreachable; callers gate on that to skip *visibly*.
     */
    public static function prepareProbe(string $connection): void
    {
        if (! self::isProbe($connection)) {
            return;
        }

        $driver = app(Repository::class)->get("database.connections.{$connection}.driver");

        if ($driver === 'pgsql') {
            // Postgres accepts a `search_path` naming a schema that does not exist yet, so
            // the probe connection can create its own schema. Unqualified DDL then lands
            // there, and nowhere else.
            DB::connection($connection)->statement(
                'create schema if not exists "'.self::probeNamespace().'"',
            );

            return;
        }

        // MySQL has no schemas, and refuses to connect to a database that does not exist —
        // so the probe database is created over the suite's own location first, then the
        // probe connection is dropped so it reconnects to the new database.
        self::createMysqlProbeDatabase((string) $driver, $connection);
    }

    private static function createMysqlProbeDatabase(string $driver, string $connection): void
    {
        $config = app(Repository::class);
        $bootstrap = "{$connection}__probe_bootstrap";

        $config->set("database.connections.{$bootstrap}", self::connectionConfig($driver));

        try {
            DB::connection($bootstrap)->statement(
                'create database if not exists `'.self::probeNamespace().'`',
            );
        } finally {
            DB::purge($bootstrap);
            $config->set("database.connections.{$bootstrap}", null);
            DB::purge($connection);
        }
    }

    /**
     * Whether a configured connection is an isolated probe — i.e. confined to
     * {@see self::PROBE_NAMESPACE}. Keyed on the namespace rather than the connection
     * name so a suite that registers the probe under another name still gets the guard,
     * and so the suite's own connection can never match. An unconfigured name is no probe.
     */
    public static function isProbe(string $connection): bool
    {
        $settings = app(Repository::class)->get("database.connections.{$connection}");

        if (! is_array($settings)) {
            return false;
        }

        return match ($settings['driver'] ?? null) {
            'pgsql' => ($settings['search_path'] ?? null) === self::probeNamespace(),
            'mysql', 'mariadb' => ($settings['database'] ?? null) === self::probeNamespace(),
            default => false,
        };
    }

    /**
     * One `TESTING_DB_*` value for a driver, or the driver's own default when the
     * location cannot be describing that driver.
     */
    private static function location(string $driver, string $key, string $default): string
    {
        if (! self::locationDescribes($driver)) {
            return $default;
        }

        $value = env("TESTING_DB_{$key}");

        return $value === null ? $default : (string) $value;
    }

    /**
     * Whether the `TESTING_DB_*` location can be describing this driver's engine.
     *
     * The location describes **one** engine — the leg's — not every engine at once.
     * Handing it to a *different* real engine is not merely useless, it is actively
     * harmful: on the pgsql leg `TESTING_DB_PORT=5432` pointed the mysql driver straight
     * at Postgres, where the TCP connect succeeds and the client then waits **60 seconds**
     * for a MySQL handshake that will never come — per call, on a connection whose only
     * job was to be unreachable. Falling back to the driver's own defaults keeps the
     * off-leg connections "present but unreachable", which is the point, and makes them
     * fail *fast*.
     *
     * A sqlite leg is the exception: sqlite has no location of its own, so a
     * `TESTING_DB_*` set beside it can only be describing a real engine the suite means
     * to reach — a postgres service running next to a sqlite leg. It describes **postgres
     * only**: postgres is the fleet's real-engine leg, and handing the same location to the
     * mysql probe as well pointed it at that Postgres — the 60-second handshake hang above,
     * four times over per availability check. A MySQL run sets `TESTING_DB_DRIVER=mysql`.
     */
    private static function locationDescribes(string $driver): bool
    {
        $leg = self::driver();

        return $leg === $driver || ($leg === 'sqlite' && $driver === 'pgsql');
    }

    /**
     * The database a driver's connection opens: the location's (default `testing`), and — for
     * the engine the suite itself runs on, under `--parallel` — that name with the worker's
     * token, so no two workers share the database their teardown empties.
     */
    private static function database(string $driver): string
    {
        $database = self::location($driver, 'DATABASE', 'testing');
        $token = $driver === self::driver() ? self::parallelToken() : null;

        return $token === null ? $database : $database.'_'.$token;
    }

    /**
     * The active driver: `TESTING_DB_DRIVER`, defaulting to `sqlite`. An unrecognised value
     * throws — see {@see self::DRIVERS}.
     */
    public static function driver(): string
    {
        $driver = env('TESTING_DB_DRIVER');

        if (! is_string($driver) || $driver === '') {
            return 'sqlite';
        }

        if (! in_array($driver, self::DRIVERS, true)) {
            throw new InvalidArgumentException(
                "TESTING_DB_DRIVER={$driver} is not a driver the matrix knows (".implode(', ', self::DRIVERS).'). '
                .'Refusing to fall back to SQLite: a leg named for another engine would run on :memory: and skip '
                .'its engine-gated tests green. Fix the variable on the CI leg.',
            );
        }

        return $driver;
    }

    /**
     * The connection config array for the active (or given) driver. Exposed so a suite
     * can register the matrix connection under a different name, or assert on it.
     *
     * @return array<string, mixed>
     */
    public static function connectionConfig(?string $driver = null): array
    {
        $driver ??= self::driver();

        return match ($driver) {
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => self::location($driver, 'HOST', '127.0.0.1'),
                'port' => self::location($driver, 'PORT', '5432'),
                'database' => self::database($driver),
                'username' => self::location($driver, 'USERNAME', 'testing'),
                'password' => self::location($driver, 'PASSWORD', ''),
                'charset' => 'utf8',
                'prefix' => '',
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ],
            'mysql', 'mariadb' => [
                'driver' => $driver,
                'host' => self::location($driver, 'HOST', '127.0.0.1'),
                'port' => self::location($driver, 'PORT', '3306'),
                'database' => self::database($driver),
                'username' => self::location($driver, 'USERNAME', 'root'),
                'password' => self::location($driver, 'PASSWORD', ''),
                'charset' => 'utf8mb4',
                'prefix' => '',
            ],
            'sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            default => throw new InvalidArgumentException(
                "[{$driver}] is not a driver the matrix knows (".implode(', ', self::DRIVERS).').',
            ),
        };
    }
}
