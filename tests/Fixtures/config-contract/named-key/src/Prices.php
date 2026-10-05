<?php

declare(strict_types=1);

namespace Shop;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Config;

// The key passed by name — first, or after another named argument. A `key:` label is not part
// of the key, and must not make a literal read look interpolated.
final readonly class Prices
{
    public function __construct(private Repository $config) {}

    public function currency(): mixed
    {
        return config(key: 'shop.currency');
    }

    public function locale(): mixed
    {
        return Config::get(default: 'en', key: 'shop.locale');
    }

    public function region(): string
    {
        return $this->config->string(key: 'shop.region');
    }
}
