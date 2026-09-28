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

// ---------------------------------------------------------------------------
// Guard the guard — an apply that cannot fail is not a check. Both of these
// came from the deleted rollback pin, which is where they earned their keep.
// ---------------------------------------------------------------------------

it('passes when the optional migration count pin matches', function (): void {
    expect(fixturePath('green/bare-constrained'))->toApplyOnConnection('sqlite_real', migrations: 2);
});

it('bites when the migration count does not match the pin', function (): void {
    expect(fn (): mixed => expect(fixturePath('green/bare-constrained'))
        ->toApplyOnConnection('sqlite_real', migrations: 99))
        ->toThrow(AssertionFailedError::class, 'Expected 99 migrations');
});

it('bites on a set that applies but creates nothing', function (): void {
    // "The migrations applied cleanly" is true of a migration whose up() is empty. Without
    // this guard that is a green — the vacuous pass this package exists to kill.
    expect(fn (): mixed => expect(fixturePath('creates-nothing'))->toApplyOnConnection('sqlite_real'))
        ->toThrow(AssertionFailedError::class, 'created no tables');
});

// ---------------------------------------------------------------------------
// The loader guards: a file the loader cannot use must fail BY NAME, never be
// quietly skipped — a silently dropped migration is the whole bug class here.
// ---------------------------------------------------------------------------

it('bites on a file that is not a migration at all', function (): void {
    expect(fn (): mixed => expect(fixturePath('loader/not-a-migration'))->toApplyOnConnection('sqlite_real'))
        ->toThrow(AssertionFailedError::class, 'did not return a runnable Migration instance');
});

it('bites on a migration that declares no up()', function (): void {
    // `Migration` declares no up(), and Migrator guards the call with method_exists, so
    // only an explicit guard catches a migration that forgot one.
    expect(fn (): mixed => expect(fixturePath('loader/no-up'))->toApplyOnConnection('sqlite_real'))
        ->toThrow(AssertionFailedError::class, 'declares no up()');
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

// ---------------------------------------------------------------------------
// The negative control must not pass for a reason other than the order. An
// unreachable engine, or an error no order could avoid, is not a rejection.
// ---------------------------------------------------------------------------

it('fails the negative control when the engine is unreachable', function (): void {
    // Not even broken: the identity "reorder". An engine that cannot be reached rejects
    // everything, which used to read as "rejected the broken order" — a vacuous green.
    expect(fn (): mixed => expect(fixturePath('green/nineteen-edges'))
        ->toRejectBrokenOrderOnConnection(fn (array $files): array => $files, 'sqlite_unreachable'))
        ->toThrow(AssertionFailedError::class, 'not reachable');
});

it('fails the negative control when the engine refuses for a reason unrelated to order', function (): void {
    expect(fn (): mixed => expect(fixturePath('broken/unrelated-sql-error'))
        ->toRejectBrokenOrderOnConnection(fn (array $files): array => $files, 'sqlite_real'))
        ->toThrow(AssertionFailedError::class, 'not an ordering error');
});

it('fails the apply on an unreachable engine with an assertion, not a crash', function (): void {
    expect(fn (): mixed => expect(fixturePath('green/nineteen-edges'))->toApplyOnConnection('sqlite_unreachable'))
        ->toThrow(AssertionFailedError::class, 'not reachable');
});

it('leaves the default connection alone when a run cannot even start', function (): void {
    $default = config('database.default');

    try {
        MigrationRunner::brokenOrderIsRejectedOnConnection(
            fixturePath('green/nineteen-edges'),
            fn (array $files): array => $files,
            'sqlite_unreachable',
        );
    } catch (AssertionFailedError) {
        // expected — the point is the state it leaves behind
    }

    try {
        MigrationRunner::applyOnConnection(fixturePath('green/nineteen-edges'), 'sqlite_unreachable');
    } catch (AssertionFailedError) {
        // expected
    }

    expect(config('database.default'))->toBe($default);
});

it('fails the negative control when a postgres engine is down, instead of counting the refusal', function (): void {
    // The exact scenario from the review: a pgsql probe aimed at a closed port. Every
    // statement fails with "connection refused" — a QueryException — which used to read as
    // the engine rejecting the order, and left the default connection switched to pgsql.
    config()->set('database.connections.pgsql_down', [...config('database.connections.pgsql'), 'port' => '1']);
    $default = config('database.default');

    expect(fn (): mixed => expect(fixturePath('green/nineteen-edges'))
        ->toRejectBrokenOrderOnConnection(fn (array $files): array => $files, 'pgsql_down'))
        ->toThrow(AssertionFailedError::class, 'not reachable')
        ->and(config('database.default'))->toBe($default);
})->skip(fn (): bool => ! extension_loaded('pdo_pgsql'), 'pdo_pgsql is not installed');
