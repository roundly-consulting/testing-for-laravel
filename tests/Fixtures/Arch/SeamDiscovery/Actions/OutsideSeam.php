<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\SeamDiscovery\Actions;

use RoundlyConsulting\Testing\Tests\Fixtures\Arch\SeamDiscovery\Models\Cog;

/** A seam-shaped method OUTSIDE the seam directory is not a seam. */
final class OutsideSeam
{
    public static function cog(): string
    {
        return (string) config('arch.cog_model', Cog::class);
    }
}
