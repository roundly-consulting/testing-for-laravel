<?php

declare(strict_types=1);

namespace Shop;

final class Title
{
    public function __invoke(): mixed
    {
        return config('shop.title');
    }
}
