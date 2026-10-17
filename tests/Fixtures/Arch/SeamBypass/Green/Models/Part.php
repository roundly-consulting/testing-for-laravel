<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\SeamBypass\Green\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\SeamBypass\Green\Support\GadgetModel;

/** A packaged model. */
class Part extends Model
{
    protected $guarded = [];

    /** Part is not swappable: naming it is fine. */
    public function sibling(): BelongsTo
    {
        return $this->belongsTo(Part::class, 'sibling_id');
    }

    public function gadget(): BelongsTo
    {
        return $this->belongsTo(GadgetModel::class());
    }

    public static function cheapest(): ?Model
    {
        return Part::query()->orderBy('price')->first();
    }
}
