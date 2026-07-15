<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\ModelSwap;

use Illuminate\Database\Eloquent\Model;

/**
 * A correctly *seamed* action: it resolves the model class from config, so a host swap
 * is honoured and the row is created as the host subclass.
 */
final class CreateRecord
{
    public static function run(string $name): Model
    {
        /** @var class-string<Model> $class */
        $class = config('model_swap.record_model');

        return $class::query()->create(['name' => $name]);
    }
}
