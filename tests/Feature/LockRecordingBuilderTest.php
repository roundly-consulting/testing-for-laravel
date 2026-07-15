<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Testing\Fixtures\LockRecorder;
use RoundlyConsulting\Testing\Tests\Fixtures\Locks\RecordingWidget;
use RoundlyConsulting\Testing\Tests\Support\LockRecorderTestCase;

uses(LockRecorderTestCase::class);

// Variant A: the model is subclassable, so its builder records every lock directly.

it('records a lockForUpdate call and the transaction depth it ran at', function (): void {
    DB::transaction(function (): void {
        RecordingWidget::query()->lockForUpdate()->get();
    });

    expect(LockRecorder::recorded())->toHaveCount(1)
        ->and(LockRecorder::recorded()[0]['marker'])->toBe('lock-for-update')
        ->and(LockRecorder::recorded()[0]['transactionDepth'])->toBe(1);
});

it('captures the deeper transaction depth of a lock inside a savepoint', function (): void {
    // The nested transaction is a savepoint (depth 2) — the exact datum that killed the
    // deleted LockedUpdate helper (its lock landed in a savepoint released too early).
    DB::transaction(function (): void {
        DB::transaction(function (): void {
            RecordingWidget::query()->lockForUpdate()->get();
        });
    });

    expect(LockRecorder::recorded())->toHaveCount(1)
        ->and(LockRecorder::recorded()[0]['transactionDepth'])->toBe(2);
});

it('records a shared lock with its own marker', function (): void {
    RecordingWidget::query()->sharedLock()->get();

    expect(LockRecorder::recorded())->toHaveCount(1)
        ->and(LockRecorder::recorded()[0]['marker'])->toBe('lock-shared');
});

it('records a raw string lock with a custom marker', function (): void {
    RecordingWidget::query()->lock('for update')->get();

    expect(LockRecorder::recorded())->toHaveCount(1)
        ->and(LockRecorder::recorded()[0]['marker'])->toBe('lock-custom');
});

it('distinguishes a locked read from an unlocked one', function (): void {
    // An unlocked read records nothing — proof the recorder is not blindly logging reads.
    RecordingWidget::query()->get();

    expect(LockRecorder::recorded())->toBeEmpty();

    RecordingWidget::query()->lockForUpdate()->get();

    expect(LockRecorder::recorded())->toHaveCount(1);
});
