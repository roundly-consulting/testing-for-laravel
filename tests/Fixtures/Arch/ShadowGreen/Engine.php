<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\ShadowGreen;

/**
 * Shares no prefix with `Driver`, so exempting `Driver` leaves it checked — the object that
 * keeps every preset pointed at this namespace from passing over an empty set.
 */
final class Engine
{
    public function start(): string
    {
        return 'started';
    }
}
