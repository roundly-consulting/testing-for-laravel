<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Testing\Database\DriverMatrix;
use RoundlyConsulting\Testing\PackageTestCase;
use RoundlyConsulting\Testing\Tests\Support\FakePackageTestCase;

uses(FakePackageTestCase::class);

/**
 * The per-test state reset, which {@see PackageTestCase} does by
 * dropping every table rather than by rolling the migrations back.
 *
 * The fixture package it runs against ships **no `down()`** — deliberately, because that is
 * what every roundly package ships (the developer standard forbids `down()`). So these tests
 * only pass if the reset is genuinely `down()`-free. Before the drop-based reset, this exact
 * fixture put the pgsql leg 22 red on `relation "..." already exists`: Testbench asked for a
 * rollback, `Migrator` skipped the absent `down()` in silence, the table survived, and the
 * next test died creating it again.
 *
 * On SQLite `:memory:` none of this is needed — the database dies with the connection — so
 * that path is left exactly as Testbench wrote it. These tests are green either way, which is
 * the point: the reset is invisible to the suite, on every leg.
 */

// ---------------------------------------------------------------------------
// The reset itself. These two are the pair: whichever order Pest runs them in,
// each must see a schema and a table that the other did not leave behind.
// ---------------------------------------------------------------------------

it('gives each test a freshly migrated schema (first of the pair)', function (): void {
    expect(Schema::hasTable('fake_widgets'))->toBeTrue();

    DB::table('fake_widgets')->insert(['name' => 'first']);

    expect(DB::table('fake_widgets')->count())->toBe(1);
});

it('gives each test a freshly migrated schema (second of the pair)', function (): void {
    // If the previous test's schema survived teardown, its row survives with it and this
    // count is 2 — the leak the drop-based reset exists to close, made visible as data
    // rather than as a duplicate-table error naming an innocent migration.
    expect(Schema::hasTable('fake_widgets'))->toBeTrue()
        ->and(DB::table('fake_widgets')->count())->toBe(0);

    DB::table('fake_widgets')->insert(['name' => 'second']);

    expect(DB::table('fake_widgets')->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// The constraint that rules out the obvious alternative.
// ---------------------------------------------------------------------------

it('opens no transaction of its own, so a test observes its own depth', function (): void {
    // RefreshDatabase would reset state by wrapping each test in a transaction — and that
    // extra level is not free here: LockRecorder records `transactionDepth`, the datum that
    // condemned the deleted LockedUpdate helper (its lock landed in a savepoint released
    // before the ledger write). A drop is pure DDL and opens nothing, so depth starts at 0.
    expect(DB::connection()->transactionLevel())->toBe(0);

    DB::transaction(function (): void {
        expect(DB::connection()->transactionLevel())->toBe(1);

        DB::transaction(function (): void {
            expect(DB::connection()->transactionLevel())->toBe(2);
        });
    });

    expect(DB::connection()->transactionLevel())->toBe(0);
});

// ---------------------------------------------------------------------------
// Guard the proof.
// ---------------------------------------------------------------------------

it('reaches the drop-based branch on a real engine, and the sqlite branch on sqlite', function (): void {
    // The reset only has to do anything where the database outlives the connection. Pin
    // which branch this leg takes, so a green run cannot be green for the wrong reason:
    // on the sqlite leg these tests would pass even with the reset removed entirely.
    $sqlite = DriverMatrix::driver() === 'sqlite';

    expect(config('database.connections.testing.driver'))->toBe($sqlite ? 'sqlite' : DriverMatrix::driver());

    if (! $sqlite) {
        expect(config('database.connections.testing.database'))->not->toBe(':memory:');
    }
});

it('ships no down() in any fixture migration', function (): void {
    // The whole proof rests on this: these fixtures model what the fleet ships, and the
    // fleet ships no down(). One down() added back here and the pgsql leg goes green
    // through Testbench's rollback again — proving nothing about the drop-based reset,
    // while quietly contradicting the standard this package exists to enforce.
    $offenders = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../Fixtures')) as $file) {
        if ($file->getExtension() !== 'php' || ! str_contains($file->getPath(), 'migrations')) {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (str_contains($source, 'function down(')) {
            $offenders[] = $file->getFilename();
        }
    }

    expect($offenders)->toBe([]);
});
