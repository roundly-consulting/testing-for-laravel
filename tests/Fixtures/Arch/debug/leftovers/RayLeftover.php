<?php

declare(strict_types=1);

namespace Fixture\Debug\Leftovers;

/**
 * The whole point: `ray()` is not a defined function in this graph, so Pest's arch layer
 * filtered it out and the ban passed. A token scan sees the call regardless.
 */
final class RayLeftover
{
    public function inspect(string $value): void
    {
        ray($value);
    }
}
