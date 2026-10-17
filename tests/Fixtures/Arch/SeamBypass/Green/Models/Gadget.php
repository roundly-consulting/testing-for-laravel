<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\SeamBypass\Green\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\SeamBypass\Green\Support\GadgetModel;

/** A packaged model. */
class Gadget extends Model
{
    protected $guarded = [];

    /**
     * Prose is not code: Gadget::query(), Gadget::where('a', 1) and belongsTo(Gadget::class)
     * in a docblock name nothing.
     */
    public function parent(): BelongsTo
    {
        // A comment naming belongsTo(Gadget::class) is not a relation either.
        return $this->belongsTo(GadgetModel::class(), 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(static::class, 'parent_id');
    }

    public function parts(): HasMany
    {
        return $this->hasMany(Part::class);
    }

    /** A static the model declares itself builds no forwarded query. */
    public static function forSerial(string $serial): ?self
    {
        $found = GadgetModel::query()->where('serial', $serial)->first();

        return $found instanceof self ? $found : null;
    }

    public function describe(): string
    {
        return 'Gadget::query() and belongsTo(Gadget::class) in a string are text, not code';
    }
}
