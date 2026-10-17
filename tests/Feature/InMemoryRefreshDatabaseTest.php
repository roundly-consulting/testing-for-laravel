<?php

declare(strict_types=1);

use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Testing\Tests\Support\InMemoryRefreshDatabaseTestCase;

uses(InMemoryRefreshDatabaseTestCase::class);

/**
 * `RefreshDatabase` owns the reset, so neither the kept schema nor the skipped in-memory rollback
 * applies to it: Testbench's own teardown runs exactly as Testbench wrote it.
 */
it('leaves an in-memory RefreshDatabase suite to Testbench, opt-in or not', function (): void {
    expect(Schema::hasTable('fake_widgets'))->toBeTrue()
        ->and(DB::connection()->transactionLevel())->toBe(1);

    // Migrated now, so the sources get a migrator Testbench rolls back at teardown — untouched.
    $this->defineDatabaseMigrations();

    $rolledBack = false;
    Event::listen(MigrationsStarted::class, function (MigrationsStarted $event) use (&$rolledBack): void {
        $rolledBack = $rolledBack || $event->method === 'down';
    });

    $this->tearDownInteractsWithMigrations();

    expect($rolledBack)->toBeTrue();
});
