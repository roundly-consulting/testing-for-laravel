<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Testing\Assert;
use RoundlyConsulting\Testing\Tests\Support\RealEngineTestCase;

uses(RealEngineTestCase::class);

/**
 * The failure message from a rollback assertion, or '' if it (wrongly) passed.
 */
function rollbackFailure(string $fixture, int $migrations, ?string $connection = null): string
{
    try {
        expect(fixturePath('rollback/'.$fixture))->toRollBackCleanly($migrations, $connection);
    } catch (AssertionFailedError $error) {
        return $error->getMessage();
    }

    return '';
}

// ---------------------------------------------------------------------------
// The structural half — no engine, so it runs on every leg. This is the half
// that protects the fleet.
// ---------------------------------------------------------------------------

it('passes a set where every migration declares a real down()', function (): void {
    expect(fixturePath('rollback/green'))->toRollBackCleanly(migrations: 2);
});

it('bites on a migration that declares no down()', function (): void {
    expect(rollbackFailure('missing-down', 2))->toContain('declares no down()');
});

it('names the migration at fault, not the one that would fail later', function (): void {
    // Bug #1's whole shape: `books` has no down(), survives the rollback, and the NEXT
    // test dies creating `authors` — naming 0001, which is innocent. The structural half
    // must name 0002 and only 0002, before any DDL runs at all.
    $failure = rollbackFailure('missing-down', 2);

    expect($failure)
        ->toContain('0002_create_books_table.php')
        ->not->toContain('0001_create_authors_table.php');
});

it('bites on a down() with an empty body', function (): void {
    expect(rollbackFailure('empty-down', 1))
        ->toContain('empty body')
        ->toContain('0001_create_widgets_table.php');
});

it('bites on a down() whose body is only comments', function (): void {
    // A regex over the source would read the docblock as a body; the tokenizer must not.
    expect(rollbackFailure('comment-only-down', 1))->toContain('empty body');
});

// ---------------------------------------------------------------------------
// The pin — it must not be able to pass over an empty or drifted parse.
// ---------------------------------------------------------------------------

it('bites on a migrations directory that does not exist', function (): void {
    expect(rollbackFailure('does-not-exist', 1))->toContain('Migrations directory does not exist');
});

it('bites when the migration count does not match the pin', function (): void {
    expect(rollbackFailure('green', 99))->toContain('Expected 99 migrations');
});

it('cannot pass over an empty parse', function (): void {
    // An unpinned assertion over an empty directory is the vacuous green this whole
    // package exists to kill — so a zero-migration set must fail, not silently succeed.
    $empty = fixturePath('rollback/empty-set');
    mkdir($empty, 0o755, true);

    try {
        expect(rollbackFailure('empty-set', 0))->toContain('No migrations found');
    } finally {
        rmdir($empty);
    }
});

// ---------------------------------------------------------------------------
// The behavioural half — engine-gated. sqlite_real is available on every leg,
// so these run everywhere; the pgsql cases below skip visibly without Postgres.
// ---------------------------------------------------------------------------

it('proves a green set really unwinds on a live connection', function (): void {
    expect(fixturePath('rollback/green'))->toRollBackCleanly(migrations: 2, connection: 'sqlite_real');
});

it('bites on a down() that leaves a table behind', function (): void {
    // Structurally clean — a real, non-empty down(). Only the engine can catch this,
    // which is why the behavioural half is not redundant.
    expect(rollbackFailure('leaves-table', 1, 'sqlite_real'))
        ->toContain('left 1 table(s) behind')
        ->toContain('widgets');
});

it('restores the default connection after a behavioural run', function (): void {
    $before = config('database.default');

    expect(fixturePath('rollback/green'))->toRollBackCleanly(migrations: 2, connection: 'sqlite_real');

    expect(config('database.default'))->toBe($before);
});

it('restores the default connection even when the rollback fails', function (): void {
    $before = config('database.default');

    rollbackFailure('leaves-table', 1, 'sqlite_real');

    expect(config('database.default'))->toBe($before);
});

it('exposes the same check through the static Assert mirror', function (): void {
    // App suites without Pest expectations reach the assertion this way.
    Assert::migrationsRollBackCleanly(fixturePath('rollback/green'), 2, 'sqlite_real');

    expect(fn (): mixed => Assert::migrationsRollBackCleanly(fixturePath('rollback/missing-down'), 2))
        ->toThrow(AssertionFailedError::class);
});

// ---------------------------------------------------------------------------
// pgsql leg only — the engine bug #1 was found on. Skips visibly, never silently.
// ---------------------------------------------------------------------------

it('proves a green set unwinds on postgres', function (): void {
    expect(fixturePath('rollback/green'))->toRollBackCleanly(migrations: 2, connection: 'pgsql');
})->skip(fn (): bool => ! test()->connectionAvailable('pgsql'), 'pgsql connection not available');

it('bites on postgres when a down() leaves a table behind', function (): void {
    expect(rollbackFailure('leaves-table', 1, 'pgsql'))->toContain('left 1 table(s) behind');
})->skip(fn (): bool => ! test()->connectionAvailable('pgsql'), 'pgsql connection not available');
