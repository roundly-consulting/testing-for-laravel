<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Testing\Tests\Fixtures\ModelSwap\CreateRecord;
use RoundlyConsulting\Testing\Tests\Fixtures\ModelSwap\UncountedHostRecord;
use RoundlyConsulting\Testing\Tests\Support\UncountedModelSwapTestCase;

uses(UncountedModelSwapTestCase::class);

/**
 * #11: the downgrade must be loud.
 *
 * Omitting CountsCreations used to cost the assertion its strongest half — the created-event
 * count — with no warning at all. A caller who never thought about the trait got a weaker
 * proof wearing the same name, and `alerts`' seam-bypass proof stayed green until the trait
 * was added. The absence is now a failure that says what to do about it.
 */
it('bites when the host subclass is missing CountsCreations', function (): void {
    expect(fn (): mixed => expect('model_swap.record_model')->toHonourModelSwap(
        UncountedHostRecord::class,
        fn () => CreateRecord::run('a'),
    ))->toThrow(AssertionFailedError::class, 'must use the');
});

/**
 * The escape hatch for a flow that genuinely creates nothing. It is deliberately explicit at
 * the call site: a downgrade you had to type is a decision, not an accident.
 */
it('allows a read-only flow to opt out of the creation proof', function (): void {
    CreateRecord::run('seed');

    expect('model_swap.record_model')->toHonourModelSwap(
        UncountedHostRecord::class,
        fn () => UncountedHostRecord::query()->get(),
        expectsCreation: false,
    );
});
