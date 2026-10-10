<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\Facades;

use Illuminate\Support\Facades\Facade;
use LogicException;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\VaultManager;

/**
 * Negative control: throws before it reaches the root.
 */
final class RefusingVault extends Facade
{
    /**
     * @param  string  $method
     * @param  array<array-key, mixed>  $args
     */
    public static function __callStatic($method, $args): never
    {
        throw new LogicException('refused');
    }

    protected static function getFacadeAccessor(): string
    {
        return VaultManager::class;
    }
}
