<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Testing\Database\DriverMatrix;
use RoundlyConsulting\Testing\Database\KeptSchema;
use RoundlyConsulting\Testing\Database\SchemaCatalogue;
use RoundlyConsulting\Testing\Tests\Fixtures\KeptSchema\MigrationRuns;
use RoundlyConsulting\Testing\Tests\Support\KeptSchemaTestCase;

uses(KeptSchemaTestCase::class);

/**
 * The catalogue check: a test that changed the schema is never reused. Each case captures the
 * schema the test was handed, changes it, and the reset must refuse — the caller then throws the
 * schema away and the next test migrates from scratch. What the check covers, and what it does
 * not, is listed on {@see SchemaCatalogue}; the gaps are pinned here too, so that list stays true.
 */
function capturedKeptSchema(): KeptSchema
{
    $schema = KeptSchema::capture(DB::connection(), 'pin', 'migrations');

    expect($schema)->not->toBeNull();

    return $schema ?? throw new LogicException('unreachable');
}

/**
 * One statement per engine — DDL the schema builder has no fluent form for.
 *
 * @param  array{sqlite?: string, pgsql?: string, mysql?: string}  $statements
 */
function onEngine(array $statements): void
{
    $statement = $statements[DriverMatrix::driver()] ?? null;

    if ($statement !== null) {
        DB::unprepared($statement);
    }
}

it('resets a schema the test only wrote rows to', function (): void {
    $schema = capturedKeptSchema();

    DB::table('kept_owners')->insert(['name' => 'Ada']);

    expect($schema->reset())->toBeTrue()
        ->and(DB::table('kept_owners')->count())->toBe(0);
});

it('refuses to reset a schema the test changed', function (Closure $change): void {
    $schema = capturedKeptSchema();

    // A change may hand back its own undo, for what the drop-per-test reset leaves behind too.
    $undo = $change();

    try {
        expect($schema->reset())->toBeFalse();
    } finally {
        if ($undo instanceof Closure) {
            $undo();
        }
    }
})->with([
    'a new column' => [fn () => Schema::table('kept_owners', static fn (Blueprint $table) => $table->string('nickname')->nullable())],
    'a renamed column' => [fn () => Schema::table('kept_owners', static fn (Blueprint $table) => $table->renameColumn('email', 'mail'))],
    'a changed column' => [fn () => Schema::table('kept_owners', static fn (Blueprint $table) => $table->string('email')->nullable()->default('none')->change())],
    'a new index' => [fn () => Schema::table('kept_owners', static fn (Blueprint $table) => $table->index('email'))],
    'a dropped unique index' => [fn () => Schema::table('kept_items', static fn (Blueprint $table) => $table->dropUnique(['label']))],
    'a dropped table' => [fn () => Schema::drop('numbered_things')],
    'a new table' => [fn () => Schema::create('stray_things', static fn (Blueprint $table) => $table->id())],
    'a deleted migrations row' => [fn () => DB::table('migrations')->where('id', 1)->delete()],
    'a new migrations row' => [fn () => DB::table('migrations')->insert(['migration' => 'stray', 'batch' => 9])],
    'a dropped migrations table' => [fn () => Schema::drop('migrations')],
    'a new view' => [function (): Closure {
        DB::statement('create view kept_owner_names as select name from kept_owners');

        return static fn () => DB::statement('drop view kept_owner_names');
    }],
    'a new trigger' => [function (): Closure {
        onEngine([
            'sqlite' => 'create trigger kept_owners_touch after insert on kept_owners begin select 1; end',
            'mysql' => 'create trigger kept_owners_touch before insert on kept_owners for each row set new.email = new.email',
            'pgsql' => 'create or replace function kept_touch() returns trigger language plpgsql as $$ begin return new; end $$; '
                .'create trigger kept_owners_touch before insert on kept_owners for each row execute function kept_touch()',
        ]);

        return static fn () => onEngine(['pgsql' => 'drop function kept_touch() cascade']);
    }],
]);

it('refuses to reset an in-memory database the test left foreign keys off on, or reached past', function (Closure $change): void {
    $schema = capturedKeptSchema();

    $change();

    expect($schema->reset())->toBeFalse();
})->with([
    'foreign keys off' => [fn () => Schema::disableForeignKeyConstraints()],
    'a temp table' => [fn () => DB::statement('create temp table scratch (id integer)')],
    'an attached database' => [fn () => DB::statement("attach database ':memory:' as aux")],
])->skip(fn (): bool => DriverMatrix::driver() !== 'sqlite', 'a real engine scopes these to the session, which closes after every test');

it('refuses to reset a postgres schema that grew a sequence', function (): void {
    $schema = capturedKeptSchema();

    DB::statement('create sequence stray_numbers');

    try {
        expect($schema->reset())->toBeFalse();
    } finally {
        // A standalone sequence outlives the drop of every table.
        DB::statement('drop sequence stray_numbers');
    }
})->skip(fn (): bool => DriverMatrix::driver() !== 'pgsql', 'postgres only');

it('does not see what the catalogue leaves out, as documented', function (): void {
    $schema = capturedKeptSchema();

    // Each of these survives the drop-per-test reset too: a test that makes one must undo it.
    onEngine([
        'sqlite' => 'pragma recursive_triggers = on',
        'mysql' => 'create procedure kept_gap() begin select 1; end',
        'pgsql' => "create type kept_gap as enum ('a', 'b')",
    ]);

    try {
        expect($schema->reset())->toBeTrue();
    } finally {
        onEngine([
            'sqlite' => 'pragma recursive_triggers = off',
            'mysql' => 'drop procedure kept_gap',
            'pgsql' => 'drop type kept_gap',
        ]);
    }
});

it('migrates from scratch for the test after one that changed the schema', function (): void {
    $runs = MigrationRuns::of('kept');

    Schema::table('kept_owners', static fn (Blueprint $table) => $table->string('nickname')->nullable());

    $this->tearDownInteractsWithMigrations();

    expect(KeptSchema::current())->toBeNull();

    $this->defineDatabaseMigrations();

    expect(MigrationRuns::of('kept'))->toBe($runs + 1)
        ->and(Schema::hasColumn('kept_owners', 'nickname'))->toBeFalse()
        ->and(KeptSchema::current())->not->toBeNull();
});
