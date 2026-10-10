<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\Facades;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\ShadowManager;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\Support\RedactsSensitiveArguments;

/**
 * Its root's swap() is shadowed by Facade::swap(), which the probe must never call for real.
 */
final class ShadowVault extends Facade
{
    use RedactsSensitiveArguments;

    protected static function getFacadeAccessor(): string
    {
        return ShadowManager::class;
    }
}
