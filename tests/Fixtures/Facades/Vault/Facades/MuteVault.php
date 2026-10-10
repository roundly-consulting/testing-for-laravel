<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\Facades;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\VaultManager;

/**
 * Negative control: turns argument capture back on before forwarding, so the trace holds no
 * argument to read and proves nothing.
 */
final class MuteVault extends Facade
{
    /**
     * @param  string  $method
     * @param  array<array-key, mixed>  $args
     * @return mixed
     */
    public static function __callStatic($method, $args)
    {
        ini_set('zend.exception_ignore_args', '1');

        return self::getFacadeRoot()->$method(...$args);
    }

    protected static function getFacadeAccessor(): string
    {
        return VaultManager::class;
    }
}
