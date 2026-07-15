<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Assertions\Migrations;

/**
 * A single parsed foreign-key relationship: the {@see self::$child} table declares
 * a key onto the {@see self::$parent} table, in the migration file that sorts at
 * {@see self::$at} in directory order.
 *
 * A self-referencing key has `$parent === $child`.
 */
final readonly class ForeignKeyEdge
{
    public function __construct(
        public string $child,
        public string $parent,
        public int $at,
    ) {}

    public function isSelfReferencing(): bool
    {
        return $this->parent === $this->child;
    }
}
