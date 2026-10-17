<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Testing\Database\KeptSchema;
use RoundlyConsulting\Testing\Tests\Support\KeptSchemaWithoutMigrationsTestCase;

uses(KeptSchemaWithoutMigrationsTestCase::class);

/**
 * A test case that keeps its schema but has no migration sources. Nothing creates a migrations
 * table, so there is no migrated schema to keep: every test runs on the drop-per-test reset, as
 * it would without the opt-in — and a table a test builds by hand is gone before the next one.
 */
it('runs a test that touches the database', function (): void {
    // Not even another test case's kept schema: on in-memory SQLite the worker may still hold
    // one, but this test opens a database of its own.
    expect(DB::connection()->scalar('select 1'))->toEqual(1)
        ->and(Schema::getTables(Schema::getCurrentSchemaName()))->toBe([]);
});

it('drops a fixture table a test built by hand', function (): void {
    expect(Schema::hasTable('hand_built'))->toBeFalse();

    Schema::create('hand_built', static function (Blueprint $table): void {
        $table->id();
        $table->string('label');
    });

    expect(DB::table('hand_built')->insertGetId(['label' => 'only']))->toBe(1);
})->with(['first', 'second']);

it('captures nothing from a database without a migrations table', function (): void {
    Schema::create('hand_built', static function (Blueprint $table): void {
        $table->id();
    });

    expect(KeptSchema::capture(DB::connection(), 'pin', 'migrations'))->toBeNull();
});
