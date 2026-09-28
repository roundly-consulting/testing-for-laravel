<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Broken;

use Illuminate\Support\Facades\Facade;

/**
 * No @method line yet — the failure must hand back one paste-ready line per method.
 */
final class Suggested extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SuggestedManager::class;
    }
}
