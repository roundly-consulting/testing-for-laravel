<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\FactorySeam\Green;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\FactorySeam\Src\Support\Catalog;

/**
 * A fixture factory.
 */
class SprocketFactory extends Factory
{
    /** No `$model`: the seam it returns says which model it builds. */
    public function modelName(): string
    {
        return Catalog::sprocket();
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [];
    }
}
