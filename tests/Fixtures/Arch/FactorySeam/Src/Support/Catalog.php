<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\FactorySeam\Src\Support;

use RoundlyConsulting\Testing\Tests\Fixtures\Arch\FactorySeam\Src\Models\Sprocket;

/** A multi-model seam: one method per swappable model. */
final class Catalog
{
    public static function sprocket(): string
    {
        return (string) config('arch.sprocket_model', Sprocket::class);
    }
}
