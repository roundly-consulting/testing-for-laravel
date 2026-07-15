<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Testing\Assert;

it('passes on a correct order through the static escape hatch', function (): void {
    Assert::migrationsRunInDependencyOrder(fixturePath('green/bare-constrained'), 1);

    // Reaching here without an exception is the assertion.
    expect(true)->toBeTrue();
});

it('throws on a broken order through the static escape hatch', function (): void {
    expect(fn (): mixed => Assert::migrationsRunInDependencyOrder(fixturePath('broken/child-before-parent')))
        ->toThrow(AssertionFailedError::class);
});
