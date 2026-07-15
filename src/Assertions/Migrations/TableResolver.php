<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Assertions\Migrations;

use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;

/**
 * Resolves the table name behind a schema/foreign-key argument as it appears in
 * migration source text — a string literal, a bare (column-derived) key, or a
 * non-literal expression that the caller must map through `$tableResolvers`.
 *
 * The resolver never guesses on a non-literal: an unmapped `Schema::create($var)`
 * or `->constrained(Registrar::table())` fails the assertion loudly rather than
 * silently dropping the edge (the guard-the-guard doctrine).
 *
 * @see https://laravel.com/docs/migrations#foreign-key-constraints
 */
final readonly class TableResolver
{
    /** @param array<string, string> $tableResolvers raw expression => table name */
    public function __construct(private array $tableResolvers = []) {}

    /**
     * Resolve the table named by a `Schema::create(...)` / `Schema::table(...)`
     * argument. The argument is required to name a table, so a bare/empty argument
     * is a construction error and fails.
     */
    public function resolveSchemaTable(string $argument, string $context): string
    {
        $argument = trim($argument);

        $literal = $this->literal($argument);

        if ($literal !== null) {
            return $literal;
        }

        return $this->resolveExpression($argument, $context, 'Schema::create/table');
    }

    /**
     * Resolve the parent table of a `->constrained(...)` call. An empty argument is
     * the bare form: the parent is derived from the foreign-key column name via
     * `Str::plural(Str::beforeLast($column, '_id'))`.
     */
    public function resolveConstrained(string $argument, string $column, string $context): string
    {
        $argument = trim($argument);

        if ($argument === '') {
            Assert::assertNotSame(
                '',
                $column,
                "A bare `->constrained()` in {$context} has no foreignId() column to derive its parent from.",
            );

            return Str::plural(Str::beforeLast($column, '_id'));
        }

        $literal = $this->literal($argument);

        if ($literal !== null) {
            return $literal;
        }

        return $this->resolveExpression($argument, $context, '->constrained()');
    }

    /**
     * Resolve the parent table of a long-hand `->on(...)` call.
     */
    public function resolveOn(string $argument, string $context): string
    {
        $argument = trim($argument);

        $literal = $this->literal($argument);

        if ($literal !== null) {
            return $literal;
        }

        return $this->resolveExpression($argument, $context, '->on()');
    }

    private function literal(string $argument): ?string
    {
        if (preg_match("/^'([^']*)'\$/", $argument, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/^"([^"]*)"$/', $argument, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function resolveExpression(string $argument, string $context, string $form): string
    {
        if (array_key_exists($argument, $this->tableResolvers)) {
            return $this->tableResolvers[$argument];
        }

        Assert::fail(
            "Could not resolve the {$form} table `{$argument}` in {$context}: it is not a "
            ."string literal. Add a resolver, e.g. tableResolvers: ['{$argument}' => 'table'].",
        );
    }
}
