<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\SeamBypass\Relations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A packaged model. */
class Gadget extends Model
{
    protected $guarded = [];

    /** `self::class` inside the swappable model is its packaged class, spelled differently. */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
