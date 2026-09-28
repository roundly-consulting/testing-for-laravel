<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Broken;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\TeamsManager;

/**
 * No @method line at all.
 *
 * @see TeamsManager
 */
final class Bare extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TeamsManager::class;
    }
}
