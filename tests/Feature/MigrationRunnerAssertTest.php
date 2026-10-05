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

it('applies a named-class migration and loads it twice', function (): void {
    // The Migrator resolves a named class from the file name; a second load in the same
    // process must reuse that class rather than require the file again (a fatal redeclare).
    expect(fixturePath('named-class'))->toApplyOnConnection('sqlite_real', migrations: 1)
        ->and(fixturePath('named-class'))->toApplyOnConnection('sqlite_real', migrations: 1);
});

it('fails a named class that is not a Migration, by name, on every load', function (): void {
    foreach ([1, 2] as $load) {
        expect(fn (): mixed => expect(fixturePath('loader/named-class-not-migration'))->toApplyOnConnection('sqlite_real'))
            ->toThrow(AssertionFailedError::class, 'declares `CreateNotAMigrationTable`, which is not a Migration');
    }
});
