<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Broken;

use Countable;
use Traversable;

/**
 * Every signature shape the paste-ready `@method` suggestion has to render.
 */
final class SuggestedManager
{
    public function union(int|string $key): UnrelatedFake|HollowManager|null
    {
        return null;
    }

    public function intersection(Countable&Traversable $items): ?self
    {
        return null;
    }

    /**
     * @param  array<string, int>  $map
     */
    public function defaults(?string $a = null, bool $b = true, bool $c = false, int $d = 3, float $e = 1.5, string $f = 'x', array $map = ['k' => 1]): void {}

    /**
     * @param  mixed  $value
     */
    public function untyped($value): mixed
    {
        return $value;
    }
}
