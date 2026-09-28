<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Assertions\Facades;

/**
 * One class-like declaration read from source tokens: its fully-qualified name, what kind
 * of declaration it is, and the two facts that decide whether it is a host-facing action —
 * `abstract`, and an `@internal` class docblock.
 */
final readonly class DeclaredClass
{
    public const string CLASS_KIND = 'class';

    public function __construct(
        public string $name,
        public string $file,
        public string $kind,
        public bool $abstract,
        public bool $internal,
    ) {}

    /**
     * A concrete class that does not opt out with `@internal` — the shape every
     * host-facing action has. Abstract bases, interfaces, traits and enums never are.
     */
    public function isHostFacing(): bool
    {
        return $this->kind === self::CLASS_KIND && ! $this->abstract && ! $this->internal;
    }
}
