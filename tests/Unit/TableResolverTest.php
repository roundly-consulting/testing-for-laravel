<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Testing\Assertions\Migrations\TableResolver;

it('resolves single- and double-quoted table literals', function (): void {
    $resolver = new TableResolver;

    expect($resolver->resolveSchemaTable("'users'", 'x'))->toBe('users')
        ->and($resolver->resolveSchemaTable('"teams"', 'x'))->toBe('teams');
});

it('derives a bare constrained() parent from the foreign-key column', function (): void {
    expect((new TableResolver)->resolveConstrained('', 'user_id', 'x'))->toBe('users');
});

it('fails a bare constrained() with no column to derive from', function (): void {
    expect(fn (): string => (new TableResolver)->resolveConstrained('', '', 'x'))
        ->toThrow(AssertionFailedError::class);
});

it('resolves a literal constrained() argument', function (): void {
    expect((new TableResolver)->resolveConstrained("'roles'", '', 'x'))->toBe('roles');
});

it('resolves a literal on() argument', function (): void {
    expect((new TableResolver)->resolveOn("'users'", 'x'))->toBe('users');
});

it('maps a non-literal expression through the resolver map', function (): void {
    $resolver = new TableResolver(['Registrar::table()' => 'roles']);

    expect($resolver->resolveConstrained('Registrar::table()', '', 'x'))->toBe('roles');
});

it('fails an unmapped non-literal Schema table expression', function (): void {
    expect(fn (): string => (new TableResolver)->resolveSchemaTable('$table', 'x'))
        ->toThrow(AssertionFailedError::class);
});

it('fails an unmapped non-literal on() expression', function (): void {
    expect(fn (): string => (new TableResolver)->resolveOn('$var', 'x'))
        ->toThrow(AssertionFailedError::class);
});
