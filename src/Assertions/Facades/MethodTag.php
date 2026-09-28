<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Assertions\Facades;

/**
 * One parsed `@method` line of a facade docblock: the method name, how many parameters it
 * documents, and whether it was written `@method static`.
 */
final readonly class MethodTag
{
    public function __construct(
        public string $name,
        public int $parameters,
        public bool $static,
        public string $line,
    ) {}
}
