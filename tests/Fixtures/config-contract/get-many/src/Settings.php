<?php

declare(strict_types=1);

namespace Shop;

use Illuminate\Support\Facades\Config;

// Repository::getMany() reads every key it is handed — listed, or as `key => default`.
final class Settings
{
    /** @return array<string, mixed> */
    public function display(): array
    {
        return Config::getMany(['shop.currency', 'shop.locale' => 'en']);
    }

    /** @return array<string, mixed> */
    public function location(): array
    {
        return config()->getMany(keys: ['shop.region', 'shop.zone']);
    }
}
