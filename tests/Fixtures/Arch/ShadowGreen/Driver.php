<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\ShadowGreen;

/**
 * The documented extension point a host subclasses: non-final on purpose, exempted by name.
 */
class Driver
{
    public function name(): string
    {
        return 'driver';
    }
}
