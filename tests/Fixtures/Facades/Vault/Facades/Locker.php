<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\Facades;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\Contracts\Locker as LockerContract;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\Support\RedactsSensitiveArguments;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\Testing\LockerFake;

/**
 * Green over a contract whose implementation marks the same parameters.
 */
final class Locker extends Facade
{
    use RedactsSensitiveArguments;

    public static function fake(): LockerFake
    {
        $fake = new LockerFake;

        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return LockerContract::class;
    }
}
