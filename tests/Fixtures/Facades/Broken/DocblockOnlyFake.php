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
 * fake() exists only in the docblock.
 *
 * @method static Team create(string $name, array<string, mixed> $options = [])
 * @method static TeamHandle for(Team $team)
 * @method static int sync(array<string, int> $map, Closure(int, string): bool $resolver, array $defaults = [], string ...$tags)
 * @method static static fresh()
 * @method static int prune()
 * @method static TeamsFake fake()
 */
final class DocblockOnlyFake extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TeamsManager::class;
    }
}
