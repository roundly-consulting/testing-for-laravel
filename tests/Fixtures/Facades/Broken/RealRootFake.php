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
 * Declares the fake class, but hands back whatever the container already resolved — which is
 * the fake only if something bound the fake as the manager beforehand. Nothing new is built.
 *
 * @method static Team create(string $name, array<string, mixed> $options = [])
 * @method static TeamHandle for(Team $team)
 * @method static int sync(array<string, int> $map, Closure(int, string): bool $resolver, array $defaults = [], string ...$tags)
 * @method static static fresh()
 * @method static int prune()
 */
final class RealRootFake extends Facade
{
    public static function fake(): TeamsFake
    {
        /** @var TeamsFake $root */
        $root = app(TeamsManager::class);

        self::swap($root);

        return $root;
    }

    protected static function getFacadeAccessor(): string
    {
        return TeamsManager::class;
    }
}
