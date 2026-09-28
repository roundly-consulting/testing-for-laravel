<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Broken;

use Closure;
use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Models\Team;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\TeamsManager;

/**
 * for() and prune() are missing.
 *
 * @method static Team create(string $name, array<string, mixed> $options = [])
 * @method static int sync(array<string, int> $map, Closure(int, string): bool $resolver, array $defaults = [], string ...$tags)
 * @method static static fresh()
 */
final class Undocumented extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TeamsManager::class;
    }
}
