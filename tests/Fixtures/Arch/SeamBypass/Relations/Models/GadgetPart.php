<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\SeamBypass\Relations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/** A packaged model. */
class GadgetPart extends Model
{
    protected $guarded = [];

    public function gadget(): BelongsTo
    {
        return $this->belongsTo(Gadget::class, 'gadget_id');
    }

    public function siblings(): HasManyThrough
    {
        // The through model is the swappable one here.
        return $this->hasManyThrough(Part::class, Gadget::class);
    }

    public function compatible(): BelongsToMany
    {
        return $this?->belongsToMany(related: Gadget::class, table: 'compatibility');
    }
}
