<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing;

use RoundlyConsulting\Testing\Assertions\Migrations\MigrationGraph;

/**
 * Static entry points for every assertion in this package.
 *
 * The Pest expectations in {@see Expectations\Expectations} are the documented,
 * canonical API — lead with `expect(...)->toXxx(...)`. This class is a thin
 * secondary entry point for plain PHPUnit suites (and what the expectations
 * delegate to internally); the real logic lives in the `Assertions\` classes.
 */
final class Assert
{
    /**
     * Pin that a directory of migrations runs from an empty database in directory
     * order: every foreign-key target is created before the table referencing it.
     *
     * @param  int|null  $expectedForeignKeys  guard-the-guard: pin the total edge count so the
     *                                         check cannot pass over an empty parse
     * @param  array<string, string>  $tableResolvers  raw expression => table, for non-literal
     *                                                 `Schema::create($var)` / `->constrained(Class::method())`
     */
    public static function migrationsRunInDependencyOrder(
        string $migrationsDir,
        ?int $expectedForeignKeys = null,
        array $tableResolvers = [],
    ): void {
        MigrationGraph::forDirectory($migrationsDir, $tableResolvers)
            ->assertRunnable($expectedForeignKeys);
    }
}
