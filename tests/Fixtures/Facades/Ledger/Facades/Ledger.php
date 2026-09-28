<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Ledger\Facades;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Ledger\Contracts\Ledger as LedgerContract;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Ledger\Testing\LedgerFake;

/**
 * A facade over a contract, bound to LedgerManager by LedgerServiceProvider.
 *
 * @method static void record(int $amount)
 * @method static int balance()
 * @method static void assertRecorded(int $amount)
 *
 * @see LedgerContract
 */
final class Ledger extends Facade
{
    public static function fake(): LedgerFake
    {
        $fake = new LedgerFake;

        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return LedgerContract::class;
    }
}
