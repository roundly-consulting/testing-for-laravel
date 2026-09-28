<?php

declare(strict_types=1);

namespace Shop;

final class Cache
{
    public function store(): mixed
    {
        config('shop.cache');

        // `shop.cache` is the string 'redis' — nothing lives below it, so this is always null.
        return config('shop.cache.store');
    }
}
