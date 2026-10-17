<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Testing\Database\DriverMatrix;
use RoundlyConsulting\Testing\Database\KeptSchema;
use RoundlyConsulting\Testing\PackageTestCase;
use RoundlyConsulting\Testing\Tests\Fixtures\KeptSchema\MigrationRuns;
use RoundlyConsulting\Testing\Tests\Support\KeptSchemaTestCase;

uses(KeptSchemaTestCase::class);

/**
 * {@see PackageTestCase::keepsMigratedSchema()} on whichever engine the leg runs: a worker
 * migrates once, and every test still starts where a fresh migrate leaves it — empty tables,
 * every key counter where the migrations left it, no transaction around the test.
 *
 * Pest runs a file's tests one after another in one process, so the tests below see each
 * other's leftovers if the reset misses any — whatever order they run in.
 */

/**
 * The first schema signature and migrate count this file saw, so the others can compare.
 *
 * @return array{signature: string|null, runs: int|null}
 */
function keptSchemaFirstSeen(?string $signature = null, ?int $runs = null): array
{
    static $seen = ['signature' => null, 'runs' => null];

    $seen['signature'] ??= $signature;
    $seen['runs'] ??= $runs;

    return $seen;
}

it('runs the test outside any transaction', function (): void {
    expect(DB::connection()->transactionLevel())->toBe(0);
});

it('starts every test on empty tables with every counter as the migrate left it', function (): void {
    expect(DB::table('kept_owners')->count())->toBe(0)
        ->and(DB::table('kept_items')->count())->toBe(0)
        ->and(DB::table('numbered_things')->count())->toBe(0);

    $owner = DB::table('kept_owners')->insertGetId(['name' => 'Ada']);
    $item = DB::table('kept_items')->insertGetId(['kept_owner_id' => $owner, 'label' => 'only']);
    $thing = DB::table('numbered_things')->insertGetId(['label' => 'first']);

    // Ids a fresh migrate hands out: 1, 1, and the 100 the migration started its counter at.
    expect($owner)->toBe(1)->and($item)->toBe(1)->and($thing)->toBe(100);

    DB::table('kept_owners')->insert([['name' => 'Bo'], ['name' => 'Cy']]);
    DB::table('numbered_things')->insert(['label' => 'second']);

    // A rolled-back insert still spends a key on a real engine — on a table left empty.
    rescue(static fn () => DB::transaction(static function (): void {
        DB::table('kept_items')->insert(['kept_owner_id' => 1, 'label' => 'rolled back']);

        throw new RuntimeException('roll back');
    }), report: false);
})->with(['first', 'second', 'third']);

it('migrates once for every test that shares the signature', function (): void {
    $kept = KeptSchema::current();

    expect($kept)->not->toBeNull();

    $seen = keptSchemaFirstSeen($kept?->signature, MigrationRuns::of('kept'));

    expect($kept?->signature)->toBe($seen['signature'])
        ->and(MigrationRuns::of('kept'))->toBe($seen['runs']);
})->with(['first', 'second', 'third']);

it('hands the next test the reset schema without migrating again', function (): void {
    $runs = MigrationRuns::of('kept');
    $kept = KeptSchema::current();

    DB::table('kept_items')->insert([
        'kept_owner_id' => DB::table('kept_owners')->insertGetId(['name' => 'Ada']),
        'label' => 'left behind',
    ]);
    DB::table('numbered_things')->insert(['label' => 'left behind']);

    // The boundary between two tests, inside one: the teardown resets, the next setUp attaches.
    $this->tearDownInteractsWithMigrations();
    $this->defineDatabaseMigrations();

    expect(MigrationRuns::of('kept'))->toBe($runs)
        ->and(KeptSchema::current())->toBe($kept)
        ->and(DB::table('kept_owners')->count())->toBe(0)
        ->and(DB::table('kept_items')->count())->toBe(0)
        ->and(DB::table('kept_owners')->insertGetId(['name' => 'Bo']))->toBe(1)
        ->and(DB::table('numbered_things')->insertGetId(['label' => 'again']))->toBe(100);
});

it('keeps the schema in the worker database, or in the in-memory one the test is handed', function (): void {
    $connection = DB::connection();

    if (DriverMatrix::driver() === 'sqlite') {
        expect($connection->getConfig('database'))->toBe(':memory:');

        return;
    }

    // On a real engine the kept tables live where the drop-per-test reset would have dropped them.
    expect($connection->getDatabaseName())->toBe(DriverMatrix::connectionConfig()['database'])
        ->and($connection->getSchemaBuilder()->hasTable('kept_owners'))->toBeTrue();
});
