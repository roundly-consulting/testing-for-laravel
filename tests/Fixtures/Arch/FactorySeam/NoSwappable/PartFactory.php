<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\FactorySeam\NoSwappable;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\FactorySeam\Src\Models\Part;

/**
 * A fixture factory.
 */
class PartFactory extends Factory
{
    protected $model = Part::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [];
    }
}
