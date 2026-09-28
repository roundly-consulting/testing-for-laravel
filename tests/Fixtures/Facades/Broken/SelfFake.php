<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Broken;

use Closure;
use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Models\Team;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\TeamHandle;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\TeamsManager;

/**
 * fake() "fakes" by swapping the real manager in for itself. It is typed, public static,
 * installed for the facade and for DI — and records nothing, because it is the root.
 *
 * @method static Team create(string $name, array<string, mixed> $options = [])
 * @method static TeamHandle for(Team $team)
 * @method static int sync(array<string, int> $map, Closure(int, string): bool $resolver, array $defaults = [], string ...$tags)
 * @method static static fresh()
 * @method static int prune()
 */
final class SelfFake extends Facade
{
    public static function fake(): TeamsManager
    {
        $root = self::getFacadeRoot();

        if (! $root instanceof TeamsManager) {
            $root = app(TeamsManager::class);
        }

        self::swap($root);

        return $root;
    }

    protected static function getFacadeAccessor(): string
    {
        return TeamsManager::class;
    }
}
