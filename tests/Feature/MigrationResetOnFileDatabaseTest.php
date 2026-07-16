<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Testing\Tests\Support\FileDatabaseTestCase;

uses(FileDatabaseTestCase::class);

/**
 * The drop-based reset, driven on **every** leg.
 *
 * {@see FileDatabaseTestCase} points the default connection at a file-backed SQLite database.
 * That is not `:memory:`, so it takes the same real-engine branch Postgres takes — the branch
 * that drops every table instead of asking for the `down()`-based rollback roundly packages
 * cannot serve. The fixture package here ships no `down()`, so these only pass if the reset is
 * genuinely rollback-free.
 *
 * Postgres proves this too, but only on a job with no coverage measurement and no local engine
 * for most developers. This proves it with nothing but a temp file.
 */
it('drops the schema between tests on a database that outlives the connection (first)', function (): void {
    // The database is a real file, so nothing here dies with the connection.
    expect($this->databaseFile())->toBeFile()
        ->and(config('database.connections.testing.database'))->not->toBe(':memory:');

    expect(Schema::hasTable('fake_widgets'))->toBeTrue();

    DB::table('fake_widgets')->insert(['name' => 'first']);

    expect(DB::table('fake_widgets')->count())->toBe(1);
});

it('drops the schema between tests on a database that outlives the connection (second)', function (): void {
    // Remove the drop from PackageTestCase and this is where it shows: Testbench asks for a
    // rollback, Migrator skips the absent down() in silence, `fake_widgets` survives with its
    // row, and this fails — exactly the class that put the pgsql leg 22 red.
    expect(Schema::hasTable('fake_widgets'))->toBeTrue()
        ->and(DB::table('fake_widgets')->count())->toBe(0);

    DB::table('fake_widgets')->insert(['name' => 'second']);

    expect(DB::table('fake_widgets')->count())->toBe(1);
});

it('opens no transaction on the real-engine branch either', function (): void {
    // The drop is DDL, so the depth a test observes is its own — the LockRecorder invariant.
    expect(DB::connection()->transactionLevel())->toBe(0);
});
