<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Support;

use RoundlyConsulting\Testing\Database\DriverMatrix;
use RoundlyConsulting\Testing\PackageTestCase;

/**
 * Testbench base for the real-engine runner self-tests.
 *
 * {@see PackageTestCase} now ships the whole real-engine wiring — the `pgsql` connection
 * and the {@see PackageTestCase::connectionAvailable()} gate — so this class no longer
 * copies it. All it adds is one connection the base case deliberately does not have:
 *
 *  - `sqlite_real` — an in-memory SQLite database, pinned to SQLite on **every** leg. The
 *    base `testing` connection follows `TESTING_DB_DRIVER`, so on the pgsql leg it is not
 *    SQLite. These tests need a driver that does *not* enforce foreign keys at DDL time,
 *    because that is exactly the driver against which the negative control must FAIL
 *    loudly. Pinning it here keeps that meaning on every leg.
 *  - `sqlite_unreachable` — configured, never usable: the stand-in for an engine that is
 *    down, which the negative control must refuse to count as a rejection.
 *
 * The inherited `pgsql` connection is present but unreachable off the postgres CI job, so
 * the pgsql self-tests skip *visibly* rather than passing vacuously.
 */
class RealEngineTestCase extends PackageTestCase
{
    protected function packageProviders(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function configBeforeBoot(): array
    {
        return [
            'database.connections.sqlite_real' => DriverMatrix::connectionConfig('sqlite'),
            // Configured but unreachable: the database file does not exist, so opening the
            // connection throws — an engine that is down, without needing pgsql/mysql.
            'database.connections.sqlite_unreachable' => [
                ...DriverMatrix::connectionConfig('sqlite'),
                'database' => __DIR__.'/../Fixtures/no-such-directory/database.sqlite',
            ],
        ];
    }
}
