<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Testing\Assert;
use RoundlyConsulting\Testing\Tests\Fixtures\ModelSwap\CreateGrandchildRecord;
use RoundlyConsulting\Testing\Tests\Fixtures\ModelSwap\CreateRecord;
use RoundlyConsulting\Testing\Tests\Fixtures\ModelSwap\CreateRecordHardCoded;
use RoundlyConsulting\Testing\Tests\Fixtures\ModelSwap\CreateRecordViaPackagedClass;
use RoundlyConsulting\Testing\Tests\Fixtures\ModelSwap\HostRecord;
use RoundlyConsulting\Testing\Tests\Fixtures\ModelSwap\Record;
use RoundlyConsulting\Testing\Tests\Support\ModelSwapTestCase;

uses(ModelSwapTestCase::class);

// ---------------------------------------------------------------------------
// Green: a correctly-seamed flow creates the row as the host subclass.
// ---------------------------------------------------------------------------

it('passes when the seamed flow creates the row as the host subclass', function (): void {
    expect('model_swap.record_model')->toHonourModelSwap(
        HostRecord::class,
        fn (): HostRecord => CreateRecord::run('a'), // @phpstan-ignore-line
    );
});

it('accepts an iterable of models from the exercise', function (): void {
    expect('model_swap.record_model')->toHonourModelSwap(
        HostRecord::class,
        fn (): array => [CreateRecord::run('a'), CreateRecord::run('b')],
    );
});

it('honours the swap through the static escape hatch', function (): void {
    Assert::modelSwapHonoured('model_swap.record_model', HostRecord::class, fn () => CreateRecord::run('a'));

    expect(HostRecord::creationCount())->toBeGreaterThanOrEqual(1);
});

// ---------------------------------------------------------------------------
// Proves-it-bites.
// ---------------------------------------------------------------------------

it('bites when a hard-coded call site creates the packaged class', function (): void {
    // The action ignores the config and creates a Record, not the configured HostRecord.
    expect(fn (): mixed => expect('model_swap.record_model')->toHonourModelSwap(
        HostRecord::class,
        fn () => CreateRecordHardCoded::run('a'),
    ))->toThrow(AssertionFailedError::class);
});

it('bites fast when the swap was not applied before boot', function (): void {
    // config('model_swap.record_model') is HostRecord (swapped before boot), but the
    // caller passes Record — the mismatch is the "you forgot the before-boot swap" guard.
    expect(fn (): mixed => expect('model_swap.record_model')->toHonourModelSwap(
        Record::class,
        fn () => CreateRecord::run('a'),
    ))->toThrow(AssertionFailedError::class);
});

it('bites on a subclass instance whose concrete class is wrong', function (): void {
    // GrandchildRecord is an instanceof HostRecord, but not HostRecord — instanceof is
    // not enough, the concrete class must match.
    expect(fn (): mixed => expect('model_swap.record_model')->toHonourModelSwap(
        HostRecord::class,
        fn () => CreateGrandchildRecord::run('a'),
    ))->toThrow(AssertionFailedError::class);
});

it('bites when the right class is returned but no row was created as it', function (): void {
    // The row is created as the packaged Record, then re-read as HostRecord: the returned
    // concrete class is right, but HostRecord's created event never fired.
    expect(fn (): mixed => expect('model_swap.record_model')->toHonourModelSwap(
        HostRecord::class,
        fn () => CreateRecordViaPackagedClass::run('a'),
    ))->toThrow(AssertionFailedError::class);
});

it('bites when the exercise returns no models', function (): void {
    expect(fn (): mixed => expect('model_swap.record_model')->toHonourModelSwap(
        HostRecord::class,
        fn (): array => [],
    ))->toThrow(AssertionFailedError::class);
});

it('bites when the exercise returns a non-model', function (): void {
    expect(fn (): mixed => expect('model_swap.record_model')->toHonourModelSwap(
        HostRecord::class,
        fn (): string => 'not a model',
    ))->toThrow(AssertionFailedError::class);
});
