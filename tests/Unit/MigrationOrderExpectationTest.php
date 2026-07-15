<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;

// ---------------------------------------------------------------------------
// Green: every foreign-key form the fleet ships parses and passes.
// ---------------------------------------------------------------------------

it('accepts a 19-edge dependency order pinned by count', function (): void {
    expect(fixturePath('green/nineteen-edges'))->toHaveRunnableMigrationOrder(19);
});

it('accepts bare constrained() keys derived from the column', function (): void {
    expect(fixturePath('green/bare-constrained'))->toHaveRunnableMigrationOrder(1);
});

it('accepts long-hand references()->on() keys', function (): void {
    expect(fixturePath('green/references-on'))->toHaveRunnableMigrationOrder(1);
});

it('accepts a Schema::create($var) table resolved by a resolver', function (): void {
    expect(fixturePath('green/var-table'))->toHaveRunnableMigrationOrder(1, ['$tableName' => 'books']);
});

it('accepts a self-referencing key sorted with its own migration', function (): void {
    expect(fixturePath('green/self-referencing'))->toHaveRunnableMigrationOrder(1);
});

it('accepts an ALTER that sorts after its CREATE', function (): void {
    expect(fixturePath('green/alter-after-create'))->toHaveRunnableMigrationOrder(1);
});

// ---------------------------------------------------------------------------
// Proves-it-bites: the expectation MUST go red on each broken fixture.
// ---------------------------------------------------------------------------

it('rejects a child table created before its parent', function (): void {
    expect(fn (): mixed => expect(fixturePath('broken/child-before-parent'))->toHaveRunnableMigrationOrder())
        ->toThrow(AssertionFailedError::class);
});

it('rejects an ALTER that runs before its CREATE', function (): void {
    expect(fn (): mixed => expect(fixturePath('broken/alter-before-create'))->toHaveRunnableMigrationOrder())
        ->toThrow(AssertionFailedError::class);
});

it('rejects a bare self-referencing key that derives the wrong table', function (): void {
    expect(fn (): mixed => expect(fixturePath('broken/self-referencing-bare'))->toHaveRunnableMigrationOrder())
        ->toThrow(AssertionFailedError::class);
});

it('rejects a Schema::create($var) table with no resolver', function (): void {
    expect(fn (): mixed => expect(fixturePath('broken/var-table-unresolved'))->toHaveRunnableMigrationOrder())
        ->toThrow(AssertionFailedError::class);
});

it('rejects an unparseable constrained() rather than dropping the edge', function (): void {
    expect(fn (): mixed => expect(fixturePath('broken/unparseable-constrained'))->toHaveRunnableMigrationOrder())
        ->toThrow(AssertionFailedError::class);
});

it('rejects a mispinned edge count even on a correct order', function (): void {
    expect(fn (): mixed => expect(fixturePath('green/nineteen-edges'))->toHaveRunnableMigrationOrder(5))
        ->toThrow(AssertionFailedError::class);
});

it('rejects a missing migrations directory', function (): void {
    expect(fn (): mixed => expect(fixturePath('green/does-not-exist'))->toHaveRunnableMigrationOrder())
        ->toThrow(AssertionFailedError::class);
});
