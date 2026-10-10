<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\Facades;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\VaultManager;
use SensitiveParameter;

/**
 * Green without the trait: a real static per secret method, each with its own attribute. The
 * harmless methods still go through `__callStatic`.
 */
final class StaticVault extends Facade
{
    public static function unlock(#[SensitiveParameter] string $secret, string $label): bool
    {
        return self::getFacadeRoot()->unlock($secret, $label);
    }

    public static function encode(#[SensitiveParameter] string $bytes): string
    {
        return self::getFacadeRoot()->encode($bytes);
    }

    public static function join(string $glue, #[SensitiveParameter] string ...$parts): string
    {
        return self::getFacadeRoot()->join($glue, ...$parts);
    }

    public static function fingerprint(#[SensitiveParameter] string $key): string
    {
        return self::getFacadeRoot()->fingerprint($key);
    }

    protected static function getFacadeAccessor(): string
    {
        return VaultManager::class;
    }
}
