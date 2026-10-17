<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\SeamBypass\Relations\Support;

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\SeamBypass\Relations\Models\Gadget;

/**
 * The seam: the only place that reads `arch.gadget_model`, beside the default it resolves.
 */
final class GadgetModel
{
    /** @return class-string<Gadget> */
    public static function class(): string
    {
        $model = config('arch.gadget_model', Gadget::class);

        return is_string($model) && is_a($model, Gadget::class, true) ? $model : Gadget::class;
    }

    /** @return Builder<Gadget> */
    public static function query(): Builder
    {
        return self::class()::query();
    }

    /** Inside the seam the packaged class may be named: this is where the default lives. */
    public static function packaged(): Builder
    {
        return Gadget::query()->withoutGlobalScopes();
    }
}
