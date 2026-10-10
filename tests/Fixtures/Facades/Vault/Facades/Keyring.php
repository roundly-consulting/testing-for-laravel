<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\Facades;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\Contracts\Keyring as KeyringContract;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\Support\RedactsSensitiveArguments;

/**
 * Over a contract whose implementation marks different parameters than the contract does.
 */
final class Keyring extends Facade
{
    use RedactsSensitiveArguments;

    protected static function getFacadeAccessor(): string
    {
        return KeyringContract::class;
    }
}
