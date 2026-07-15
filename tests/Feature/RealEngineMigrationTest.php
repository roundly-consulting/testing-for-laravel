<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Testing\Assertions\Migrations\MigrationRunner;
use RoundlyConsulting\Testing\Tests\Support\RealEngineTestCase;

uses(RealEngineTestCase::class);

// ---------------------------------------------------------------------------
// Runs on every leg (sqlite_real is always available).
// ---------------------------------------------------------------------------

it('applies a clean migration order on a live connection', function (): void {
    expect(fixturePath('green/nineteen-edges'))->toApplyOnConnection('sqlite_real');
});

it('applies bare-constrained and references()->on() sets on a live connection', function (): void {
    expect(fixturePath('green/bare-constrained'))->toApplyOnConnection('sqlite_real')
        ->and(fixturePath('green/references-on'))->toApplyOnConnection('sqlite_real');
});

it('bites when a migration fails to apply', function (): void {
    // Two migrations create the same table — the engine rejects the second CREATE.
    expect(fn (): mixed => expect(fixturePath('broken/duplicate-table'))->toApplyOnConnection('sqlite_real'))
        ->toThrow(AssertionFailedError::class);
});

it('bites on a missing migrations directory', function (): void {
    expect(fn (): mixed => expect(fixturePath('green/does-not-exist'))->toApplyOnConnection('sqlite_real'))
        ->toThrow(AssertionFailedError::class);
});

it('passes the negative control when the engine rejects the broken order', function (): void {
    // Reversing alter-after-create runs the Schema::table() ALTER before its CREATE.
    // Even SQLite rejects "no such table", so the negative control turns green here.
    expect(fixturePath('green/alter-after-create'))
        ->toRejectBrokenOrderOnConnection(fn (array $files): array => array_reverse($files), 'sqlite_real');
});

it('fails loudly when the engine accepts the broken order', function (): void {
    // child-before-parent relies on FK enforcement at CREATE time, which SQLite lacks,
    // so SQLite ACCEPTS the broken order and the negative control must fail loudly.
    expect(fn (): mixed => expect(fixturePath('broken/child-before-parent'))
        ->toRejectBrokenOrderOnConnection(fn (array $files): array => $files, 'sqlite_real'))
        ->toThrow(AssertionFailedError::class);
});

it('bites when the reorder is not a permutation of the migration files', function (): void {
    expect(fn (): mixed => expect(fixturePath('green/nineteen-edges'))
        ->toRejectBrokenOrderOnConnection(fn (array $files): array => ['/does/not/exist.php'], 'sqlite_real'))
        ->toThrow(AssertionFailedError::class);
});

it('reports whether a connection is available', function (): void {
    expect(MigrationRunner::connectionIsAvailable('sqlite_real'))->toBeTrue()
        ->and(MigrationRunner::connectionIsAvailable('a-connection-that-is-not-configured'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// pgsql leg only — the real negative control the plan calls for. Skips visibly
// (never a silent green) when no postgres service is wired.
// ---------------------------------------------------------------------------

it('applies a clean order on postgres', function (): void {
    expect(fixturePath('green/nineteen-edges'))->toApplyOnConnection('pgsql');
})->skip(fn (): bool => ! test()->connectionAvailable('pgsql'), 'pgsql connection not available');

it('rejects a child-before-parent order on postgres', function (): void {
    // A real engine enforces the foreign key at CREATE time and refuses the order — the
    // negative control the structural pin can never be.
    expect(fixturePath('broken/child-before-parent'))
        ->toRejectBrokenOrderOnConnection(fn (array $files): array => $files, 'pgsql');
})->skip(fn (): bool => ! test()->connectionAvailable('pgsql'), 'pgsql connection not available');
