<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\SeamBypass\Statics\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\SeamBypass\Statics\Support\GadgetModel;

/** A packaged model. */
class Gadget extends Model
{
    protected $guarded = [];

    public static function forSerial(string $serial): ?self
    {
        $found = GadgetModel::query()->where('serial', $serial)->first();

        return $found instanceof self ? $found : null;
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }
}
