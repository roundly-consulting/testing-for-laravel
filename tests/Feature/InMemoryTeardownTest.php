<?php

declare(strict_types=1);

use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Database\Events\NoPendingMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Testing\Database\DriverMatrix;
use RoundlyConsulting\Testing\PackageTestCase;
use RoundlyConsulting\Testing\Tests\Support\FakePackageTestCase;

uses(FakePackageTestCase::class);

/**
 * The in-memory SQLite teardown no longer rolls the sources' migrations back. Testbench ran a
 * `migrate:rollback` per source there: with no `down()` to call it only deleted the migrations
 * rows of a database about to vanish with its connection — ~21 % of a SQLite worker's time in
 * tickets-for-laravel, for nothing a later test could observe.
 *
 * What must not change is pinned beside it: a migrator the test added itself still rolls back,
 * and the next test still starts on an empty database ({@see PackageTestCase}'s reset pairs).
 */

/**
 * Every `migrate:rollback` the teardown starts, by the event the migrator fires for it.
 *
 * @return list<string>
 */
function rollbacksDuring(Closure $teardown): array
{
    $rollbacks = [];

    Event::listen(MigrationsStarted::class, function (MigrationsStarted $event) use (&$rollbacks): void {
        if ($event->method === 'down') {
            $rollbacks[] = 'rolled back';
        }
    });

    Event::listen(NoPendingMigrations::class, function (NoPendingMigrations $event) use (&$rollbacks): void {
        if ($event->method === 'down') {
            $rollbacks[] = 'nothing to roll back';
        }
    });

    $teardown();

    return $rollbacks;
}

it('skips the rollback of the migration sources on in-memory SQLite', function (): void {
    expect(Schema::hasTable('fake_widgets'))->toBeTrue()
        ->and($this->cachedTestMigratorProcessors)->toHaveCount(1);

    expect(rollbacksDuring(fn () => $this->tearDownInteractsWithMigrations()))->toBe([])
        // Untouched: the database still holds the schema and every migrations row until it
        // vanishes with its connection.
        ->and(Schema::hasTable('fake_widgets'))->toBeTrue()
        ->and(DB::table('migrations')->count())->toBe(1)
        ->and($this->cachedTestMigratorProcessors)->toBe([]);
})->skip(fn (): bool => DriverMatrix::driver() !== 'sqlite', 'in-memory SQLite only');

it('still rolls back a migrator the test added itself', function (): void {
    $this->loadMigrationsFrom(fixturePath('green/bare-constrained'));

    expect($this->cachedTestMigratorProcessors)->toHaveCount(2)
        ->and(rollbacksDuring(fn () => $this->tearDownInteractsWithMigrations()))->toBe(['rolled back'])
        ->and($this->cachedTestMigratorProcessors)->toHaveCount(1);
})->skip(fn (): bool => DriverMatrix::driver() !== 'sqlite', 'in-memory SQLite only');

it('starts the next test on an empty in-memory database, as before', function (): void {
    // With the rollback gone, nothing but the connection resets the database — and the
    // connection is new for every test.
    expect(DB::table('fake_widgets')->count())->toBe(0);

    DB::table('fake_widgets')->insert(['name' => 'left behind']);
})->with(['first', 'second']);
