<?php

declare(strict_types=1);

namespace Shop;

// `config([...])` with an array SETS keys — a write, not a read, and not an unresolvable key.
final class Toggle
{
    public function enable(): bool
    {
        config(['shop.flag' => true]);

        return (bool) config('shop.flag');
    }
}
