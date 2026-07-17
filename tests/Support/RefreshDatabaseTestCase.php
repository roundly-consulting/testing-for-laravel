<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Support;

use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * A real-engine (file-backed) suite that uses `RefreshDatabase` — the one shape the reset must
 * keep its hands off.
 *
 * `RefreshDatabase` migrates **once** and resets each test inside a transaction it rolls back
 * and disconnects itself. Dropping its tables would destroy the schema it relies on surviving,
 * and purging its connection would cut the transaction it is about to roll back. This is the
 * suite the old `cachedTestMigratorProcessors === []` gate was really protecting — the gate was
 * a proxy, and the proxy also swept up every package that merely ships no migrations.
 */
class RefreshDatabaseTestCase extends FileDatabaseTestCase
{
    use RefreshDatabase;
}
