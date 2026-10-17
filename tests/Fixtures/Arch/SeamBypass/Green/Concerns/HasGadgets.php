<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\SeamBypass\Green\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\SeamBypass\Green\Models\Gadget;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\SeamBypass\Green\Support\GadgetModel;

/** A host-model trait that relates and checks the swappable model the right way. */
trait HasGadgets
{
    public function gadgets(): MorphMany
    {
        return $this->morphMany(GadgetModel::class(), 'owner');
    }

    public function ownsGadget(Model $model): bool
    {
        // `instanceof` and is_a() against the packaged base are correct: a configured model is
        // always Gadget or a subclass of it.
        return $model instanceof Gadget && is_a($model, Gadget::class);
    }

    public static function bootHasGadgets(): void
    {
        // Model's own statics register hooks; they build no query.
        Gadget::creating(static fn (): bool => true);
        Gadget::observe([]);
    }
}
