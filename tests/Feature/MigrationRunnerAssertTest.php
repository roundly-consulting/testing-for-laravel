<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Testing\Assert;
use RoundlyConsulting\Testing\Tests\Support\RealEngineTestCase;

uses(RealEngineTestCase::class);

it('applies a clean order through the static escape hatch', function (): void {
    Assert::migrationsApplyOnConnection(fixturePath('green/references-on'), 'sqlite_real');

    // Reaching here without an exception is the assertion.
    expect(true)->toBeTrue();
});

it('fails loudly through the static negative-control escape hatch on sqlite', function (): void {
    expect(fn (): mixed => Assert::brokenOrderIsRejectedOnConnection(
        fixturePath('broken/child-before-parent'),
        fn (array $files): array => $files,
        'sqlite_real',
    ))->toThrow(AssertionFailedError::class);
});
