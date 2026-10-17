<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Testing\Database\DriverMatrix;
use RoundlyConsulting\Testing\Database\KeptSchema;
use RoundlyConsulting\Testing\Tests\Support\KeptSchemaTestCase;

uses(KeptSchemaTestCase::class);

/**
 * The counter half of the reset. Emptying a table does not rewind its key counter on any of the
 * three engines, so a reset that only deleted rows would hand the next test ids a fresh migrate
 * never would — the class of bug that makes an `id === 1` assertion pass alone and fail in a suite.
 */
it('leaves the counters advanced when only the rows are deleted — on this engine too', function (): void {
    // The negative control: without it, the pins below could pass on an engine whose counters
    // happen to restart on their own, and prove nothing about the rewind.
    $owner = DB::table('kept_owners')->insertGetId(['name' => 'Ada']);
    $thing = DB::table('numbered_things')->insertGetId(['label' => 'first']);

    DB::table('kept_owners')->delete();
    DB::table('numbered_things')->delete();

    expect(DB::table('kept_owners')->insertGetId(['name' => 'Bo']))->toBe($owner + 1)
        ->and(DB::table('numbered_things')->insertGetId(['label' => 'second']))->toBe($thing + 1);
});

it('puts every counter back where the migrate left it', function (): void {
    $schema = KeptSchema::capture(DB::connection(), 'pin', 'migrations');

    expect($schema)->not->toBeNull();

    DB::table('kept_owners')->insert([['name' => 'Ada'], ['name' => 'Bo']]);
    DB::table('numbered_things')->insert([['label' => 'a'], ['label' => 'b'], ['label' => 'c']]);

    expect($schema?->reset())->toBeTrue()
        ->and(DB::table('kept_owners')->insertGetId(['name' => 'Cy']))->toBe(1)
        // 100, not 1: back to the migrate's starting value — a rewind to 1 would be its own bug.
        ->and(DB::table('numbered_things')->insertGetId(['label' => 'd']))->toBe(100);
});

it('rewinds a counter a rolled-back insert advanced on a table left empty', function (): void {
    $schema = KeptSchema::capture(DB::connection(), 'pin', 'migrations');

    rescue(static fn () => DB::transaction(static function (): void {
        DB::table('kept_owners')->insert(['name' => 'Ada']);

        throw new RuntimeException('roll back');
    }), report: false);

    expect(DB::table('kept_owners')->count())->toBe(0)
        ->and($schema?->reset())->toBeTrue()
        ->and(DB::table('kept_owners')->insertGetId(['name' => 'Bo']))->toBe(1);
});

it('empties a child table and its parent without tripping the foreign key', function (): void {
    $schema = KeptSchema::capture(DB::connection(), 'pin', 'migrations');

    DB::table('kept_items')->insert([
        'kept_owner_id' => DB::table('kept_owners')->insertGetId(['name' => 'Ada']),
        'label' => 'child',
    ]);

    expect($schema?->reset())->toBeTrue()
        ->and(DB::table('kept_items')->count())->toBe(0)
        ->and(DB::table('kept_owners')->count())->toBe(0);
});

it('rolls back the transaction a test left open on the shared in-memory database', function (): void {
    $schema = KeptSchema::capture(DB::connection(), 'pin', 'migrations');

    DB::beginTransaction();
    DB::table('kept_owners')->insert(['name' => 'Ada']);

    expect($schema?->reset())->toBeTrue()
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse()
        ->and(DB::table('kept_owners')->count())->toBe(0);
})->skip(fn (): bool => DriverMatrix::driver() !== 'sqlite', 'a real engine closes the session, and its transaction, before the reset');

it('closes the test\'s sessions before the reset, so an open transaction neither blocks nor survives it', function (): void {
    DB::beginTransaction();
    DB::table('kept_owners')->insert(['name' => 'Ada']);
    DB::table('numbered_things')->insert(['label' => 'locked']);

    // On Postgres the TRUNCATE would wait forever on the row locks of a session left open.
    $this->tearDownInteractsWithMigrations();
    $this->defineDatabaseMigrations();

    expect(DB::connection()->transactionLevel())->toBe(0)
        ->and(DB::table('kept_owners')->count())->toBe(0)
        ->and(DB::table('numbered_things')->insertGetId(['label' => 'next']))->toBe(100);
});
