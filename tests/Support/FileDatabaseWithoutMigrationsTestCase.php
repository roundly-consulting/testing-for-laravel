<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Support;

/**
 * A real-engine (file-backed) suite that loads **no** migrations — the branch where the reset
 * must keep its hands off.
 *
 * `loadMigrationsFrom()` is what caches a migrator, so an empty cache means either nobody
 * loaded migrations or the suite uses `RefreshDatabase` (which takes Testbench's other branch
 * and owns its own reset). Dropping every table in either case would be this package
 * overreaching into a database it was never asked to manage.
 */
class FileDatabaseWithoutMigrationsTestCase extends FileDatabaseTestCase
{
    protected function migrationSources(): array
    {
        return [];
    }
}
