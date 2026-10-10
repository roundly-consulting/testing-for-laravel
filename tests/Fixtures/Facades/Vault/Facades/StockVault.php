<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\Facades;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\VaultManager;

/**
 * Negative control: Laravel's own `__callStatic` frame carries every argument raw.
 */
final class StockVault extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return VaultManager::class;
    }
}
