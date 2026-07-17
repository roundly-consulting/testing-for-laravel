<?php

declare(strict_types=1);

namespace Fixture\Debug\Green;

use Illuminate\Support\Collection;

/**
 * Deliberately full of near-misses: a docblock naming dd() and ray(), a method *named*
 * dump() reached through `->`, and a static ::dump(). None is a leftover.
 */
final class Clean
{
    public function run(Collection $items): void
    {
        $items->dump();

        self::dump('x');
    }

    public static function dump(string $value): void
    {
        // A method named dump() is not a call to the global dump().
    }
}
