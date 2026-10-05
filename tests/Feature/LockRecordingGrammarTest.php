<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Testing\Fixtures\LockRecorder;
use RoundlyConsulting\Testing\Fixtures\LockRecordingGrammar;
use RoundlyConsulting\Testing\Tests\Fixtures\Locks\PlainWidget;
use RoundlyConsulting\Testing\Tests\Support\LockRecorderTestCase;

uses(LockRecorderTestCase::class);

// Variant B: the model is NOT subclassable, so the lock is observed by compiling it to a
// trailing SQL comment the grammar emits and a DB::listen() picks up. The grammar is SQLite's,
// so the recording cases run where the suite is on sqlite; a real engine takes a real lock.

function onSqlite(): bool
{
    return DB::connection()->getDriverName() === 'sqlite';
}

function installLockRecordingGrammar(): void
{
    $connection = DB::connection();
    $connection->setQueryGrammar(new LockRecordingGrammar($connection));

    LockRecorder::flush();
    LockRecorder::listenForMarkers();
}

it('observes a lock through the grammar comment marker and its depth', function (): void {
    installLockRecordingGrammar();

    DB::transaction(function (): void {
        PlainWidget::query()->lockForUpdate()->get();
    });

    expect(LockRecorder::recorded())->toHaveCount(1)
        ->and(LockRecorder::recorded()[0]['marker'])->toBe('lock-for-update')
        ->and(LockRecorder::recorded()[0]['transactionDepth'])->toBe(1)
        ->and(LockRecorder::recorded()[0]['sql'])->toContain('/* lock-for-update */');
})->skip(fn (): bool => ! onSqlite(), 'the recording grammar is sqlite-only');

it('emits a shared-lock marker for a shared lock', function (): void {
    installLockRecordingGrammar();

    PlainWidget::query()->sharedLock()->get();

    expect(LockRecorder::recorded())->toHaveCount(1)
        ->and(LockRecorder::recorded()[0]['marker'])->toBe('lock-shared');
})->skip(fn (): bool => ! onSqlite(), 'the recording grammar is sqlite-only');

it('marks a raw string lock as custom', function (): void {
    installLockRecordingGrammar();

    PlainWidget::query()->lock('for update')->get();

    expect(LockRecorder::recorded())->toHaveCount(1)
        ->and(LockRecorder::recorded()[0]['marker'])->toBe('lock-custom')
        ->and(LockRecorder::recorded()[0]['sql'])->toContain('/* lock-custom */');
})->skip(fn (): bool => ! onSqlite(), 'the recording grammar is sqlite-only');

it('does not mark or record an unlocked read', function (): void {
    installLockRecordingGrammar();

    PlainWidget::query()->get();

    expect(LockRecorder::recorded())->toBeEmpty();
})->skip(fn (): bool => ! onSqlite(), 'the recording grammar is sqlite-only');

it('refuses a non-sqlite connection', function (): void {
    // Installed on pgsql it replaced Postgres' grammar with SQLite's: `lockForUpdate()` compiled
    // to a comment, so the real row lock was silently dropped (and MySQL got double-quoted
    // identifiers it reads as strings). A real engine never needs the marker — it locks.
    config()->set('database.connections.lock_sqlite', ['driver' => 'sqlite', 'database' => ':memory:']);

    foreach (['pgsql', 'mysql'] as $connection) {
        expect(fn (): LockRecordingGrammar => new LockRecordingGrammar(DB::connection($connection)))
            ->toThrow(InvalidArgumentException::class, "on a [{$connection}] connection");
    }

    expect(new LockRecordingGrammar(DB::connection('lock_sqlite')))->toBeInstanceOf(LockRecordingGrammar::class);
});
