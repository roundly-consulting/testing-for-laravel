<?php

declare(strict_types=1);

namespace Shop;

// A cache lookup under a key that merely shares the prefix — not a config read.
final class Tokens
{
    public function read(): mixed
    {
        return app('cache')->get('shop.token');
    }
}
