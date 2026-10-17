<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\FactorySeam\Missing;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\FactorySeam\Src\Models\Gadget;

/**
 * A fixture factory.
 *
 * The fix only in prose does not count:
 *
 *     public function modelName(): string { return GadgetModel::class(); }
 */
class GadgetFactory extends Factory
{
    protected $model = Gadget::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [];
    }
}
