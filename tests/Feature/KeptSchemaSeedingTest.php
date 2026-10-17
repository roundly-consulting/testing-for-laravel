<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Testing\Database\KeptSchema;
use RoundlyConsulting\Testing\Tests\Fixtures\KeptSchema\MigrationRuns;
use RoundlyConsulting\Testing\Tests\Support\SeedingKeptSchemaTestCase;

uses(SeedingKeptSchemaTestCase::class);

/**
 * Migrations that write rows. A kept schema would be emptied after the first test, and every
 * later test would start without the rows a fresh migrate gives it — so the harness sees the rows
 * after the migrate and keeps the drop-per-test reset for that schema instead.
 */
function seedingRunsBefore(?int $runs = null): ?int
{
    static $last = null;

    $previous = $last;
    $last = $runs ?? $last;

    return $previous;
}

it('gives every test the rows its migrations seeded', function (): void {
    expect(DB::table('seeded_settings')->orderBy('id')->pluck('key')->all())->toBe(['locale', 'timezone']);

    DB::table('seeded_settings')->where('key', 'locale')->delete();
    DB::table('seeded_settings')->insert(['key' => 'currency']);
})->with(['first', 'second', 'third']);

it('migrates for every test once the migrations seeded rows', function (): void {
    $runs = MigrationRuns::of('seeding');
    $previous = seedingRunsBefore($runs);

    expect(KeptSchema::current())->toBeNull();

    if ($previous !== null) {
        expect($runs)->toBeGreaterThan($previous);
    }
})->with(['first', 'second', 'third']);

it('captures nothing from a database with rows in it', function (): void {
    expect(KeptSchema::capture(DB::connection(), 'pin', 'migrations'))->toBeNull();
});
