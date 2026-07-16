<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Database;

use Illuminate\Contracts\Foundation\Application;

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
     * Point the app's default `testing` connection at the matrix driver, and register the
     * real-engine connections (`pgsql`, `mysql`) from the same source of truth.
     *
     * The named connections deliberately **overwrite** the framework's stock ones, which
     * read `DB_*` and point at Laravel's defaults (database `laravel`, user `root`). Those
     * can never reach a `TESTING_DB_*`-configured CI service, so a gate on them would skip
     * silently even with the engine up. Off a driver leg the named connections are present
     * but unreachable — which is exactly what makes that gate skip *visibly*.
     */
    public static function configure(Application $app): void
    {
        $config = $app->make('config');

        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', self::connectionConfig());

        foreach (self::REAL_ENGINE_DRIVERS as $driver) {
            $config->set("database.connections.{$driver}", self::connectionConfig($driver));
        }
    }

    /**
     * The active driver: `TESTING_DB_DRIVER`, defaulting to `sqlite`.
     */
    public static function driver(): string
    {
        $driver = env('TESTING_DB_DRIVER');

        return is_string($driver) && $driver !== '' ? $driver : 'sqlite';
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
                'host' => (string) env('TESTING_DB_HOST', '127.0.0.1'),
                'port' => (string) env('TESTING_DB_PORT', '5432'),
                'database' => (string) env('TESTING_DB_DATABASE', 'testing'),
                'username' => (string) env('TESTING_DB_USERNAME', env('TESTING_DB_USER', 'testing')),
                'password' => (string) env('TESTING_DB_PASSWORD', ''),
                'charset' => 'utf8',
                'prefix' => '',
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ],
            'mysql', 'mariadb' => [
                'driver' => $driver,
                'host' => (string) env('TESTING_DB_HOST', '127.0.0.1'),
                'port' => (string) env('TESTING_DB_PORT', '3306'),
                'database' => (string) env('TESTING_DB_DATABASE', 'testing'),
                'username' => (string) env('TESTING_DB_USERNAME', env('TESTING_DB_USER', 'root')),
                'password' => (string) env('TESTING_DB_PASSWORD', ''),
                'charset' => 'utf8mb4',
                'prefix' => '',
            ],
            default => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        };
    }
}
