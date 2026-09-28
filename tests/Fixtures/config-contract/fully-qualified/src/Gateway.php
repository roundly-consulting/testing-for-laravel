<?php

declare(strict_types=1);

namespace Shop;

use Illuminate\Support\Facades\Config as Settings;

// Every one of these reads config; none of them is the bare `config(` / `Config::` token the
// scraper used to require.
final class Gateway
{
    public function key(): mixed
    {
        return \config('shop.payment.key');
    }

    public function mode(): mixed
    {
        return Settings::get('shop.mode');
    }

    public function region(): string
    {
        return Settings::string('shop.region');
    }

    public function currency(): mixed
    {
        return \Config::get('shop.currency');
    }
}
