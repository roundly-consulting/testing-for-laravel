<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Testing\Assert;

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

// ---------------------------------------------------------------------------
// Block order inside one file — a file is not one instant.
// ---------------------------------------------------------------------------

it('accepts a parent and child created in the same migration, parent first', function (): void {
    expect(fixturePath('green/same-file'))->toHaveRunnableMigrationOrder(foreignKeys: 1);
});

it('rejects a child created before its parent in the same migration', function (): void {
    expect(fn (): mixed => expect(fixturePath('broken/same-file-child-first'))->toHaveRunnableMigrationOrder(foreignKeys: 1))
        ->toThrow(AssertionFailedError::class, '`team_members` references `teams`, which must be created first');
});

// ---------------------------------------------------------------------------
// A commented-out key is not a key.
// ---------------------------------------------------------------------------

it('ignores foreign keys that are commented out', function (): void {
    // Two commented-out declarations sit beside the one real key. Counting them inflated the
    // pin (and a commented key onto a missing table failed a correct set).
    expect(fixturePath('green/commented-out'))->toHaveRunnableMigrationOrder(foreignKeys: 1);
});

// ---------------------------------------------------------------------------
// The rest of Laravel's foreign-key forms.
// ---------------------------------------------------------------------------

it('understands foreignIdFor(), multi-argument and named-argument constrained()', function (): void {
    expect(fixturePath('green/fk-forms'))->toHaveRunnableMigrationOrder(foreignKeys: 7);
});

it('resolves a foreignIdFor() table from the model, not from the class name', function (): void {
    // Imprint's table is `imprints_catalogue`; the conventional guess `imprints` is never
    // created, so a name-based guess would go red on a correct order.
    expect(fixturePath('green/fk-forms'))->toHaveRunnableMigrationOrder(foreignKeys: 7);
    expect(fn (): mixed => expect(fixturePath('green/fk-forms'))->toHaveRunnableMigrationOrder(
        foreignKeys: 7,
        externalTables: ['imprints'],
    ))->toThrow(AssertionFailedError::class, 'imprints');
});

it('fails a foreignIdFor() whose model it cannot resolve, naming the resolver to add', function (): void {
    expect(fn (): mixed => expect(fixturePath('broken/foreign-id-for-unresolved'))->toHaveRunnableMigrationOrder())
        ->toThrow(AssertionFailedError::class, "tableResolvers: ['\$model' => 'table']");
});

it('maps an unresolvable foreignIdFor() model through tableResolvers', function (): void {
    expect(fixturePath('broken/foreign-id-for-unresolved'))->toHaveRunnableMigrationOrder(
        foreignKeys: 1,
        tableResolvers: ['$model' => 'books'],
    );
});

// ---------------------------------------------------------------------------
// Tables the set builds on but does not own.
// ---------------------------------------------------------------------------

it('fails a key onto a table the set does not create', function (): void {
    expect(fn (): mixed => expect(fixturePath('green/host-tables'))->toHaveRunnableMigrationOrder(foreignKeys: 1))
        ->toThrow(AssertionFailedError::class);
});

it('accepts keys onto and ALTERs of declared external tables', function (): void {
    expect(fixturePath('green/host-tables'))->toHaveRunnableMigrationOrder(foreignKeys: 1, externalTables: ['users']);
});

it('fails an external table the set never references', function (): void {
    expect(fn (): mixed => expect(fixturePath('green/host-tables'))->toHaveRunnableMigrationOrder(
        foreignKeys: 1,
        externalTables: ['users', 'teams'],
    ))->toThrow(AssertionFailedError::class, 'teams');
});

it('fails an external table the set itself creates', function (): void {
    expect(fn (): mixed => expect(fixturePath('green/same-file'))->toHaveRunnableMigrationOrder(
        foreignKeys: 1,
        externalTables: ['teams'],
    ))->toThrow(AssertionFailedError::class, 'teams');
});

it('mirrors external tables through the static escape hatch', function (): void {
    Assert::migrationsRunInDependencyOrder(fixturePath('green/host-tables'), 1, [], ['users']);

    expect(true)->toBeTrue();
});

// ---------------------------------------------------------------------------
// An empty parse is never "runnable" — not even with foreignKeys: 0.
// ---------------------------------------------------------------------------

it('fails on an empty or stub-only directory, even with foreignKeys: 0', function (): void {
    $empty = sys_get_temp_dir().'/order-pin-empty-'.bin2hex(random_bytes(6));
    mkdir($empty);

    try {
        foreach ([null, 0] as $pin) {
            expect(fn (): mixed => expect($empty)->toHaveRunnableMigrationOrder($pin))
                ->toThrow(AssertionFailedError::class, 'No migration files')
                ->and(fn (): mixed => expect(fixturePath('broken/stub-only'))->toHaveRunnableMigrationOrder($pin))
                ->toThrow(AssertionFailedError::class, '*.php.stub');
        }
    } finally {
        rmdir($empty);
    }
});

it('fails a set with no Schema::create()/table() block to order', function (): void {
    expect(fn (): mixed => expect(fixturePath('broken/no-schema-blocks'))->toHaveRunnableMigrationOrder(foreignKeys: 0))
        ->toThrow(AssertionFailedError::class, 'no Schema::create() or Schema::table() block');
});

// ---------------------------------------------------------------------------
// A table's life: created, maybe dropped, maybe created again — read from up() only.
// ---------------------------------------------------------------------------

it('fails a second create without a drop; accepts drop-and-recreate; ignores down()', function (): void {
    // Two CREATEs of `things` with no drop between them: every engine refuses the second.
    expect(fn (): mixed => expect(fixturePath('broken/duplicate-table'))->toHaveRunnableMigrationOrder(foreignKeys: 0))
        ->toThrow(AssertionFailedError::class, '`things` is created twice with no drop in between');

    // 0003's up() drops `legacy_tags` and its down() creates it again (with a key onto a table
    // nothing creates). down() never runs on the way up, so neither the CREATE nor the key in
    // it may count — the key in 0002 ran against the CREATE live at its ordinal, 0001's.
    expect(fixturePath('green/drop-in-up'))->toHaveRunnableMigrationOrder(foreignKeys: 1);

    // create → alter → dropIfExists + create: the ALTER ran against the FIRST create.
    expect(fixturePath('green/rebuild'))->toHaveRunnableMigrationOrder(foreignKeys: 1);
});

it('fails a key onto, or an ALTER of, a table that has been dropped', function (): void {
    expect(fn (): mixed => expect(fixturePath('broken/key-after-drop'))->toHaveRunnableMigrationOrder(foreignKeys: 1))
        ->toThrow(AssertionFailedError::class, '`posts` references `tags`, which is dropped before it')
        ->and(fn (): mixed => expect(fixturePath('broken/alter-after-drop'))->toHaveRunnableMigrationOrder(foreignKeys: 0))
        ->toThrow(AssertionFailedError::class, '`tags` is altered after it is dropped');
});
