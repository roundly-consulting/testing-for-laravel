<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Testing\Tests\Support\FileDatabaseWithoutMigrationsTestCase;

uses(FileDatabaseWithoutMigrationsTestCase::class);

/**
 * A real-engine suite that loaded **no** migrations still gets the full reset.
 *
 * This file used to assert the opposite — that a 0-migration suite was "left alone" — because
 * the teardown gated its reset on `cachedTestMigratorProcessors === []`. That cache is empty
 * both for a suite using `RefreshDatabase` (which must be left alone) and for one that simply
 * ships no migrations (which must not be), and the gate could not tell them apart. Four shipped
 * packages — `query-builder`, `metrics`, `translatable`, `crypto` — ship zero migrations and
 * were getting no teardown at all.
 *
 * The reset is driven explicitly here rather than awaited between tests: calling it inside the
 * test is what makes the proof deterministic instead of dependent on execution order.
 */
it('drops a table a 0-migration suite created', function (): void {
    // Nothing was migrated — this table is the suite's own, and the exact shape that survived
    // into the next test as `relation "posts" already exists`.
    Schema::create('hand_rolled', function ($table): void {
        $table->id();
    });

    expect(Schema::hasTable('hand_rolled'))->toBeTrue();

    $this->tearDownInteractsWithMigrations();

    expect(Schema::hasTable('hand_rolled'))->toBeFalse();
});

/**
 * The reset closes the PDO session, not just the schema.
 *
 * Dropping tables leaves the connection wide open, and nothing else in the stack closes it:
 * Testbench never disconnects, and flushing the app does not reliably collect it. That is one
 * leaked backend per test — measured climbing to 21 across a 20-test Postgres suite before
 * this, and flat at 2 after — ending in `FATAL: sorry, too many clients already` charged to
 * whatever statement happened to be running. The leak was identical with and without
 * migrations, which is what proves it was never a migration problem.
 */
it('purges every connection the test opened', function (): void {
    DB::connection()->select('select 1');

    expect(DB::getConnections())->not->toBe([]);

    $this->tearDownInteractsWithMigrations();

    expect(DB::getConnections())->toBe([]);
});
