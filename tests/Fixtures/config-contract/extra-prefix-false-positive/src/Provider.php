<?php

declare(strict_types=1);

final class Provider
{
    public function boot(): void
    {
        // A routes FILENAME, not a config key — but it is shaped exactly like one, so an
        // `extraReadPrefixes` entry of `shop.` promotes it to a config read (purchases).
        $this->package()->hasRoutes('shop.php', 'shop.currency');
    }

    private function package(): object
    {
        return new class
        {
            public function hasRoutes(string $file, string $key): void {}
        };
    }
}
