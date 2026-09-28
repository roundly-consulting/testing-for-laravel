<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Broken;

use Illuminate\Support\Facades\Facade;

/**
 * A facade over a root with no documentable method.
 *
 * @method static void wire()
 */
final class Hollow extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return HollowManager::class;
    }
}
