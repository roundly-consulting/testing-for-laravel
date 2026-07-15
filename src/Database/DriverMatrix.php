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
 * the `TESTING_DB_DRIVER` env var (default: in-memory SQLite with foreign keys ON). The
 * pgsql / mysql legs read `TESTING_DB_{HOST,PORT,DATABASE,USERNAME,PASSWORD}` — the same
 * variables the Phase B postgres CI job already exports, so it is a drop-in there.
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
     * Point the app's default `testing` connection at the matrix driver.
     */
    public static function configure(Application $app): void
    {
        $config = $app->make('config');

        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', self::connectionConfig());
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
