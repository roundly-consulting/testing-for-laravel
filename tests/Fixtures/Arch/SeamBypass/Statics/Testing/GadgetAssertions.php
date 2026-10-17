<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\SeamBypass\Statics\Testing;

use RoundlyConsulting\Testing\Tests\Fixtures\Arch\SeamBypass\Statics\Models\Gadget;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\SeamBypass\Statics\Models\Gadget as Device;

/**
 * A testing helper that queries the packaged class — comments 1.1.0's `AssertsComments` shape.
 */
final class GadgetAssertions
{
    public static function all(): int
    {
        return Gadget::query()->count()
            + Gadget::where('active', true)->count()
            + Gadget::withTrashed()->count()
            + Gadget::active()->count()
            + Gadget::all()->count()
            + Device::count();
    }

    public static function make(): Gadget
    {
        return Gadget::firstOrCreate(['serial' => 'x']);
    }

    public static function allowed(): void
    {
        // A static the model declares, and Model's own hook registration: no query.
        Gadget::forSerial('x');
        Gadget::creating(static fn (): bool => true);
    }
}
