<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\FactorySeam\NotTheSeam;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\FactorySeam\Src\Models\Gadget;

/**
 * A fixture factory.
 */
class GadgetFactory extends Factory
{
    protected $model = Gadget::class;

    public function modelName(): string
    {
        return $this->model;
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [];
    }
}
