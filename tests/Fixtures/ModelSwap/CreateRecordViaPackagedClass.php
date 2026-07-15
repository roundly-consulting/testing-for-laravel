<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\ModelSwap;

use Illuminate\Database\Eloquent\Model;

/**
 * The subtlest break (permissions #31): it creates the row as the packaged {@see Record}
 * — so the host subclass's `created` event never fires — then re-reads it *as* the host
 * subclass and returns that. The returned object has the right concrete class, so a
 * concrete-class check alone passes; only counting `created` events on the host subclass
 * proves the row was never actually created as it. The assertion must go red here.
 */
final class CreateRecordViaPackagedClass
{
    public static function run(string $name): Model
    {
        $record = Record::query()->create(['name' => $name]);

        /** @var class-string<Model> $class */
        $class = config('model_swap.record_model');

        return $class::query()->findOrFail($record->getKey());
    }
}
