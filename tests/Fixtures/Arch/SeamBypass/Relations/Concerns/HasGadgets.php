<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\SeamBypass\Relations\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\SeamBypass\Relations\Models\Gadget as Device;

/**
 * A host-model trait naming the packaged class: fully qualified, and through an alias. Excluded
 * from Pint in pint.json — its fully_qualified_strict_types fixer would import the name and
 * erase the case this fixture exists for.
 */
trait HasGadgets
{
    public function gadgets(): MorphMany
    {
        return $this->morphMany(\RoundlyConsulting\Testing\Tests\Fixtures\Arch\SeamBypass\Relations\Models\Gadget::class, 'owner');
    }

    public function tagged(): MorphToMany
    {
        return $this->morphToMany(Device::class, 'taggable');
    }
}
