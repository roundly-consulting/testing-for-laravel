<?php

declare(strict_types=1);

namespace Shop;

use RoundlyConsulting\PackageToolkit\Support\Config;
use RoundlyConsulting\PackageToolkit\Support\ConfigValidator;
use SensitiveParameter;

// package-toolkit-for-laravel 1.3's secret(), requireSecret() and secretList() readers —
// static on `Config`, and chained off every validator shape the scraper already follows.
final class Secrets
{
    public function __construct(private readonly ConfigValidator $strict) {}

    /** @return list<mixed> */
    public function all(array $values): array
    {
        return [
            Config::secret('shop.api_key'),
            Config::requireSecret('shop.signing.secret'),
            Config::secretList('shop.keys'),
            Config::using(ShopException::class)->secret('shop.webhook_secret'),
            Config::using(ShopException::class)->requireSecret('shop.token'),
            Config::for($values, ShopException::class)
                ->secretList('shop.ring', fn (#[SensitiveParameter] string $key): bool => $key !== ''),
            ConfigValidator::forRepository()->secret(key: 'shop.password'),
            self::validator()->secretList('shop.previous_keys'),
            $this->strict->requireSecret('shop.client_secret'),
        ];
    }

    // The cosmos-foundation docs-gate shape: an optional secret, blank when none is set.
    public static function docsGate(): string
    {
        return Config::secret('shop.docs_gate.secret') ?? '';
    }

    /** @return list<string> */
    public function peers(): array
    {
        $read = Config::using(ShopException::class);

        return $read->secretList('shop.peers');
    }

    private static function validator(): ConfigValidator
    {
        return Config::using(ShopException::class);
    }
}
