<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Broken;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\TeamsManager;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Testing\TeamsFake;

/**
 * fake() declares a union, so its type cannot prove the fake stands in for the root.
 *
 * @method static int prune()
 */
final class UnionFake extends Facade
{
    public static function fake(): TeamsFake|UnrelatedFake
    {
        $fake = app(TeamsFake::class);

        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return TeamsManager::class;
    }
}
