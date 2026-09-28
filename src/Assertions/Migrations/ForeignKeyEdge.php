<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Assertions\Migrations;

/**
 * A single parsed foreign-key relationship: the {@see self::$child} table declares
 * a key onto the {@see self::$parent} table, in the Schema block whose ordinal in run
 * order (files in directory order, blocks in source order) is {@see self::$at}.
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
