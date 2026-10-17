<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\FactorySeam\NotTheSeam;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\FactorySeam\Src\Models\Sprocket;

/**
 * A fixture factory.
 */
class SprocketFactory extends Factory
{
    protected $model = Sprocket::class;

    public function modelName(): string
    {
        return Sprocket::class;
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [];
    }
}
