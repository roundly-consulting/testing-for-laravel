<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\FactorySeam\Src\Support;

use RoundlyConsulting\Testing\Tests\Fixtures\Arch\FactorySeam\Src\Models\Gadget;

/** The seam for `arch.gadget_model`. */
final class GadgetModel
{
    public static function class(): string
    {
        return (string) config('arch.gadget_model', Gadget::class);
    }
}
