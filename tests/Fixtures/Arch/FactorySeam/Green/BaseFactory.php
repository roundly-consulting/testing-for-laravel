<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\FactorySeam\Green;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A fixture factory. An abstract base builds nothing itself.
 */
abstract class BaseFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [];
    }
}
