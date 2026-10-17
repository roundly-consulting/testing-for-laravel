<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Testing\Database\KeptSchema;
use RoundlyConsulting\Testing\Tests\Support\FileDatabaseKeptSchemaTestCase;

uses(FileDatabaseKeptSchemaTestCase::class);

/**
 * Opting in on an engine the harness cannot keep — a file-backed SQLite database here — keeps
 * the drop-per-test reset in charge: every test migrates and drops, as without the opt-in.
 */
it('drops the schema between tests on a database it cannot keep (first)', function (): void {
    expect(KeptSchema::supports(DB::connection()))->toBeFalse()
        ->and(Schema::hasTable('fake_widgets'))->toBeTrue()
        ->and(DB::table('fake_widgets')->count())->toBe(0);

    DB::table('fake_widgets')->insert(['name' => 'first']);
});

it('drops the schema between tests on a database it cannot keep (second)', function (): void {
    expect(Schema::hasTable('fake_widgets'))->toBeTrue()
        ->and(DB::table('fake_widgets')->count())->toBe(0);

    DB::table('fake_widgets')->insert(['name' => 'second']);
});
