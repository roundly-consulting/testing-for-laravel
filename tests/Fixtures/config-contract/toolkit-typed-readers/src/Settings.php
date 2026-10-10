<?php

declare(strict_types=1);

namespace Shop;

use RoundlyConsulting\PackageToolkit\Support\Config;
use RoundlyConsulting\PackageToolkit\Support\ConfigValidator;

// package-toolkit-for-laravel 1.2's float(), string() and list() readers — static on
// `Config`, and chained off every validator shape the scraper already follows.
final class Settings
{
    public function __construct(private readonly ConfigValidator $strict) {}

    /** @return list<mixed> */
    public function all(): array
    {
        return [
            Config::float('shop.ratio', 1.0, min: 0.0),
            Config::string('shop.locale', 'en'),
            Config::list('shop.hosts', []),
            Config::using(ShopException::class)->list('shop.channels', ['web']),
            Config::using(ShopException::class)->string('shop.currency', 'EUR'),
            ConfigValidator::forRepository()->float('shop.tax.rate', 0.2, max: 1.0),
            self::validator()->list(default: [], key: 'shop.regions'),
            $this->strict->string('shop.label', 'Shop'),
        ];
    }

    public function discount(): float
    {
        $read = Config::using(ShopException::class);

        return $read->float('shop.discount', 0.0);
    }

    /** @return list<string> */
    public function mirrors(): array
    {
        return Config::using(ShopException::class)
            ?->list('shop.mirrors', [], fn (mixed $host): bool => is_string($host));
    }

    private static function validator(): ConfigValidator
    {
        return Config::using(ShopException::class);
    }
}
