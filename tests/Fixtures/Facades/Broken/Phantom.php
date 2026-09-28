<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Broken;

use Closure;
use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Models\Team;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\TeamHandle;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\TeamsManager;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Testing\TeamsFake;

/**
 * Documents an archive() that exists nowhere.
 *
 * @method static Team create(string $name, array<string, mixed> $options = [])
 * @method static TeamHandle for(Team $team)
 * @method static int sync(array<string, int> $map, Closure(int, string): bool $resolver, array $defaults = [], string ...$tags)
 * @method static static fresh()
 * @method static int prune()
 * @method static void archive(Team $team)
 * @method static void assertArchived(Team $team)
 */
final class Phantom extends Facade
{
    public static function fake(): TeamsFake
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
