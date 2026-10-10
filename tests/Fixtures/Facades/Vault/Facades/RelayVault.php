<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\Facades;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\Support\SensitiveArguments;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\VaultManager;
use SensitiveParameterValue;

/**
 * Negative control: its own frame is redacted like the trait's, but it forwards through a helper
 * that forgot the attribute — so a frame below the facade frame holds the secret raw.
 */
final class RelayVault extends Facade
{
    /**
     * @param  string  $method
     * @param  array<array-key, mixed>  $args
     * @return mixed
     */
    public static function __callStatic($method, $args)
    {
        $forward = $args;
        $sensitive = SensitiveArguments::of(VaultManager::class, $method);

        if ($sensitive !== null) {
            foreach ($sensitive->positions as $position) {
                if (array_key_exists($position, $args)) {
                    $args[$position] = new SensitiveParameterValue($args[$position]);
                }
            }

            $args = $sensitive->redactNamedAndVariadic($args);
        }

        return self::relay($method, $forward);
    }

    protected static function getFacadeAccessor(): string
    {
        return VaultManager::class;
    }

    /**
     * @param  array<array-key, mixed>  $arguments
     */
    private static function relay(string $method, array $arguments): mixed
    {
        return self::getFacadeRoot()->$method(...$arguments);
    }
}
