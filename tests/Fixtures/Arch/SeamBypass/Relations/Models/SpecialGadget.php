<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\SeamBypass\Relations\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A packaged subclass of the swappable model: `parent::class` is the packaged Gadget. */
class SpecialGadget extends Gadget
{
    public function base(string $prefix): BelongsTo
    {
        return $this->belongsTo(parent::class, "{$prefix}_id");
    }
}
