<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Testing\Database\DriverMatrix;
use RoundlyConsulting\Testing\Tests\Support\RealEngineTestCase;

uses(RealEngineTestCase::class);

/**
 * The live proof that the real-engine probe cannot reach the suite's schema.
 *
 * The probe and the suite are the same engine at the same TESTING_DB_* location, so on
 * the matching leg (TESTING_DB_DRIVER=pgsql) they were byte-identical: one physical
 * database, two PDO sessions. MigrationRunner drops its target clean on entry and again
 * in `finally`, so every `toApplyOnConnection('pgsql')` / `toRejectBrokenOrderOnConnection`
 * dropped the *live suite's* tables out from under it. Random execution order decided
 * whether that landed on a test that still needed them, which is why the leg looked green
 * some runs and lost 173 tests on others.
 *
 * These cases are deliberately NOT seed-dependent: each one plants a table on the suite's
 * own connection, runs the probe, and asserts the table is still standing. They fail on
 * the pre-fix code every single run.
 */

/**
 * The suite's default connection, planted with a table the probe must never see.
 */
function plantSuiteCanary(string $name): void
{
    Schema::create($name, function (Blueprint $table): void {
        $table->id();
    });

    expect(Schema::hasTable($name))->toBeTrue("failed to plant the {$name} canary");
}

/**
 * The collision only exists on the leg where the suite runs on the same engine as the
 * probe — off it the suite is sqlite `:memory:` and the two could never have touched, so
 * these would pass without proving anything. Skip *visibly* instead of banking a vacuous
 * green, and gate on the engine being reachable so the leg cannot lie either.
 */
$needsPostgresLeg = fn (): bool => DriverMatrix::driver() !== 'pgsql'
    || ! test()->connectionAvailable('pgsql');

$needsPostgres = fn (): bool => ! test()->connectionAvailable('pgsql');

it('leaves the suite schema standing when a probe applies migrations', function (): void {
    plantSuiteCanary('suite_canary_apply');

    expect(fixturePath('green/nineteen-edges'))->toApplyOnConnection('pgsql');

    expect(Schema::hasTable('suite_canary_apply'))
        ->toBeTrue('the probe dropped the live suite\'s tables — probe and suite share a schema');
})->skip($needsPostgresLeg, 'pgsql driver leg only — the probe and suite share an engine only there');

it('leaves the suite schema standing when a probe runs the negative control', function (): void {
    // The negative control calls the same runFiles() — and therefore the same
    // drop-on-entry/drop-in-finally — as the positive one. Skipping the redundant
    // apply on the matching leg would NOT have fixed this path, which is why the probe
    // is isolated rather than conditionally skipped.
    plantSuiteCanary('suite_canary_reject');

    expect(fixturePath('broken/child-before-parent'))
        ->toRejectBrokenOrderOnConnection(fn (array $files): array => $files, 'pgsql');

    expect(Schema::hasTable('suite_canary_reject'))
        ->toBeTrue('the negative control dropped the live suite\'s tables');
})->skip($needsPostgresLeg, 'pgsql driver leg only — the probe and suite share an engine only there');

it('creates the probe schema and keeps it disjoint from the suite schema', function (): void {
    plantSuiteCanary('suite_canary_disjoint');

    expect(fixturePath('green/nineteen-edges'))->toApplyOnConnection('pgsql');

    // The schema the suite occupies when it is itself on postgres — read from the matrix
    // rather than from the live `testing` connection, which is sqlite on every other leg.
    $suiteSchema = DriverMatrix::connectionConfig('pgsql')['search_path'];
    $probeSchema = Schema::connection('pgsql')->getCurrentSchemaListing();

    expect($probeSchema)->toBe([DriverMatrix::PROBE_NAMESPACE])
        ->and($probeSchema)->not->toContain($suiteSchema);

    // Nominal isolation is not isolation: the schema must actually exist on the engine,
    // and the suite's must still be there beside it.
    $schemas = array_column(DB::connection('pgsql')->select(
        'select nspname from pg_namespace where nspname in (?, ?)',
        [DriverMatrix::PROBE_NAMESPACE, $suiteSchema],
    ), 'nspname');

    expect($schemas)->toContain(DriverMatrix::PROBE_NAMESPACE, $suiteSchema);
})->skip($needsPostgres, 'pgsql connection not available');

it('drops only the probe schema, never the suite table beside it', function (): void {
    // Isolation proven at the drop itself: the operation that caused the bug, run
    // directly, must leave the suite's table standing.
    plantSuiteCanary('suite_canary_drop');

    DriverMatrix::prepareProbe('pgsql');

    Schema::connection('pgsql')->create('probe_only', function (Blueprint $table): void {
        $table->id();
    });

    expect(Schema::connection('pgsql')->hasTable('probe_only'))->toBeTrue();

    Schema::connection('pgsql')->dropAllTables();

    expect(Schema::connection('pgsql')->hasTable('probe_only'))->toBeFalse()
        ->and(Schema::hasTable('suite_canary_drop'))->toBeTrue('dropAllTables on the probe reached the suite');
})->skip($needsPostgresLeg, 'pgsql driver leg only — the probe and suite share an engine only there');

it('prepares the probe schema without touching the suite connection', function (): void {
    // prepareProbe must be a no-op for anything that is not an isolated probe, so it can
    // never create a schema on — or otherwise disturb — the connection the suite runs on.
    plantSuiteCanary('suite_canary_prepare');

    DriverMatrix::prepareProbe('testing');
    DriverMatrix::prepareProbe('sqlite_real');

    expect(Schema::hasTable('suite_canary_prepare'))->toBeTrue();
})->skip($needsPostgresLeg, 'pgsql driver leg only — the probe and suite share an engine only there');
