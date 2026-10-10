<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\Facades;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\VaultManager;
use RuntimeException;
use SensitiveParameter;

/**
 * Negative control: hides every argument of every method, the harmless ones included.
 */
final class BluntVault extends Facade
{
    /**
     * @param  string  $method
     * @param  array<array-key, mixed>  $args
     * @return mixed
     */
    public static function __callStatic($method, #[SensitiveParameter] $args)
    {
        $instance = self::getFacadeRoot();

        if (! $instance) {
            throw new RuntimeException('A facade root has not been set.');
        }

        return $instance->$method(...$args);
    }

    protected static function getFacadeAccessor(): string
    {
        return VaultManager::class;
    }
}
