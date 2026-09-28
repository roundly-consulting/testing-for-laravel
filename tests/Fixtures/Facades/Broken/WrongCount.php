<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Broken;

use Closure;
use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Models\Team;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\TeamHandle;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\TeamsManager;

/**
 * create() and sync() document the wrong number of parameters.
 *
 * @method static Team create(string $name)
 * @method static TeamHandle for(Team $team)
 * @method static int sync(array<string, int> $map, Closure(int, string): bool $resolver)
 * @method static static fresh()
 * @method static int prune()
 */
final class WrongCount extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TeamsManager::class;
    }
}
