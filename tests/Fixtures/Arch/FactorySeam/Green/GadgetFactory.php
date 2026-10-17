<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\FactorySeam\Green;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\FactorySeam\Src\Models\Gadget;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\FactorySeam\Src\Support\GadgetModel;

/**
 * A fixture factory.
 */
class GadgetFactory extends Factory
{
    protected $model = Gadget::class;

    public function modelName(): string
    {
        return GadgetModel::class();
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [];
    }
}
