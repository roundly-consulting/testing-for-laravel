<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Testing\Assertions\Migrations\CallArgument;
use RoundlyConsulting\Testing\Assertions\Migrations\TableResolver;
use RoundlyConsulting\Testing\Tests\Fixtures\Migrations\Author;
use RoundlyConsulting\Testing\Tests\Fixtures\Migrations\Imprint;

/**
 * @return list<CallArgument>
 */
function positional(string ...$texts): array
{
    return array_values(array_map(static fn (string $text): CallArgument => new CallArgument(null, $text), $texts));
}

it('resolves single- and double-quoted table literals', function (): void {
    $resolver = new TableResolver;

    expect($resolver->resolveSchemaTable("'users'", 'x'))->toBe('users')
        ->and($resolver->resolveSchemaTable('"teams"', 'x'))->toBe('teams');
});

it('derives a bare constrained() parent from the foreign-key column', function (): void {
    expect((new TableResolver)->resolveConstrained([], 'user_id', null, 'x'))->toBe('users');
});

it('derives past the referenced column when constrained() names one', function (): void {
    // Laravel strips `_{$column}`, not always `_id`: foreignUuid('owner_uuid')->constrained(column: 'uuid').
    expect((new TableResolver)->resolveConstrained([new CallArgument('column', "'uuid'")], 'owner_uuid', null, 'x'))
        ->toBe('owners');
});

it('fails a bare constrained() with no column to derive from', function (): void {
    expect(fn (): string => (new TableResolver)->resolveConstrained([], '', null, 'x'))
        ->toThrow(AssertionFailedError::class);
});

it('resolves a literal constrained() argument, positional or named', function (): void {
    $resolver = new TableResolver;

    expect($resolver->resolveConstrained(positional("'roles'"), '', null, 'x'))->toBe('roles')
        ->and($resolver->resolveConstrained(positional("'roles'", "'id'"), '', null, 'x'))->toBe('roles')
        ->and($resolver->resolveConstrained([new CallArgument('column', "'id'"), new CallArgument('table', "'roles'")], '', null, 'x'))->toBe('roles');
});

it('treats an explicit null table as the bare form', function (): void {
    expect((new TableResolver)->resolveConstrained(positional('null', "'id'"), 'team_id', null, 'x'))->toBe('teams');
});

it('prefers the table foreignIdFor() bound over a derived one', function (): void {
    expect((new TableResolver)->resolveConstrained([], 'author_id', 'writers', 'x'))->toBe('writers')
        ->and((new TableResolver)->resolveConstrained(positional("'editors'"), 'author_id', 'writers', 'x'))->toBe('editors');
});

it('resolves a literal on() argument', function (): void {
    expect((new TableResolver)->resolveOn(positional("'users'"), 'x'))->toBe('users')
        ->and((new TableResolver)->resolveOn([new CallArgument('table', "'users'")], 'x'))->toBe('users');
});

it('maps a non-literal expression through the resolver map', function (): void {
    $resolver = new TableResolver(['Registrar::table()' => 'roles']);

    expect($resolver->resolveConstrained(positional('Registrar::table()'), '', null, 'x'))->toBe('roles');
});

it('fails an unmapped non-literal Schema table expression', function (): void {
    expect(fn (): string => (new TableResolver)->resolveSchemaTable('$table', 'x'))
        ->toThrow(AssertionFailedError::class);
});

it('fails an unmapped non-literal on() expression', function (): void {
    expect(fn (): string => (new TableResolver)->resolveOn(positional('$var'), 'x'))
        ->toThrow(AssertionFailedError::class);
});

it('resolves a foreignIdFor() model through the imports, an alias and a class literal', function (): void {
    $resolver = new TableResolver;
    $imports = ['author' => Author::class, 'publisher' => Imprint::class];

    expect($resolver->resolveModelTable(positional('Author::class'), $imports, 'x'))->toBe('authors')
        ->and($resolver->resolveModelTable(positional('Publisher::class'), $imports, 'x'))->toBe('imprints_catalogue')
        ->and($resolver->resolveModelTable(positional('\\'.Author::class.'::class'), [], 'x'))->toBe('authors')
        ->and($resolver->resolveModelTable(positional("'".Imprint::class."'"), [], 'x'))->toBe('imprints_catalogue')
        ->and($resolver->resolveModelTable([new CallArgument('model', 'Author::class')], $imports, 'x'))->toBe('authors');
});

it('fails a foreignIdFor() argument that is not a loadable model', function (string $argument): void {
    expect(fn (): string => (new TableResolver)->resolveModelTable(positional($argument), [], 'x'))
        ->toThrow(AssertionFailedError::class, 'tableResolvers');
})->with(['$model', 'Missing::class', 'stdClass::class', 'config(\'x.model\')']);

it('maps a foreignIdFor() argument through the resolver map before resolving the class', function (): void {
    expect((new TableResolver(['Author::class' => 'people']))->resolveModelTable(positional('Author::class'), [], 'x'))
        ->toBe('people');
});
