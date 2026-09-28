<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Facades;

use Closure;
use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Models\Team;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\TeamHandle;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\TeamsManager;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Testing\TeamsFake;

/**
 * The green facade. `sync()` documents generics, a callable type, an array shape with a
 * nested array default holding `=>` and a quoted comma, and a variadic — four parameters,
 * which must not miscount. `driver()` is vendor API documented anyway (allowed); `fake()` is
 * a real static on this class; the assert helpers live on the fake.
 *
 * @method static Team create(string $name, array<string, mixed> $options = [])
 * @method static TeamHandle for(Team $team)
 * @method static int sync(array<string, int> $map, Closure(int, string): bool $resolver, array{a: int, b: string, c: list<int>} $defaults = ['a' => 1, 'b' => 'x, y', 'c' => [2, 3]], string ...$tags)
 * @method static static fresh()
 * @method static int prune()
 * @method static mixed driver(string|null $driver = null)
 * @method static void assertCreated(string $name)
 * @method static void assertNothingCreated()
 * @method static TeamsFake fake()
 *
 * @see TeamsManager
 */
final class Teams extends Facade
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
