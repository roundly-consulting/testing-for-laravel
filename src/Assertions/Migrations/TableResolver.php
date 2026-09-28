<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Assertions\Migrations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;

/**
 * Resolves the table name behind a schema/foreign-key argument as it appears in
 * migration source text — a string literal, a bare (column-derived) key, a
 * `foreignIdFor(Model::class)` model, or a non-literal expression that the caller must map
 * through `$tableResolvers`.
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
     * Resolve the parent table of a `->constrained(...)` call, mirroring Laravel's
     * `ForeignIdColumnDefinition::constrained($table = null, $column = null, $indexName = null)`:
     *
     *  - a `table` argument (positional or named) wins;
     *  - else the table the column is already bound to — `foreignIdFor(Model::class)`;
     *  - else the bare form: derived from the column, `Str::plural(Str::beforeLast($column,
     *    '_'.$referencedColumn))`, where the referenced column defaults to `id`.
     *
     * @param  list<CallArgument>  $arguments
     */
    public function resolveConstrained(array $arguments, string $column, ?string $boundTable, string $context): string
    {
        $table = CallArgument::bound($arguments, 'table', 0);

        if ($table !== null) {
            $literal = $this->literal($table->text);

            return $literal ?? $this->resolveExpression($table->text, $context, '->constrained()');
        }

        if ($boundTable !== null) {
            return $boundTable;
        }

        Assert::assertNotSame(
            '',
            $column,
            "A bare `->constrained()` in {$context} has no foreignId() column to derive its parent from.",
        );

        $referenced = CallArgument::bound($arguments, 'column', 1);
        $referencedColumn = $referenced === null ? 'id' : ($this->literal($referenced->text) ?? 'id');

        return Str::plural(Str::beforeLast($column, '_'.$referencedColumn));
    }

    /**
     * Resolve the parent table of a long-hand `->on(...)` call.
     *
     * @param  list<CallArgument>  $arguments
     */
    public function resolveOn(array $arguments, string $context): string
    {
        $table = CallArgument::bound($arguments, 'table', 0);
        $text = $table === null ? '' : $table->text;

        return $this->literal($text) ?? $this->resolveExpression($text, $context, '->on()');
    }

    /**
     * Resolve the table a `foreignIdFor($model)` column is bound to — the model's own
     * `getTable()`, exactly what Laravel reads, so a model whose `$table` is not its
     * conventional name resolves correctly instead of being guessed from the class name.
     *
     * A `tableResolvers` entry for the raw argument wins; otherwise the argument must be a
     * `Model::class` (resolved through the file's `use` imports) or a class-name literal
     * naming a loadable Eloquent model. Anything else fails with the resolver to add.
     *
     * @param  list<CallArgument>  $arguments
     * @param  array<string, string>  $imports  lowercased alias => fully-qualified name
     */
    public function resolveModelTable(array $arguments, array $imports, string $context): string
    {
        $model = CallArgument::bound($arguments, 'model', 0);
        $text = $model === null ? '' : $model->text;

        if (array_key_exists($text, $this->tableResolvers)) {
            return $this->tableResolvers[$text];
        }

        $class = $this->className($text, $imports);

        if ($class !== null && class_exists($class) && is_subclass_of($class, Model::class)) {
            return (new $class)->getTable();
        }

        Assert::fail(
            "Could not resolve the foreignIdFor() model `{$text}` in {$context}: it is not a loadable Eloquent "
            ."model class. Add a resolver, e.g. tableResolvers: ['{$text}' => 'table'].",
        );
    }

    /**
     * @param  array<string, string>  $imports
     */
    private function className(string $text, array $imports): ?string
    {
        $literal = $this->literal($text);

        if ($literal !== null) {
            return ltrim(str_replace('\\\\', '\\', $literal), '\\');
        }

        if (preg_match('/^(\\\\?[A-Za-z_][\w\\\\]*)::class$/', $text, $matches) !== 1) {
            return null;
        }

        $name = $matches[1];

        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        $segments = explode('\\', $name);
        $alias = strtolower($segments[0]);

        if (isset($imports[$alias])) {
            $segments[0] = $imports[$alias];
        }

        return implode('\\', $segments);
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
