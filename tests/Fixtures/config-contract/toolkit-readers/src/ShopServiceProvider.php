<?php

declare(strict_types=1);

namespace Shop;

use RoundlyConsulting\PackageToolkit\Package;
use RoundlyConsulting\PackageToolkit\PackageServiceProvider;
use RoundlyConsulting\PackageToolkit\Support\Config;
use RoundlyConsulting\PackageToolkit\Support\ConfigValidator;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;

final class ShopServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        // `shop.php` is the routes FILE — it must not be read as a config key.
        $package
            ->name('shop')
            ->hasConfigFile()
            ->hasRoutes('shop.php', enabledVia: 'shop.routes.enabled')
            ->hasFacadeAlias(Shop::class, 'shop.facade_alias');
    }

    public function register(): void
    {
        parent::register();

        $this->bindFromConfig(TaxResolver::class, 'shop.tax.resolver', ConfigTaxResolver::class);
    }

    public static function settings(string $surface): array
    {
        return [
            Config::enum('shop.mode', Mode::class),
            Config::requireString('shop.host'),
            Config::using(ShopException::class)->integer('shop.retries', 3, min: 0),
            self::validator()->boolean('shop.strict'),
            Config::oneOf('shop.order', ['latest', 'oldest'], 'latest'),
            ModelResolver::for('shop.model'),
            Config::enum("shop.rate_limits.{$surface}.per", Timespan::class),
            Config::integer("shop.rate_limits.{$surface}.limit", 60, min: 1),
        ];
    }

    private static function validator(): ConfigValidator
    {
        return Config::using(ShopException::class);
    }
}
