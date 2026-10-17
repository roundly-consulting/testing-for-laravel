<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\SeamBypassUnloadable\Actions;

use RoundlyConsulting\Testing\Tests\Fixtures\Arch\SeamBypassUnloadable\Models\Gadget;

/**
 * The model cannot be autoloaded (its namespace maps to no PSR-4 path), so what forwards to a
 * query is read from the builders: a builder method and a soft-delete macro do, Model's own
 * hook does not, and an unknown name errs toward passing.
 */
final class ListGadgets
{
    public function execute(): void
    {
        Gadget::where('active', true);
        Gadget::onlyTrashed();
        Gadget::creating(static fn (): bool => true);
        Gadget::somethingUnknown();
    }
}
