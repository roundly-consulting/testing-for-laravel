<?php

declare(strict_types=1);

namespace Shop;

// Reading below a leaf that can legitimately hold more than the package ships.
final class Reader
{
    public function read(): void
    {
        config('shop.guards.web');
        config('shop.drivers.0');
        config('shop.connection.host');
    }
}
