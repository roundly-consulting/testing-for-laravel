<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Support;

/**
 * Stand-in for a package registrar whose table name is resolved at runtime — used by
 * the `broken/unparseable-constrained` fixture to present a doubly-nested
 * `->constrained(...)` expression the edge parser cannot read (and therefore must
 * fail on, never silently drop).
 */
final class Registrar
{
    public static function table(string $connection): string
    {
        return 'roles';
    }

    public static function connection(): string
    {
        return 'default';
    }
}
