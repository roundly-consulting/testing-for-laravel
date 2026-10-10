<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\Facades;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\VaultManager;

/**
 * Negative control: swallows every call, so nothing reaches the root and there is no trace.
 */
final class SilentVault extends Facade
{
    /**
     * @param  string  $method
     * @param  array<array-key, mixed>  $args
     */
    public static function __callStatic($method, $args): null
    {
        return null;
    }

    protected static function getFacadeAccessor(): string
    {
        return VaultManager::class;
    }
}
