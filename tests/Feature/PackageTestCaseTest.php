<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Testing\Database\DriverMatrix;
use RoundlyConsulting\Testing\Tests\Support\FakePackageTestCase;
use RoundlyConsulting\Testing\Tests\Support\FakeWidget;

uses(FakePackageTestCase::class);

it('runs a package provider migrations loaded by class', function (): void {
    expect(Schema::hasTable('fake_widgets'))->toBeTrue();
});

it('turns sqlite foreign key constraints on', function (): void {
    // Only meaningful where the default connection *is* sqlite: postgres enforces foreign
    // keys unconditionally and has no such setting. Skipping visibly on the pgsql leg beats
    // asserting a key that leg cannot have.
    expect(config('database.connections.testing.foreign_key_constraints'))->toBeTrue();
})->skip(fn (): bool => DriverMatrix::driver() !== 'sqlite', 'sqlite leg only');

it('applies configBeforeBoot values before the providers boot', function (): void {
    expect(config('fake.enabled'))->toBeTrue();
});

it('applies a configured model swap before the providers boot', function (): void {
    expect(config('fake.widget_model'))->toBe(FakeWidget::class);
});

// ---------------------------------------------------------------------------
// Real-engine wiring — the half an adopting package needs and could not reach.
// ---------------------------------------------------------------------------

it('ships the connectionAvailable() mirror the README promises', function (): void {
    expect($this->connectionAvailable('testing'))->toBeTrue()
        ->and($this->connectionAvailable('a-connection-that-is-not-configured'))->toBeFalse();
});

it('registers the real-engine connections so an R gate can skip visibly', function (): void {
    // Present but (off a CI driver leg) unreachable is the whole point: a gate on a
    // connection that is not configured at all can never fire, so every R row in every
    // adopting package would skip silently forever.
    expect(config('database.connections.pgsql'))->toBe(DriverMatrix::probeConnectionConfig('pgsql'))
        ->and(config('database.connections.mysql'))->toBe(DriverMatrix::probeConnectionConfig('mysql'));
});

it('registers the real-engine connections isolated from the suite connection', function (): void {
    // The regression pin for the probe/suite collision. These were registered from
    // connectionConfig(), so on the matching leg (TESTING_DB_DRIVER=pgsql) the `testing`
    // and `pgsql` connections were byte-identical — one physical database, two PDO
    // sessions — and MigrationRunner's drop-on-entry/drop-in-finally took the live
    // suite's tables with it.
    //
    // Runs on EVERY leg and touches no engine: random execution order hid the original
    // bug for weeks, so this pin must not depend on a seed or on a service being up.
    expect(config('database.connections.pgsql.search_path'))->toBe(DriverMatrix::PROBE_NAMESPACE)
        ->and(config('database.connections.mysql.database'))->toBe(DriverMatrix::PROBE_NAMESPACE);
});

it('routes the default connection through the driver matrix', function (): void {
    // TESTING_DB_DRIVER defaults to sqlite, so this is the no-behaviour-change guarantee
    // off the pgsql leg — and the whole suite runs on postgres on it.
    expect(config('database.default'))->toBe('testing')
        ->and(config('database.connections.testing'))->toBe(DriverMatrix::connectionConfig())
        ->and(config('database.connections.testing.driver'))->toBe(DriverMatrix::driver());
});
