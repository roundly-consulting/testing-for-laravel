<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Broken;

/**
 * Nothing a facade could document: a constructor, a magic method, an @internal helper.
 */
final class HollowManager
{
    public function __construct() {}

    public function __toString(): string
    {
        return 'hollow';
    }

    /**
     * @internal
     */
    public function wire(): void {}
}
